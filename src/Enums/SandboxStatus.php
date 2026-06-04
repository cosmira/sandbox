<?php

declare(strict_types=1);

namespace Packages\Sandbox\Enums;

enum SandboxStatus: int
{
    case Free = 0;

    case Locked = 1;

    case Saved = 2;

    public function label(): string
    {
        return match ($this) {
            self::Free   => 'Free',
            self::Locked => 'Locked',
            self::Saved  => 'Saved',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Free   => 'Sandbox is free (not in use)',
            self::Locked => 'Sandbox is locked (user is editing)',
            self::Saved  => 'Sandbox is saved (not locked, data persisted)',
        };
    }
}
