<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | The backup service's status folder on the backup disk (spec section 9) [S16, F24], shared with the
    | scheduler. Unset, backups:tick does nothing (tests and machines without the backup service).
    |
    */

    'status_dir' => env('BACKUP_STATUS_DIR'),

    // The weekly test restore's temporary PostgreSQL, run by the backup service.
    'restore_host' => env('BACKUP_RESTORE_HOST', 'backup'),
    'restore_port' => (int) env('BACKUP_RESTORE_PORT', 5433),

];
