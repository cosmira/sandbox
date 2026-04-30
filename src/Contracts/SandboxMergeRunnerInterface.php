<?php

declare(strict_types=1);

namespace Packages\Sandbox\Contracts;

/**
 * Интерфейс для приложения: выполнение слияния active → sandbox (merge into sandbox)
 * и sandbox → active (merge into active).
 *
 * Реализация в приложении (например SandboxMerger) вызывает syncIntoSandbox/syncIntoActive
 * моделей в нужном порядке.
 */
interface SandboxMergeRunnerInterface
{
    /**
     * Синхронизировать данные из активных таблиц в sandbox-таблицы.
     */
    public function mergeIntoSandbox(): void;

    /**
     * Синхронизировать данные из sandbox-таблиц в активные.
     */
    public function mergeIntoActive(): void;
}
