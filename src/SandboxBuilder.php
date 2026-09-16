<?php

declare(strict_types=1);

namespace Cosmira\Sandbox;

use Cosmira\Sandbox\Models\SandboxStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Fluent API for a user-scoped sandbox session.
 */
class SandboxBuilder
{
    /**
     * Bind a user to the supplied service or the application default.
     *
     * @param int|string $userId User identifier bound to all fluent lifecycle operations.
     */
    public function __construct(
        private readonly int|string $userId,
        ?Sandbox $sandbox = null,
    ) {
        $this->sandbox = $sandbox ?? app(Sandbox::class);
    }

    /**
     * Service instance retained by every operation in this fluent builder.
     */
    private readonly Sandbox $sandbox;

    /**
     * Open the sandbox for the builder user.
     */
    public function open(bool $force = false, ?string $note = null): self
    {
        $this->sandbox->open($this->userId, $force, $note);

        return $this;
    }

    /**
     * Roll back the sandbox for the builder user.
     */
    public function rollback(?string $note = null): void
    {
        $this->sandbox->rollback($this->userId, $note);
    }

    /**
     * Commit the sandbox for the builder user.
     */
    public function commit(?string $note = null): void
    {
        $this->sandbox->commit($this->userId, $note);
    }

    /**
     * Save the sandbox for the builder user without committing.
     */
    public function save(?string $note = null): void
    {
        $this->sandbox->save($this->userId, $note);
    }

    /**
     * Apply active data to the sandbox table for the given model.
     *
     * @param class-string<Model>|Model $modelOrClass
     */
    public function apply(string|Model $modelOrClass): self
    {
        $this->sandbox->reset($this->userId, $modelOrClass);

        return $this;
    }

    /**
     * Reset sandbox data for the given model.
     *
     * @param class-string<Model>|Model $modelOrClass
     */
    public function reset(string|Model $modelOrClass): self
    {
        $this->sandbox->reset($this->userId, $modelOrClass);

        return $this;
    }

    /**
     * Get the current sandbox status row.
     */
    public function status(): ?SandboxStatus
    {
        return $this->sandbox->status();
    }

    /**
     * Get the user ID bound to the builder.
     */
    public function getUserId(): int|string
    {
        return $this->userId;
    }

    /**
     * Get the sandbox service instance.
     */
    public function getSandbox(): Sandbox
    {
        return $this->sandbox;
    }
}
