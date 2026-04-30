<?php

declare(strict_types=1);

namespace Packages\Sandbox\Enums;

/**
 * Статусы песочницы.
 *
 * @see \Packages\Sandbox\Models\SandboxStatus
 */
enum SandboxStatus: int
{
    /**
     * Песочница свободна (не используется).
     */
    case Free = 0;

    /**
     * Песочница заблокирована пользователем (открыта для редактирования).
     */
    case Locked = 1;

    /**
     * Песочница сохранена (редактирование завершено, но не закоммичено).
     */
    case Saved = 2;

    /**
     * Получить человеческое название статуса.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Free   => 'Free',
            self::Locked => 'Locked',
            self::Saved  => 'Saved',
        };
    }

    /**
     * Получить подробное описание статуса.
     *
     * @return string
     */
    public function description(): string
    {
        return match ($this) {
            self::Free   => 'Sandbox is free (not in use)',
            self::Locked => 'Sandbox is locked (user is editing)',
            self::Saved  => 'Sandbox is saved (not locked, data persisted)',
        };
    }
}
