<?php

declare(strict_types=1);

namespace App\Platform\Database;

use RuntimeException;

/**
 * A rollback was asked for, and migrations only go forward (D107).
 */
final class ForwardOnly extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Migrations only go forward (D107): a database is never rolled back. To undo a change, write a new '
            .'migration. To rebuild a development or test database, run php artisan migrate:fresh.'
        );
    }
}
