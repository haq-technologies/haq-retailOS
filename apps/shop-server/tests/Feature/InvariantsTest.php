<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * Product invariants from CLAUDE.md and the spec that hold from the first line of code.
 * Each later phase adds the invariants it makes testable.
 */

uses(RefreshDatabase::class);

// Laravel's own tables keep Laravel's keys: they hold no business records and never sync (D21).
const FRAMEWORK_TABLES = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

it('runs on PostgreSQL 18 in UTC', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    /** @var object{server_version_num: string} $version */
    $version = DB::selectOne('SHOW server_version_num');
    expect((int) $version->server_version_num)->toBeGreaterThanOrEqual(180000)->toBeLessThan(190000);

    /** @var object{TimeZone: string} $zone */
    $zone = DB::selectOne('SHOW TIME ZONE');
    expect($zone->TimeZone)->toBe('UTC');

    expect(config('app.timezone'))->toBe('UTC');
    expect(date_default_timezone_get())->toBe('UTC');
});

it('has no Redis: sessions, jobs and the cache live in PostgreSQL', function () {
    expect(config('database.redis'))->toBeNull();
    expect(array_keys((array) config('database.connections')))->toBe(['pgsql']);

    /** @var array<string, array{driver: string}> $stores */
    $stores = config('cache.stores');
    expect(array_column($stores, 'driver'))->each->toBeIn(['array', 'database', 'file']);

    /** @var array<string, array{driver: string}> $connections */
    $connections = config('queue.connections');
    expect(array_column($connections, 'driver'))->each->toBeIn(['sync', 'database']);

    // The environment template a server starts from uses the database drivers.
    $example = (string) file_get_contents(base_path('.env.example'));
    expect($example)
        ->toContain("\nSESSION_DRIVER=database\n")
        ->toContain("\nQUEUE_CONNECTION=database\n")
        ->toContain("\nCACHE_STORE=database\n")
        ->not->toContain('REDIS_');

    // No Redis client is installed either.
    $lock = (string) file_get_contents(base_path('composer.lock'));
    expect($lock)->not->toContain('"name": "predis/predis"');
});

it('dispatches queued jobs only after the surrounding transaction commits', function () {
    expect(config('queue.connections.database.after_commit'))->toBeTrue();
});

it('gives every application table a ULID primary key', function () {
    $all = collect(DB::select(
        "select table_name from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE'"
    ))->pluck('table_name');

    // The query sees the schema: Laravel's own tables are there after migrating.
    expect($all)->toContain('migrations', 'sessions', 'cache', 'jobs');

    foreach ($all->diff(FRAMEWORK_TABLES) as $table) {
        $keys = DB::select(<<<'SQL'
            select a.attname as column_name, format_type(a.atttypid, a.atttypmod) as type
            from pg_index i
            join pg_attribute a on a.attrelid = i.indrelid and a.attnum = any(i.indkey)
            where i.indrelid = ?::regclass and i.indisprimary
            SQL, [$table]);

        expect($keys)->toHaveCount(1, "{$table} must have a single-column primary key");
        expect($keys[0]->column_name)->toBe('id', "{$table}'s primary key must be id");
        expect($keys[0]->type)->toBe('character(26)', "{$table}'s primary key must be a ULID");
    }
});
