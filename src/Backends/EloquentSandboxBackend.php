<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Backends;

use Cosmira\Sandbox\Contracts\SandboxBackend;
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
use Cosmira\Sandbox\Support\SandboxStatusLocker;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Manages the sandbox session and dispatches domain events.
 */
class EloquentSandboxBackend implements SandboxBackend
{
    public function __construct(
        private readonly SandboxModelRegistry $models,
        private readonly SandboxRecordRestorer $recordRestorer = new SandboxRecordRestorer(),
        private readonly SandboxStatus $statusModel = new SandboxStatus(),
    ) {}

    public function connection(): ConnectionInterface
    {
        return $this->statusModel->getConnection();
    }

    /**
     * Open the sandbox for the given user.
     *
     *
     * @throws SandboxException
     */
    public function open(int|string $userId, bool $force = false, ?string $note = null): void
    {
        $this->ensureRegisteredConnections();
        Log::debug('Opening sandbox', ['user_id' => $userId]);

        $this->connection()->transaction(function () use ($userId, $force, $note): void {
            $status = $this->lockedStatus();

            if (! $force && $status->isLocked() && ! $status->isForUser($userId)) {
                throw new SandboxException(
                    'Sandbox is locked by other user '.$status->user_id,
                    SandboxException::CODE_SANDBOX_LOCKED,
                );
            }

            if ($status->isLockedBy($userId)) {
                return;
            }

            if ($status->isFree()) {
                Event::dispatch(new SandboxResetting());
                $this->models->resetSandbox();
            } elseif ($force && ! $status->isForUser($userId)) {
                Event::dispatch(new SandboxResetting());
            }

            $this->updateStatusRow($status, [
                'last_operation' => null,
                'status'         => SandboxStatusEnum::Locked,
                'user_id'        => $userId,
                'note'           => $note,
                'change_date'    => now(),
            ]);

            $this->connection()->afterCommit(fn () => Event::dispatch(new SandboxOpened($userId, $force, $note)));

            Log::info('Sandbox opened', ['user_id' => $userId]);
        });
    }

    /**
     * Commit the sandbox draft to active data and release the lock.
     *
     * @throws SandboxException
     */
    public function commit(
        int|string $userId,
        ?string $note = null,
    ): void {
        $this->close(
            $userId,
            SandboxOperation::Commit,
            $note,
        );
    }

    /**
     * Roll the sandbox draft back from active data and release the lock.
     *
     * @throws SandboxException
     */
    public function rollback(int|string $userId, ?string $note = null): void
    {
        $this->close($userId, SandboxOperation::Rollback, $note);
    }

    /**
     * Save the sandbox draft without applying it to active data.
     *
     * @throws SandboxException
     */
    public function save(int|string $userId, ?string $note = null): void
    {
        $this->close($userId, SandboxOperation::Save, $note);
    }

    /**
     * Restore active data into the current owner's draft.
     *
     * @param class-string<Model>|Model $modelOrClass
     */
    public function reset(int|string $userId, string|Model $modelOrClass): void
    {
        $this->connection()->transaction(function () use ($userId, $modelOrClass): void {
            $this->ensureLockedBy($this->lockedStatus(), $userId);
            $modelClass = $modelOrClass instanceof Model ? $modelOrClass::class : $modelOrClass;

            throw_unless(
                is_subclass_of($modelClass, Model::class) && method_exists($modelClass, 'resetSandbox'),
                SandboxException::class,
                sprintf('Model %s must extend %s and support resetSandbox().', $modelClass, Model::class),
                SandboxException::CODE_MODEL_NOT_REGISTERED,
            );

            $this->ensureModelConnection($modelOrClass instanceof Model ? $modelOrClass : new $modelClass());

            if ($modelOrClass instanceof Model) {
                $this->recordRestorer->restore($modelOrClass);
            } else {
                $modelClass::resetSandbox();
            }
        });
    }

    private function ensureLockedBy(SandboxStatus $status, int|string $userId): void
    {
        throw_unless(
            $status->isLocked(),
            SandboxException::class,
            'Sandbox must be open before mutation. Use open() first.',
            SandboxException::CODE_SANDBOX_FREE,
        );

        throw_unless(
            $status->isLockedBy($userId),
            SandboxException::class,
            'Sandbox is locked by other user '.$status->user_id,
            SandboxException::CODE_SANDBOX_LOCKED,
        );
    }

    private function ensureRegisteredConnections(): void
    {
        $this->models->ensureTableConnection($this->connection());
        foreach ($this->models->all() as $modelClass) {
            $this->ensureModelConnection(new $modelClass());
        }
    }

    private function ensureModelConnection(Model $model): void
    {
        throw_unless(
            $model->getConnection() === $this->connection(),
            SandboxException::class,
            sprintf('Model %s must use the sandbox status connection.', $model::class),
            SandboxException::CODE_MODEL_NOT_REGISTERED,
        );
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
    ): void {
        $this->ensureRegisteredConnections();
        Log::debug('Closing sandbox', [
            'user_id' => $userId,
            'result'  => $result->label(),
        ]);

        $this->connection()->transaction(function () use ($userId, $result, $note): void {
            $status = $this->lockedStatus();
            $this->ensureLockedBy($status, $userId);

            match ($result) {
                SandboxOperation::Rollback => $this->handleRollback($status, $userId, $note),
                SandboxOperation::Commit   => $this->handleCommit(
                    $status,
                    $userId,
                    $note,
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

        $this->connection()->afterCommit(fn () => Event::dispatch(new SandboxRolledBack(
            $userId,
            $closedAt,
            $note,
        )));
    }

    /**
     * Commit the sandbox and release the lock.
     */
    private function handleCommit(
        SandboxStatus $status,
        int|string $userId,
        ?string $note,
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

        $this->connection()->afterCommit(fn () => Event::dispatch(new SandboxCommitted(
            $userId,
            $closedAt,
            $note,
        )));
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

        $this->connection()->afterCommit(fn () => Event::dispatch(new SandboxSaved(
            $userId,
            $closedAt,
            $note,
        )));
    }

    /**
     * Update the singleton sandbox status row.
     *
     * @param array<string, mixed> $attributes
     */
    private function updateStatusRow(SandboxStatus $status, array $attributes): void
    {
        throw_unless(
            $status->forceFill($attributes)->save(),
            SandboxException::class,
            'Sandbox status update was rejected.',
        );
    }

    /**
     * Get the locked singleton sandbox status row for a lifecycle mutation.
     */
    private function lockedStatus(): SandboxStatus
    {
        return (new SandboxStatusLocker())->query($this->statusModel)->firstOrFail();
    }

    /**
     * Get the current sandbox status row.
     */
    public function status(): ?SandboxStatus
    {
        return $this->statusModel->newQuery()->first();
    }
}
