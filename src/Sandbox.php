<?php

declare(strict_types=1);

namespace Cosmira\Sandbox;

use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Cosmira\Sandbox\Events\SandboxCommitted;
use Cosmira\Sandbox\Events\SandboxCommitting;
use Cosmira\Sandbox\Events\SandboxOpened;
use Cosmira\Sandbox\Events\SandboxResetting;
use Cosmira\Sandbox\Events\SandboxRolledBack;
use Cosmira\Sandbox\Events\SandboxRollingBack;
use Cosmira\Sandbox\Events\SandboxSaved;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
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
        ?SandboxRecordRestorer $recordRestorer = null,
        ?SandboxModelRegistry $models = null,
    ) {
        $this->recordRestorer = $recordRestorer ?? new SandboxRecordRestorer();
        $this->models = $models ?? app(SandboxModelRegistry::class);
    }

    /**
     * Restores individual sandbox records from active data.
     */
    private readonly SandboxRecordRestorer $recordRestorer;

    /**
     * Stores the models that participate in the sandbox workflow.
     */
    private readonly SandboxModelRegistry $models;

    /**
     * Register models that belong to the sandbox workflow.
     *
     * @param class-string<Model> ...$models
     */
    public function models(string ...$models): void
    {
        $this->models->register(...$models);
    }

    /**
     * Open the sandbox for the given user.
     *
     *
     * @throws SandboxException
     */
    public function open(int|string|Model $user, bool $force = false, ?string $note = null): void
    {
        Log::debug('Opening sandbox', ['user_id' => $user]);

        $userId = $this->userId($user);

        DB::transaction(function () use ($userId, $force, $note): void {
            $status = $this->lockedStatus();

            if (! $force && ! $status->isFree() && ! $status->isForUser($userId)) {
                throw new SandboxException(
                    'Sandbox is locked by other user '.$status->user_id,
                    SandboxException::CODE_SANDBOX_LOCKED,
                );
            }

            if ($status->isFree()) {
                Event::dispatch(new SandboxResetting());
                $this->models->resetSandbox();
            } elseif ($force && ! $status->isForUser($userId)) {
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
     * Commit the sandbox draft to active data and release the lock.
     *
     * @throws SandboxException
     */
    public function commit(
        int|string|Model $user,
        ?string $note = null,
        bool $asyncUpdater = true,
    ): void {
        $this->close(
            $this->userId($user),
            SandboxOperation::Commit,
            $note,
            $asyncUpdater,
        );
    }

    /**
     * Roll the sandbox draft back from active data and release the lock.
     *
     * @throws SandboxException
     */
    public function rollback(int|string|Model $user, ?string $note = null): void
    {
        $this->close($this->userId($user), SandboxOperation::Rollback, $note);
    }

    /**
     * Save the sandbox draft without applying it to active data.
     *
     * @throws SandboxException
     */
    public function save(int|string|Model $user, ?string $note = null): void
    {
        $this->close($this->userId($user), SandboxOperation::Save, $note);
    }

    /**
     * Close the sandbox with the given operation.
     *
     * @throws SandboxException
     */
    private function close(
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
                && ! $status->isLockedBy($userId)
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

        Event::dispatch(new SandboxRollingBack());
        Event::dispatch(new SandboxResetting());
        $this->models->resetSandbox();

        $this->updateStatusRow($status, [
            'status'         => SandboxStatusEnum::Free,
            'user_id'        => $userId,
            'last_operation' => SandboxOperation::Rollback,
            'note'           => $note,
            'change_date'    => $closedAt,
            'change_id'      => $status->change_id + 1,
        ]);

        Event::dispatch(new SandboxRolledBack(
            $userId,
            $closedAt,
            $note,
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

        Event::dispatch(new SandboxCommitting());
        $this->models->applySandbox();

        $this->updateStatusRow($status, [
            'status'         => SandboxStatusEnum::Free,
            'user_id'        => $userId,
            'last_operation' => SandboxOperation::Commit,
            'note'           => $note,
            'send_date'      => $closedAt,
            'change_date'    => $closedAt,
            'change_id'      => $status->change_id + 1,
        ]);

        Event::dispatch(new SandboxCommitted(
            $userId,
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

        Event::dispatch(new SandboxSaved(
            $userId,
            $closedAt,
            $note,
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
            method_exists($modelClass, 'resetSandbox'),
            SandboxException::class,
            sprintf('Model %s has no resetSandbox(). Use HasSandbox trait.', $modelClass),
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
        $modelClass::resetSandbox();
    }

    /**
     * Get the scalar identifier for a user value.
     */
    private function userId(int|string|Model $user): int|string
    {
        if (! $user instanceof Model) {
            return $user;
        }

        $key = $user->getKey();

        throw_if(
            $key === null,
            SandboxException::class,
            sprintf('Model %s has no key.', $user::class),
            SandboxException::CODE_MODEL_NOT_REGISTERED,
        );

        return $key;
    }
}
