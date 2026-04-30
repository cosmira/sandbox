<?php

declare(strict_types=1);

namespace Packages\Sandbox\Contracts;

/**
 * Синхронизация данных в sandbox при вызове Sandbox::syncToActive().
 *
 * Реализацию регистрируют в приложении; по умолчанию пакет даёт no-op.
 *
 * @see Sandbox::syncToActive()
 */
interface SandboxSyncRunnerInterface
{
    /**
     * Синхронизировать данные в sandbox (merge по ключу).
     *
     * @param array<string, mixed> $data Карта: ключ (например 'categories') => данные
     */
    public function syncToSandbox(array $data): void;
}
