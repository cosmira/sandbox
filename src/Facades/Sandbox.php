<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for the sandbox service.
 */
class Sandbox extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \Cosmira\Sandbox\Sandbox::class;
    }
}
