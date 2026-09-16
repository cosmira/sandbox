<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Eloquent;

use Illuminate\Database\ConnectionInterface as Connection;
use Illuminate\Support\Facades\Facade;

/**
 * Bridge static Eloquent model helpers to the scoped selection service.
 *
 * @method static void    select(string $model, bool $draft)
 * @method static bool    isUsingSandbox(string $model)
 * @method static ?string resolveTable(string $table, Connection $connection, bool $draft)
 */
final class Context extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TableContext::class;
    }
}
