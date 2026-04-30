<?php

declare(strict_types=1);

namespace Packages\Sandbox\Testing;

use Illuminate\Database\Eloquent\Model;
use Packages\Sandbox\Facades\Sandbox;
use Packages\Sandbox\Models\SandboxStatus;

/**
 * Трейт для удобства тестирования sandbox функциональности.
 *
 * Использование в тестах:
 * ```php
 * class ConfigControllerTest extends TestCase
 * {
 *     use SandboxTestHelpers;
 *
 *     public function testCanEditConfig(): void
 *     {
 *         // С явным userId
 *         $this->openSandbox(userId: 1);
 *         // ... or без userId (использует Auth::user())
 *         $this->openSandbox();
 *
 *         // ... make changes
 *         $this->assertSandboxLocked(userId: 1);
 *         $this->commitSandbox(userId: 1);
 *         $this->assertSandboxFree();
 *     }
 * }
 * ```
 */
trait SandboxTestHelpers
{
    /**
     * Открыть sandbox для пользователя (или текущего если не указан).
     */
    protected function openSandbox(int|string|null $userId = null, bool $force = false, ?string $note = null): void
    {
        $userId = $userId ?? auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        Sandbox::for($userId)->open($force, $note);
    }

    /**
     * Закрыть sandbox с коммитом (применить изменения).
     */
    protected function commitSandbox(int|string|null $userId = null, ?string $note = null, bool $async = true): void
    {
        $userId = $userId ?? auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        Sandbox::for($userId)->commit($note, $async);
    }

    /**
     * Закрыть sandbox с откатом (отменить изменения).
     */
    protected function rollbackSandbox(int|string|null $userId = null, ?string $note = null): void
    {
        $userId = $userId ?? auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        Sandbox::for($userId)->rollback($note);
    }

    /**
     * Закрыть sandbox без коммита (сохранить).
     */
    protected function saveSandbox(int|string|null $userId = null, ?string $note = null): void
    {
        $userId = $userId ?? auth()->user()?->getAuthIdentifier();
        if (! $userId) {
            throw new \RuntimeException('No user ID provided and no authenticated user found');
        }

        Sandbox::for($userId)->save($note);
    }

    /**
     * Проверить что sandbox свободен.
     */
    protected function assertSandboxFree(): void
    {
        $status = SandboxStatus::first();
        $this->assertNotNull($status, 'SandboxStatus not found');
        $this->assertTrue($status->isFree(), 'Sandbox is not free');
    }

    /**
     * Проверить что sandbox заблокирован конкретным пользователем.
     */
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

    /**
     * Проверить что sandbox сохранен.
     */
    protected function assertSandboxSaved(): void
    {
        $status = SandboxStatus::first();
        $this->assertNotNull($status, 'SandboxStatus not found');
        $this->assertTrue($status->isSaved(), 'Sandbox is not saved');
    }

    /**
     * Получить текущий статус sandbox.
     */
    protected function getSandboxStatus(): ?SandboxStatus
    {
        return SandboxStatus::first();
    }

    /**
     * Переключить модель на использование sandbox-таблицы.
     *
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
     * Переключить модель на использование активной таблицы.
     *
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
     * Синхронизировать данные в sandbox (скопировать из активной таблицы).
     *
     * @param class-string<Model>|Model $modelOrClass
     */
    protected function applySandbox(string|Model $modelOrClass): void
    {
        Sandbox::resetSandboxData($modelOrClass);
    }
}
