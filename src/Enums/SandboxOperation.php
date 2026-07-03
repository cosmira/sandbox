<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Enums;

/**
 * The operation used to close a sandbox session.
 */
enum SandboxOperation: int
{
    /**
     * Discard sandbox changes and reset from active data.
     */
    case Rollback = 0;

    /**
     * Apply sandbox changes to active data.
     */
    case Commit = 1;

    /**
     * Keep sandbox changes without applying them.
     */
    case Save = 2;

    /**
     * Resolve a console or legacy input value into an operation.
     */
    public static function tryFromInput(int|string $value): ?self
    {
        $normalized = is_int($value) ? $value : trim($value);

        if (is_int($normalized) || ctype_digit($normalized)) {
            return self::tryFrom((int) $normalized);
        }

        return match (strtolower($normalized)) {
            'rollback' => self::Rollback,
            'commit'   => self::Commit,
            'save'     => self::Save,
            default    => null,
        };
    }

    /**
     * Get the CLI label for the operation.
     */
    public function label(): string
    {
        return match ($this) {
            self::Rollback => 'rollback',
            self::Commit   => 'commit',
            self::Save     => 'save',
        };
    }

    /**
     * Get the human-readable description for the operation.
     */
    public function description(): string
    {
        return match ($this) {
            self::Rollback => 'Rollback',
            self::Commit   => 'Commit',
            self::Save     => 'Save without commit',
        };
    }
}
