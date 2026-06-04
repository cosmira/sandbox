<?php

declare(strict_types=1);

namespace Packages\Sandbox\Testing;

use Illuminate\Database\Eloquent\Model;
use Packages\Sandbox\Facades\Sandbox;
use Packages\Sandbox\Models\SandboxStatus;

/**
 * Test helpers for sandbox sessions.
 */
trait SandboxTestHelpers
{
    protected function openSandbox(int|string|null $userId = null, bool $force = false, ?string $note = null): void
    {
        $userId = $userId ?? auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        Sandbox::for($userId)->open($force, $note);
    }

    protected function commitSandbox(int|string|null $userId = null, ?string $note = null, bool $async = true): void
    {
        $userId = $userId ?? auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        Sandbox::for($userId)->commit($note, $async);
    }

    protected function rollbackSandbox(int|string|null $userId = null, ?string $note = null): void
    {
        $userId = $userId ?? auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        Sandbox::for($userId)->rollback($note);
    }

    protected function saveSandbox(int|string|null $userId = null, ?string $note = null): void
    {
        $userId = $userId ?? auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        Sandbox::for($userId)->save($note);
    }

    protected function assertSandboxFree(): void
    {
        $status = SandboxStatus::first();
        $this->assertNotNull($status, 'SandboxStatus not found');
        $this->assertTrue($status->isFree(), 'Sandbox is not free');
    }

    protected function assertSandboxLocked(int|string|null $userId = null): void
    {
        $userId = $userId ?? auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        $status = SandboxStatus::first();
        $this->assertNotNull($status, 'SandboxStatus not found');
        $this->assertTrue($status->isLocked(), 'Sandbox is not locked');
        $this->assertEquals((string) $userId, (string) $status->user_id, 'Sandbox is not owned by '.$userId);
    }

    protected function assertSandboxSaved(): void
    {
        $status = SandboxStatus::first();
        $this->assertNotNull($status, 'SandboxStatus not found');
        $this->assertTrue($status->isSaved(), 'Sandbox is not saved');
    }

    protected function getSandboxStatus(): ?SandboxStatus
    {
        return SandboxStatus::first();
    }

    /**
     * @param class-string<Model>|Model $modelOrClass
     */
    protected function useSandbox(string|Model $modelOrClass): void
    {
        $modelClass = $modelOrClass instanceof Model ? $modelOrClass::class : $modelOrClass;
        if (method_exists($modelClass, 'useSandboxTable')) {
            $modelClass::useSandboxTable();
        }
    }

    /**
     * @param class-string<Model>|Model $modelOrClass
     */
    protected function useActive(string|Model $modelOrClass): void
    {
        $modelClass = $modelOrClass instanceof Model ? $modelOrClass::class : $modelOrClass;
        if (method_exists($modelClass, 'useActiveTable')) {
            $modelClass::useActiveTable();
        }
    }

    /**
     * @param class-string<Model>|Model $modelOrClass
     */
    protected function applySandbox(string|Model $modelOrClass): void
    {
        Sandbox::resetSandboxData($modelOrClass);
    }
}
