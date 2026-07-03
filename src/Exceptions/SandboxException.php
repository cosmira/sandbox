<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Exceptions;

use Exception;

/**
 * Sandbox domain exception.
 */
class SandboxException extends Exception
{
    /**
     * The sandbox is locked by another user.
     */
    public const CODE_SANDBOX_LOCKED = 20605;

    /**
     * The sandbox is already free.
     */
    public const CODE_SANDBOX_FREE = 20626;

    /**
     * The model does not support sandbox synchronization.
     */
    public const CODE_MODEL_NOT_REGISTERED = 20630;

    /**
     * The configured sandbox synchronization column is missing.
     */
    public const CODE_SYNC_COLUMN_MISSING = 20631;
}
