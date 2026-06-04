<?php

declare(strict_types=1);

namespace Packages\Sandbox\Facades;

use Illuminate\Support\Facades\Facade;

class Sandbox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Packages\Sandbox\Sandbox::class;
    }
}
