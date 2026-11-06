<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * The guard every test run passes before it rebuilds a schema: tests only ever touch a database whose
 * name ends in _test or _concurrency, never a development or shop database.
 */
final class TestDatabase
{
    public static function assertSafe(): void
    {
        foreach (['pgsql', 'pgsql_owner'] as $connection) {
            $name = (string) config("database.connections.{$connection}.database");
            if (! str_ends_with($name, '_test') && ! str_ends_with($name, '_concurrency')) {
                throw new RuntimeException("Tests refuse to rebuild the database \"{$name}\": only a *_test or *_concurrency database may be used.");
            }
        }
    }
}
