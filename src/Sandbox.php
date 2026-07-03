<?php

declare(strict_types=1);

namespace Cosmira\Sandbox;

use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Cosmira\Sandbox\Events\SandboxApplying;
use Cosmira\Sandbox\Events\SandboxClosed;
use Cosmira\Sandbox\Events\SandboxOpened;
use Cosmira\Sandbox\Events\SandboxResetting;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Support\SandboxRecordRestorer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Traits\Dumpable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Support\Traits\Tappable;

/**
 * Manages the sandbox session and dispatches domain events.
 */
class Sandbox
{
    use Dumpable;
    use Macroable;
    use Tappable;

    /**
     * Create a sandbox lifecycle manager.
     */
    public function __construct(
        private readonly SandboxRecordRestorer $recordRestorer = new SandboxRecordRestorer(),
    ) {}

    /**
     * Open the sandbox for the given user.
     *
     * @param int|string|Model $user
     *
     * @throws SandboxException
     */
    public function open(int|string|Model $user, bool $force = false, ?string $note = null): void
    {
        Log::debug('Opening sandbox', ['user_id' => $user]);

        $userId = $user instanceof Model ? $user->getKey() : $user;

        DB::transaction(function () use ($userId, $force, $note): void {
            $status = $this->lockedStatus();

            if (! $force && $status->isLocked() && (string) $status->user_id !== (string) $userId) {
                throw new SandboxException(
                    'Sandbox is locked by other user '.$status->user_id,
                    SandboxException::CODE_SANDBOX_LOCKED,
                );
            }

            if (
                $status->isFree()
                || (
                    $force
                    && $status->isLocked()
                    && (string) $status->user_id !== (string) $userId
                )
            ) {
                Event::dispatch(new SandboxResetting());
            }

            $status->update([
                'status'      => SandboxStatusEnum::Locked,
                'user_id'     => $userId,
                'note'        => $note,
                'change_date' => now(),
            ]);

            Event::dispatch(new SandboxOpened($userId, $force, $note));

            Log::info('Sandbox opened', ['user_id' => $userId]);
        });
    }

    /**
     * Close the sandbox with the given operation.
     *
     * @throws SandboxException
     */
    public function close(
        int|string $userId,
        SandboxOperation $result,
        ?string $note = null,
        bool $asyncUpdater = true,
    ): void {
        Log::debug('Closing sandbox', [
            'user_id' => $userId,
            'result'  => $result->label(),
        ]);

        DB::transaction(function () use ($userId, $result, $note, $asyncUpdater): void {
            $status = $this->lockedStatus();

            throw_if(
                $status->isFree(),
                SandboxException::class,
                'Cannot close: sandbox is already free. Use open() first.',
                SandboxException::CODE_SANDBOX_FREE
            );

            if (
                $status->isLocked()
                && (string) $status->user_id !== (string) $userId
                && $result !== SandboxOperation::Rollback
            ) {
                throw new SandboxException(
                    'Sandbox is locked by other user '.$status->user_id,
                    SandboxException::CODE_SANDBOX_LOCKED,
                );
            }

            match ($result) {
                SandboxOperation::Rollback => $this->handleRollback($status, $userId, $note),
                SandboxOperation::Commit   => $this->handleCommit(
                    $status,
                    $userId,
                    $note,
                    $asyncUpdater,
                ),
                SandboxOperation::Save     => $this->handleSave($status, $userId, $note),
            };

            Log::info('Sandbox closed', ['user_id' => $userId, 'result' => $result->label()]);
        });
    }

    /**
     * Roll back the sandbox and release the lock.
     */
    private function handleRollback(SandboxStatus $status, int|string $userId, ?string $note): void
    {
        $closedAt = now();

        Event::dispatch(new SandboxResetting());

        $this->updateStatusRow($status, [
            'status'         => SandboxStatusEnum::Free,
            'user_id'        => $userId,
            'last_operation' => SandboxOperation::Rollback,
            'note'           => $note,
            'change_date'    => $closedAt,
            'change_id'      => $status->change_id + 1,
        ]);

        Event::dispatch(new SandboxClosed(
            $userId,
            SandboxOperation::Rollback,
            $closedAt,
            $note,
            false,
        ));
    }

    /**
     * Commit the sandbox and release the lock.
     */
    private function handleCommit(
        SandboxStatus $status,
        int|string $userId,
        ?string $note,
        bool $asyncUpdater,
    ): void {
        $closedAt = now();

        Event::dispatch(new SandboxApplying());

        $this->updateStatusRow($status, [
            'status'         => SandboxStatusEnum::Free,
            'user_id'        => $userId,
            'last_operation' => SandboxOperation::Commit,
            'note'           => $note,
            'send_date'      => $closedAt,
            'change_date'    => $closedAt,
            'change_id'      => $status->change_id + 1,
        ]);

        Event::dispatch(new SandboxClosed(
            $userId,
            SandboxOperation::Commit,
            $closedAt,
            $note,
            $asyncUpdater,
        ));
    }

    /**
     * Save the sandbox without applying it to active data.
     */
    private function handleSave(SandboxStatus $status, int|string $userId, ?string $note): void
    {
        $closedAt = now();

        $this->updateStatusRow($status, [
            'status'         => SandboxStatusEnum::Saved,
            'user_id'        => $userId,
            'last_operation' => SandboxOperation::Save,
            'note'           => $note,
            'change_date'    => $closedAt,
            'change_id'      => $status->change_id + 1,
        ]);

        Event::dispatch(new SandboxClosed(
            $userId,
            SandboxOperation::Save,
            $closedAt,
            $note,
            false,
        ));
    }

    /**
     * Update the singleton sandbox status row.
     *
     * @param array<string, mixed> $attributes
     */
    private function updateStatusRow(SandboxStatus $status, array $attributes): void
    {
        $status->forceFill($attributes)->save();
    }

    /**
     * Get the locked singleton sandbox status row for a lifecycle mutation.
     */
    private function lockedStatus(): SandboxStatus
    {
        return SandboxStatus::query()->lockForUpdate()->firstOrFail();
    }

    /**
     * Get the current sandbox status row.
     */
    public function status(): ?SandboxStatus
    {
        return SandboxStatus::first();
    }

    /**
     * Reset sandbox data for the given model class or instance.
     *
     * @param class-string<Model>|Model $modelOrClass
     *
     * @throws SandboxException
     */
    public function resetSandboxData(string|Model $modelOrClass): void
    {
        $this->ensureModelCanSync($modelOrClass);

        $instance = $modelOrClass instanceof Model ? $modelOrClass : null;
        $modelClass = $instance instanceof Model ? $instance::class : $modelOrClass;

        if ($instance instanceof Model) {
            $this->resetSingleRecord($instance);
        } else {
            $this->resetBulk($modelClass);
        }
    }

    /**
     * Ensure the given model supports sandbox synchronization.
     *
     * @param class-string<Model>|Model $modelOrClass
     *
     * @throws SandboxException
     */
    private function ensureModelCanSync(string|Model $modelOrClass): void
    {
        $modelClass = $modelOrClass instanceof Model ? $modelOrClass::class : $modelOrClass;

        throw_unless(
            is_subclass_of($modelClass, Model::class),
            SandboxException::class,
            sprintf('Model %s must extend %s.', $modelClass, Model::class),
            SandboxException::CODE_MODEL_NOT_REGISTERED
        );

        throw_unless(
            method_exists($modelClass, 'syncIntoSandbox'),
            SandboxException::class,
            sprintf('Model %s has no syncIntoSandbox(). Use HasSandbox trait.', $modelClass),
            SandboxException::CODE_MODEL_NOT_REGISTERED
        );
    }

    /**
     * Reset one sandbox row from the matching active row.
     *
     * @throws SandboxException
     */
    private function resetSingleRecord(Model $model): void
    {
        $this->recordRestorer->restore($model);
    }

    /**
     * Reset all sandbox rows for the given model class.
     *
     * @param class-string<Model> $modelClass
     */
    private function resetBulk(string $modelClass): void
    {
        $modelClass::syncIntoSandbox();
    }
}
