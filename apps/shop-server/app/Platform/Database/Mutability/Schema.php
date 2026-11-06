<?php

declare(strict_types=1);

namespace App\Platform\Database\Mutability;

use Illuminate\Database\Connection;

/**
 * Reads the live schema from pg_catalog, which every role can see (information_schema hides objects
 * the current role holds no privilege on).
 */
final class Schema
{
    /**
     * @return list<string>
     */
    public static function tables(Connection $db): array
    {
        /** @var list<object{name: string}> $rows */
        $rows = $db->select(<<<'SQL'
            SELECT c.relname AS name
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
            ORDER BY c.relname
            SQL);

        return array_map(fn (object $row) => $row->name, $rows);
    }

    /**
     * @return list<string>
     */
    public static function sequencesOf(Connection $db, string $table): array
    {
        /** @var list<object{name: string}> $rows */
        $rows = $db->select(<<<'SQL'
            SELECT s.relname AS name
            FROM pg_class s
            JOIN pg_depend d ON d.objid = s.oid AND d.deptype IN ('a', 'i')
            JOIN pg_class t ON t.oid = d.refobjid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE s.relkind = 'S' AND n.nspname = 'public' AND t.relname = ?
            ORDER BY s.relname
            SQL, [$table]);

        return array_map(fn (object $row) => $row->name, $rows);
    }

    /**
     * @return list<string>
     */
    public static function triggersOf(Connection $db, string $table, string $prefix = ''): array
    {
        /** @var list<object{name: string}> $rows */
        $rows = $db->select(<<<'SQL'
            SELECT tg.tgname AS name
            FROM pg_trigger tg
            JOIN pg_class t ON t.oid = tg.tgrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE NOT tg.tgisinternal AND n.nspname = 'public' AND t.relname = ? AND starts_with(tg.tgname, ?)
            ORDER BY tg.tgname
            SQL, [$table, $prefix]);

        return array_map(fn (object $row) => $row->name, $rows);
    }
}
