<?php

declare(strict_types=1);

namespace Packages\Sandbox\Exceptions;

use Exception;

/**
 * Исключение для ошибок песочницы.
 */
class SandboxException extends Exception
{
    /** Песочница заблокирована другим пользователем. */
    public const CODE_SANDBOX_LOCKED = 20605;

    /** Неверный результат редактирования. */
    public const CODE_SANDBOX_EDIT_RESULT = 20606;

    /** Попытка закрыть свободную песочницу. */
    public const CODE_SANDBOX_FREE = 20626;

    /** Модель не зарегистрирована для песочницы. */
    public const CODE_MODEL_NOT_REGISTERED = 20630;
}
