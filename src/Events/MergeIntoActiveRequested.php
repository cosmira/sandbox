<?php

declare(strict_types=1);

namespace Packages\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Запрос синхронизации sandbox в активную область.
 *
 * Слушатель должен выполнить копирование данных из sandbox-таблиц в активные.
 * Диспатчится при коммите перед обновлением статуса.
 */
class MergeIntoActiveRequested
{
    use Dispatchable;
    use SerializesModels;
}
