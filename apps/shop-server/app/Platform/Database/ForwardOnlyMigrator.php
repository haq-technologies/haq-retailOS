<?php

declare(strict_types=1);

namespace App\Platform\Database;

use Illuminate\Database\Migrations\Migrator;

/**
 * Migrations only go forward (D107). A shop's database is never rolled back: a failed update rolls back
 * the code only (deploy/update.sh), and a fix rolls forward as a new migration. Rolling back would drop
 * tables of sales that no append-only trigger can guard against, and it already stops at the first down()
 * those triggers refuse (2026_11_07_000400). So migrate:rollback, migrate:reset and migrate:refresh refuse,
 * and development and tests rebuild with migrate:fresh, which never runs a down().
 */
final class ForwardOnlyMigrator extends Migrator
{
    /**
     * @param  string[]|string  $paths
     * @param  array<string, mixed>  $options
     * @return array<int, string>
     */
    public function rollback($paths = [], array $options = []): array
    {
        throw new ForwardOnly;
    }

    /**
     * @param  string[]|string  $paths
     * @param  bool  $pretend
     * @return array<int, string>
     */
    public function reset($paths = [], $pretend = false): array
    {
        throw new ForwardOnly;
    }
}
