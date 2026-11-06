<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Failure Spool
    |--------------------------------------------------------------------------
    |
    | Where failure entries wait when their audit chain is busy (spec section
    | 10). Tests point it elsewhere, so they never touch a server's spool.
    |
    */

    'spool' => env('AUDIT_SPOOL', storage_path('app/audit/spool.jsonl')),

];
