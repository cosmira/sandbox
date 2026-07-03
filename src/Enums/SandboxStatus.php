<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Enums;

/**
 * The lifecycle status of the sandbox.
 */
enum SandboxStatus: int
{
    /**
     * The sandbox is available for editing.
     */
    case Free = 0;

    /**
     * The sandbox is locked by a user.
     */
    case Locked = 1;

    /**
     * The sandbox has saved changes that were not committed.
     */
    case Saved = 2;

    /**
     * Get the short display label for the status.
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
     * Get the human-readable description for the status.
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
