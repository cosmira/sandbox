<?php

declare(strict_types=1);

namespace Packages\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sandbox успешно закоммичен: данные перенесены в active, статус обновлён.
 *
 * Слушатель может запустить обновление query-таблиц, подготовку файлов и т.п.
 * При необходимости сам проверит, изменились ли системные списки.
 */
class SandboxCommitted
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Инициализировать событие.
     *
     * @param int|string         $userId       ID или UUID пользователя
     * @param \DateTimeInterface $sendDate     Время коммита
     * @param bool               $asyncUpdater Использовать асинхронное обновление
     */
    public function __construct(
        public readonly int|string $userId,
        public readonly \DateTimeInterface $sendDate,
        public readonly bool $asyncUpdater,
    ) {}
}
