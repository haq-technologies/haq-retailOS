<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | HAQ Retail OS runs on PostgreSQL only (spec section 4). Sessions, jobs
    | and the cache live in PostgreSQL too: there is no Redis. Connections
    | use UTC, because every time is stored in UTC.
    |
    */

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'retail'),
            'username' => env('DB_USERNAME', 'retail_app'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'timezone' => 'UTC',
            // A failed query's error names its SQL with ? placeholders, never the values: a value can be a
            // PIN's hash, a cost or a name, and the error goes to the log (finding LG1). Connections made at
            // run time copy this one, so they keep it.
            'mask_bindings_in_exception_messages' => true,
        ],

        // The owner role, which owns the tables. Only the one-shot migration job holds its password, so
        // the running app can never change the schema, the grants or the triggers (spec section 4).
        'pgsql_owner' => [
            'driver' => 'pgsql',
            'url' => env('DB_OWNER_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'retail'),
            'username' => env('DB_OWNER_USERNAME', 'retail_owner'),
            'password' => env('DB_OWNER_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'timezone' => 'UTC',
            'mask_bindings_in_exception_messages' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Database Roles
    |--------------------------------------------------------------------------
    |
    | The roles the mutability matrix grants to (spec section 4). The app role
    | runs the shop; the reporting role reads only cost-safe views.
    |
    */

    'roles' => [
        'app' => env('DB_APP_ROLE', 'retail_app'),
        'reporting' => env('DB_REPORTING_ROLE', 'retail_reporting'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];
