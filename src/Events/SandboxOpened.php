<?php

declare(strict_types=1);

namespace Packages\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sandbox успешно открыт для редактирования.
 *
 * Диспатчится после обновления статуса sandbox на Locked.
 */
class SandboxOpened
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Инициализировать событие.
     *
     * @param int|string  $userId ID или UUID пользователя
     * @param bool        $force  Sandbox был открыт принудительно
     * @param string|null $note   Примечание операции открытия
     */
    public function __construct(
        public readonly int|string $userId,
        public readonly bool $force,
        public readonly ?string $note,
    ) {}
}
