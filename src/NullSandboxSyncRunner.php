<?php

declare(strict_types=1);

namespace Packages\Sandbox;

use Packages\Sandbox\Contracts\SandboxSyncRunnerInterface;

/**
 * Реализация по умолчанию: не изменяет sandbox.
 *
 * В приложении при необходимости привяжите SandboxSyncRunnerInterface к своей реализации.
 */
class NullSandboxSyncRunner implements SandboxSyncRunnerInterface
{
    /**
     * Синхронизировать данные в sandbox (no-op реализация).
     *
     * @param array<string, mixed> $data
     *
     * @return void
     */
    public function syncToSandbox(array $data): void
    {
        // No-op
    }
}
