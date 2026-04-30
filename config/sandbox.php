<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Sandbox status table
    |--------------------------------------------------------------------------
    | Full table name (with schema if needed, e.g. sandbox_status).
    */
    'table' => env('SANDBOX_TABLE', 'sandbox_status'),

    /*
    |--------------------------------------------------------------------------
    | Schema prefix (optional)
    |--------------------------------------------------------------------------
    | For use in your event listeners if you need a table prefix.
    */
    'schema_prefix' => env('SANDBOX_SCHEMA_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Auto-register UseSandboxMiddleware
    |--------------------------------------------------------------------------
    | If true, the middleware will be automatically registered for routes.
    | If false (default), you need to manually register it in your HTTP kernel.
    */
    'auto_middleware' => env('SANDBOX_AUTO_MIDDLEWARE', false),
];
