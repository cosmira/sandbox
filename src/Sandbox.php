<?php

declare(strict_types=1);

namespace Cosmira\Sandbox;

use Cosmira\Sandbox\Contracts\SandboxBackend;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Models\SandboxStatus;
use Cosmira\Sandbox\Support\SandboxModelRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
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
        ?SandboxModelRegistry $models = null,
        ?SandboxBackend $backend = null,
    ) {
        $this->models = $models ?? app(SandboxModelRegistry::class);
        $this->backend = $backend ?? app(SandboxBackend::class);
    }

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

    /** Register tables without requiring application Pivot model classes. */
    public function tables(SandboxTable ...$tables): void
    {
        $this->models->registerTables($this->connection(), ...$tables);
    }

    private readonly SandboxBackend $backend;

    public function connection(): ConnectionInterface
    {
        return $this->backend->connection();
    }

    public function open(int|string|Model $user, bool $force = false, ?string $note = null): void
    {
        $this->backend->open($this->userId($user), $force, $note);
    }

    public function commit(int|string|Model $user, ?string $note = null): void
    {
        $this->backend->commit($this->userId($user), $note);
    }

    public function rollback(int|string|Model $user, ?string $note = null): void
    {
        $this->backend->rollback($this->userId($user), $note);
    }

    public function save(int|string|Model $user, ?string $note = null): void
    {
        $this->backend->save($this->userId($user), $note);
    }

    public function status(): ?SandboxStatus
    {
        return $this->backend->status();
    }

    public function edit(int|string|Model $user, callable $callback): mixed
    {
        return $this->connection()->transaction(function () use ($user, $callback): mixed {
            $this->open($user);

            return $this->models->usingTables(true, $callback, $this->connection());
        });
    }

    /**
     * @param callable(bool): mixed $callback Receives whether this read uses draft tables.
     */
    public function read(int|string|Model|null $user, callable $callback): mixed
    {
        $userId = $user === null ? null : $this->userId($user);
        $status = $this->status();
        $draft = $userId !== null && $status !== null
            && ($status->isSaved() || $status->isLockedBy($userId));

        return $this->models->usingTables($draft, fn (): mixed => $callback($draft));
    }

    /**
     * Reset sandbox data for the given model class or instance.
     *
     * @param class-string<Model>|Model $modelOrClass
     *
     * @throws SandboxException
     */
    public function reset(int|string|Model $user, string|Model $modelOrClass): void
    {
        $this->backend->reset($this->userId($user), $modelOrClass);
    }

    /**
     * Reset sandbox data using an explicitly identified owner.
     *
     * @param class-string<Model>|Model $modelOrClass
     *
     * @throws SandboxException
     */
    public function resetSandboxData(int|string|Model $user, string|Model $modelOrClass): void
    {
        $this->reset($user, $modelOrClass);
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
