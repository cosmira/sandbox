<?php

declare(strict_types=1);

namespace Packages\Sandbox\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Фасад для доступа к Sandbox (открытие/закрытие сессии, синхронизация данных).
 *
 * @method static void                                        open(int|string $userId, bool $force = false, ?string $note = null)
 * @method static void                                        close(int|string $userId, int $result, ?string $note = null, bool $asyncUpdater = true)
 * @method static \Packages\Sandbox\Models\SandboxStatus|null status()
 * @method static void                                        resetSandboxData(string|\Illuminate\Database\Eloquent\Model $modelOrClass)
 * @method static \Packages\Sandbox\SandboxBuilder            for(int|string $userId)
 * @method static \Packages\Sandbox\SandboxBuilder            me()
 *
 * @see \Packages\Sandbox\Sandbox
 */
class Sandbox extends Facade
{
    /**
     * Получить аксессор фасада.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return \Packages\Sandbox\Sandbox::class;
    }
}
