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

    // Set to null for an existing keyless table containing exactly one provisioned row.
    'status_primary_key' => 'id',

    /*
    |--------------------------------------------------------------------------
    | Schema prefix (optional)
    |--------------------------------------------------------------------------
    | For use in your event listeners if you need a table prefix.
    */
    'schema_prefix' => env('SANDBOX_SCHEMA_PREFIX', ''),

];
