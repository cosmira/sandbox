<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Testing;

use Cosmira\Sandbox\Facades\Sandbox;
use Cosmira\Sandbox\Models\SandboxStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Test helpers for sandbox sessions.
 */
trait SandboxTestHelpers
{
    /**
     * Open the sandbox for the given or authenticated user.
     */
    protected function openSandbox(
        int|string|null $userId = null,
        bool $force = false,
        ?string $note = null,
    ): void {
        $userId ??= auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        Sandbox::for($userId)->open($force, $note);
    }

    /**
     * Commit the sandbox for the given or authenticated user.
     */
    protected function commitSandbox(
        int|string|null $userId = null,
        ?string $note = null,
        bool $async = true,
    ): void {
        $userId ??= auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        Sandbox::for($userId)->commit($note, $async);
    }

    /**
     * Roll back the sandbox for the given or authenticated user.
     */
    protected function rollbackSandbox(int|string|null $userId = null, ?string $note = null): void
    {
        $userId ??= auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        Sandbox::for($userId)->rollback($note);
    }

    /**
     * Save the sandbox for the given or authenticated user.
     */
    protected function saveSandbox(int|string|null $userId = null, ?string $note = null): void
    {
        $userId ??= auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        Sandbox::for($userId)->save($note);
    }

    /**
     * Assert that the sandbox is free.
     */
    protected function assertSandboxFree(): void
    {
        $status = SandboxStatus::first();
        $this->assertNotNull($status, 'SandboxStatus not found');
        $this->assertTrue($status->isFree(), 'Sandbox is not free');
    }

    /**
     * Assert that the sandbox is locked by the given or authenticated user.
     */
    protected function assertSandboxLocked(int|string|null $userId = null): void
    {
        $userId ??= auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        $status = SandboxStatus::first();
        $this->assertNotNull($status, 'SandboxStatus not found');
        $this->assertTrue($status->isLocked(), 'Sandbox is not locked');
        $this->assertEquals(
            (string) $userId,
            (string) $status->user_id,
            'Sandbox is not owned by '.$userId,
        );
    }

    /**
     * Assert that the sandbox is saved.
     */
    protected function assertSandboxSaved(): void
    {
        $status = SandboxStatus::first();
        $this->assertNotNull($status, 'SandboxStatus not found');
        $this->assertTrue($status->isSaved(), 'Sandbox is not saved');
    }

    /**
     * Get the current sandbox status row.
     */
    protected function getSandboxStatus(): ?SandboxStatus
    {
        return SandboxStatus::first();
    }

    /**
     * Switch the given model to its sandbox table.
     *
     * @param class-string<Model>|Model $modelOrClass
     */
    protected function useSandbox(string|Model $modelOrClass): void
    {
        $modelClass = $modelOrClass instanceof Model ? $modelOrClass::class : $modelOrClass;
        if (method_exists($modelClass, 'useSandbox')) {
            $modelClass::useSandbox();
        }
    }

    /**
     * Switch the given model to its active table.
     *
     * @param class-string<Model>|Model $modelOrClass
     */
    protected function useActive(string|Model $modelOrClass): void
    {
        $modelClass = $modelOrClass instanceof Model ? $modelOrClass::class : $modelOrClass;
        if (method_exists($modelClass, 'useActive')) {
            $modelClass::useActive();
        }
    }

    /**
     * Apply active data to the sandbox table for the given model.
     *
     * @param class-string<Model>|Model $modelOrClass
     */
    protected function applySandbox(string|Model $modelOrClass): void
    {
        Sandbox::resetSandboxData($modelOrClass);
    }
}
