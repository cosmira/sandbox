<?php

declare(strict_types=1);

namespace Packages\Sandbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sandbox-сессия закрыта с выбранным результатом.
 */
class SandboxClosed
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param int|string         $userId       ID или UUID пользователя
     * @param int                $result       0 — откат, 1 — коммит, 2 — сохранить без коммита
     * @param \DateTimeInterface $closedAt     Время закрытия сессии
     * @param string|null        $note         Примечание операции
     * @param bool               $asyncUpdater Использовать асинхронное обновление после коммита
     */
    public function __construct(
        public readonly int|string $userId,
        public readonly int $result,
        public readonly \DateTimeInterface $closedAt,
        public readonly ?string $note,
        public readonly bool $asyncUpdater,
    ) {}
}
