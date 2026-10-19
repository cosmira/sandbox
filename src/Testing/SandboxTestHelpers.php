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
     * Failure when a helper cannot resolve a user for the sandbox session.
     */
    private const MISSING_USER = 'No user ID provided and no authenticated user found';

    /**
     * Failure when the sandbox status fixture has not been created.
     */
    private const MISSING_STATUS = 'SandboxStatus not found';

    /**
     * Open the sandbox for the given or authenticated user.
     */
    protected function openSandbox(
        int|string|null $userId = null,
        bool $force = false,
        ?string $note = null,
    ): void {
        $resolvedUserId = $userId ?? auth()->guard()->user()?->getAuthIdentifier();
        if (! $resolvedUserId) {
            throw new \RuntimeException(self::MISSING_USER);
        }

        Sandbox::for($resolvedUserId)->open($force, $note);
    }

    /**
     * Commit the sandbox for the given or authenticated user.
     */
    protected function commitSandbox(
        int|string|null $userId = null,
        ?string $note = null,
    ): void {
        $resolvedUserId = $userId ?? auth()->guard()->user()?->getAuthIdentifier();
        if (! $resolvedUserId) {
            throw new \RuntimeException(self::MISSING_USER);
        }

        Sandbox::for($resolvedUserId)->commit($note);
    }

    /**
     * Roll back the sandbox for the given or authenticated user.
     */
    protected function rollbackSandbox(int|string|null $userId = null, ?string $note = null): void
    {
        $resolvedUserId = $userId ?? auth()->guard()->user()?->getAuthIdentifier();
        if (! $resolvedUserId) {
            throw new \RuntimeException(self::MISSING_USER);
        }

        Sandbox::for($resolvedUserId)->rollback($note);
    }

    /**
     * Save the sandbox for the given or authenticated user.
     */
    protected function saveSandbox(int|string|null $userId = null, ?string $note = null): void
    {
        $resolvedUserId = $userId ?? auth()->guard()->user()?->getAuthIdentifier();
        if (! $resolvedUserId) {
            throw new \RuntimeException(self::MISSING_USER);
        }

        Sandbox::for($resolvedUserId)->save($note);
    }

    /**
     * Assert that the sandbox is free.
     */
    protected function assertSandboxFree(): void
    {
        $status = SandboxStatus::query()->first();
        $this->assertNotNull($status, self::MISSING_STATUS);
        $this->assertTrue($status->isFree(), 'Sandbox is not free');
    }

    /**
     * Assert that the sandbox is locked by the given or authenticated user.
     */
    protected function assertSandboxLocked(int|string|null $userId = null): void
    {
        $resolvedUserId = $userId ?? auth()->guard()->user()?->getAuthIdentifier();
        if (! $resolvedUserId) {
            throw new \RuntimeException(self::MISSING_USER);
        }

        $status = SandboxStatus::query()->first();
        $this->assertNotNull($status, self::MISSING_STATUS);
        $this->assertTrue($status->isLocked(), 'Sandbox is not locked');
        $this->assertEquals(
            (string) $resolvedUserId,
            (string) $status->user_id,
            'Sandbox is not owned by '.$resolvedUserId,
        );
    }

    /**
     * Assert that the sandbox is saved.
     */
    protected function assertSandboxSaved(): void
    {
        $status = SandboxStatus::query()->first();
        $this->assertNotNull($status, self::MISSING_STATUS);
        $this->assertTrue($status->isSaved(), 'Sandbox is not saved');
    }

    /**
     * Get the current sandbox status row.
     */
    protected function getSandboxStatus(): ?SandboxStatus
    {
        return SandboxStatus::query()->first();
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
    protected function applySandbox(
        string|Model $modelOrClass,
        int|string|null $userId = null,
    ): void {
        $resolvedUserId = $userId ?? auth()->guard()->user()?->getAuthIdentifier();
        if ($resolvedUserId === null) {
            throw new \RuntimeException(self::MISSING_USER);
        }

        Sandbox::reset($resolvedUserId, $modelOrClass);
    }
}
