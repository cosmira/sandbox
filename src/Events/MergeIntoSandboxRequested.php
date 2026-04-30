<?php

declare(strict_types=1);

namespace Packages\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Запрос синхронизации активной области в sandbox.
 *
 * Слушатель должен выполнить копирование данных из «активных» таблиц в sandbox.
 * Диспатчится при начале редактирования (если sandbox нужно обновить) и при откате.
 */
class MergeIntoSandboxRequested
{
    use Dispatchable;
    use SerializesModels;
}
