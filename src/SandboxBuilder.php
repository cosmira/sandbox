<?php

declare(strict_types=1);

namespace Packages\Sandbox;

use Illuminate\Database\Eloquent\Model;

/**
 * Fluent API for a user-scoped sandbox session.
 */
class SandboxBuilder
{
    private int|string $userId;

    private Sandbox $sandbox;

    public function __construct(int|string $userId)
    {
        $this->userId = $userId;
        $this->sandbox = app(Sandbox::class);
    }

    public function open(bool $force = false, ?string $note = null): self
    {
        $this->sandbox->open($this->userId, $force, $note);

        return $this;
    }

    public function rollback(?string $note = null): void
    {
        $this->sandbox->close($this->userId, 0, $note);
    }

    public function commit(?string $note = null, bool $asyncUpdater = true): void
    {
        $this->sandbox->close($this->userId, 1, $note, $asyncUpdater);
    }

    public function save(?string $note = null): void
    {
        $this->sandbox->close($this->userId, 2, $note);
    }

    /**
     * @param class-string<Model>|Model $modelOrClass
     */
    public function apply(string|Model $modelOrClass): self
    {
        $this->sandbox->resetSandboxData($modelOrClass);

        return $this;
    }

    /**
     * @param class-string<Model>|Model $modelOrClass
     */
    public function reset(string|Model $modelOrClass): self
    {
        $this->sandbox->resetSandboxData($modelOrClass);

        return $this;
    }

    public function status(): ?Models\SandboxStatus
    {
        return $this->sandbox->status();
    }

    public function getUserId(): int|string
    {
        return $this->userId;
    }

    public function getSandbox(): Sandbox
    {
        return $this->sandbox;
    }
}
