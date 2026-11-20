<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Server health
    |--------------------------------------------------------------------------
    |
    | The health page (spec section 4) [F24, D45]. The scheduler collects what only it can see (the backup
    | disk, the certificate, the host's own report of its SSDs and its time source) every minute; the page
    | reads that and the database.
    |
    */

    // The HTTPS certificate nginx serves; in a shop the scheduler mounts the tls volume here.
    'certificate' => env('HEALTH_CERTIFICATE', storage_path('app/tls/server.crt')),

    // Warn this many days before the certificate expires (spec section 4).
    'certificate_warn_days' => 60,

    // Warn when the last backup is older than this (spec sections 4 and 8).
    'backup_warn_hours' => 48,

    // Warn when no test restore has verified for this long (it runs weekly).
    'restore_warn_days' => 9,

    // Whether the backups have a disk of their own (install.sh --backup-dir), or share the database's: a
    // Windows server, or a mini-PC with no second disk. Sharing, a flash drive is the only copy away from
    // that disk, so the off-site copy is warned about after a day, not a week (D97).
    'backup_own_disk' => (bool) env('BACKUP_OWN_DISK', false),

    // Warn when no flash drive has had an off-site copy for this long: the drives are swapped weekly, or,
    // when the backups share the database's disk, copied each day (D53, D97).
    'offsite_warn_days' => 8,
    'offsite_shared_warn_hours' => 24,

    // Warn when a disk has less free than this share or this many bytes.
    'disk_warn_percent' => 10,
    'disk_warn_bytes' => 5 * 1024 ** 3,

    // Warn when an SSD has less life left than this (from smartctl, through the host's report).
    'ssd_warn_percent' => 10,

];
