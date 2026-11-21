<?php

declare(strict_types=1);

namespace App\Platform\Database\Mutability;

use Illuminate\Database\Connection;

/**
 * The migrations a newer release ran on this database that this code does not have (D105). They are there
 * only after a failed update rolled back the code and kept the database (deploy/update.sh): the previous
 * release then runs on the newer schema, and the tables that release made are its own to classify, not
 * this code's. On a database that matches its code the list is empty, so an unclassified table still
 * stops the migration.
 */
final class NewerMigrations
{
    /**
     * @param  string|null  $path  The migrations folder (default: database/migrations).
     * @return list<string>
     */
    public static function on(Connection $db, ?string $path = null): array
    {
        $table = config('database.migrations.table', 'migrations');
        if (! is_string($table) || ! $db->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        $ran = [];
        foreach ($db->table($table)->orderBy('id')->pluck('migration') as $name) {
            if (is_string($name)) {
                $ran[] = $name;
            }
        }
        $files = array_map(fn (string $file) => basename($file, '.php'), glob(($path ?? database_path('migrations')).'/*.php') ?: []);

        return array_values(array_diff($ran, $files));
    }
}
