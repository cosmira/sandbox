<?php

declare(strict_types=1);

namespace Packages\Sandbox\Exceptions;

use Exception;

/**
 * Sandbox domain exception.
 */
class SandboxException extends Exception
{
    public const CODE_SANDBOX_LOCKED = 20605;

    public const CODE_SANDBOX_EDIT_RESULT = 20606;

    public const CODE_SANDBOX_FREE = 20626;

    public const CODE_MODEL_NOT_REGISTERED = 20630;
}
