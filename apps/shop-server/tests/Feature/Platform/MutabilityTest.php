<?php

declare(strict_types=1);

use App\Platform\Database\Mutability\Applier;
use App\Platform\Database\Mutability\DriftCheck;
use App\Platform\Database\Mutability\Matrix;
use App\Platform\Database\Mutability\Roles;
use App\Platform\Database\Mutability\Rule;
use App\Platform\Database\Mutability\UnclassifiedTable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The mutability matrix in the database (spec sections 4 and 5) [F3, S15]. Probe tables stand in for
 * each kind of rule. The app role's statements run on their own autocommit connection, so they are
 * real, committed attempts, and nothing waits on the test's own transaction.
 */

const PROBES = ['probe_append', 'probe_once', 'probe_columns', 'probe_open', 'probe_users'];

function ownerDb(): Connection
{
    return DB::connection('pgsql_owner');
}

function appDb(): Connection
{
    config(['database.connections.pgsql_probe' => config('database.connections.pgsql')]);

    return DB::connection('pgsql_probe');
}

function probeMatrix(): Matrix
{
    return app(Matrix::class)->with([
        Rule::appendOnly('probe_append'),
        Rule::setOnce('probe_once', ['bound_at']),
        Rule::mutableColumns('probe_columns', ['status', 'attempts']),
        Rule::mutableWhileOpen('probe_open', 'status', ['closed']),
        Rule::neverDeleted('probe_users'),
    ]);
}

/** Runs one statement as the app role; returns the error message, or null when it succeeds. */
function attempt(string $sql): ?string
{
    try {
        appDb()->statement($sql);

        return null;
    } catch (QueryException $e) {
        return $e->getMessage();
    }
}

function newUlid(): string
{
    return (string) Str::ulid();
}

beforeEach(function () {
    $o = ownerDb();
    $o->statement('CREATE TABLE probe_append (id char(26) PRIMARY KEY, note text)');
    $o->statement('CREATE TABLE probe_once (id char(26) PRIMARY KEY, note text, bound_at timestamptz)');
    $o->statement('CREATE TABLE probe_columns (id char(26) PRIMARY KEY, note text, status text, attempts int)');
    $o->statement('CREATE TABLE probe_open (id char(26) PRIMARY KEY, note text, status text)');
    $o->statement('CREATE TABLE probe_users (id char(26) PRIMARY KEY, name text)');
    (new Applier(probeMatrix(), app(Roles::class)))->apply($o);
});

afterEach(function () {
    appDb()->disconnect();
    foreach (PROBES as $table) {
        ownerDb()->statement("DROP TABLE IF EXISTS {$table}");
    }
    (new Applier(app(Matrix::class), app(Roles::class)))->apply(ownerDb());
});

it('lets the app insert and read an append-only table, and nothing else', function () {
    $row = newUlid();
    expect(attempt("INSERT INTO probe_append VALUES ('{$row}', 'a sale')"))->toBeNull();
    expect(appDb()->select('SELECT * FROM probe_append'))->toHaveCount(1);

    expect(attempt("UPDATE probe_append SET note = 'changed'"))->toContain('permission denied');
    expect(attempt('DELETE FROM probe_append'))->toContain('permission denied');
    expect(attempt('TRUNCATE probe_append'))->toContain('permission denied');
});

it('holds append-only against a deliberately widened grant (gate G2)', function () {
    $row = newUlid();
    attempt("INSERT INTO probe_append VALUES ('{$row}', 'a sale')");

    ownerDb()->statement('GRANT UPDATE, DELETE, TRUNCATE ON probe_append TO '.config('database.roles.app'));

    expect(attempt("UPDATE probe_append SET note = 'changed'"))->toContain('UPDATE on probe_append is not allowed: the table is append-only');
    expect(attempt('DELETE FROM probe_append'))->toContain('DELETE on probe_append is not allowed');
    expect(attempt('TRUNCATE probe_append'))->toContain('TRUNCATE on probe_append is not allowed');

    /** @var object{note: string} $kept */
    $kept = appDb()->selectOne('SELECT note FROM probe_append');
    expect($kept->note)->toBe('a sale');
});

it('even stops the owner from changing an append-only row', function () {
    $row = newUlid();
    ownerDb()->statement("INSERT INTO probe_append VALUES ('{$row}', 'a sale')");

    expect(fn () => ownerDb()->statement("UPDATE probe_append SET note = 'changed'"))
        ->toThrow(QueryException::class, 'the table is append-only');
});

it('lets a set-once column be set once, from NULL, and nothing else change', function () {
    $row = newUlid();
    ownerDb()->statement("INSERT INTO probe_once VALUES ('{$row}', 'an approval', NULL)");

    expect(attempt("UPDATE probe_once SET note = 'changed'"))->toContain('permission denied');
    expect(attempt('UPDATE probe_once SET bound_at = now()'))->toBeNull();
    expect(attempt('UPDATE probe_once SET bound_at = now()'))->toContain('probe_once.bound_at is set once, and it is already set');

    // With a widened grant the trigger still holds.
    ownerDb()->statement('GRANT UPDATE, DELETE ON probe_once TO '.config('database.roles.app'));
    expect(attempt("UPDATE probe_once SET note = 'changed'"))->toContain('probe_once.note cannot change');
    expect(attempt('DELETE FROM probe_once'))->toContain('rows are never deleted');
});

it('lets only the listed columns change', function () {
    $row = newUlid();
    ownerDb()->statement("INSERT INTO probe_columns VALUES ('{$row}', 'a print job', 'pending', 0)");

    expect(attempt("UPDATE probe_columns SET status = 'printed', attempts = 1"))->toBeNull();
    expect(attempt("UPDATE probe_columns SET note = 'changed'"))->toContain('permission denied');
    expect(attempt('DELETE FROM probe_columns'))->toContain('permission denied');

    ownerDb()->statement('GRANT UPDATE ON probe_columns TO '.config('database.roles.app'));
    expect(attempt("UPDATE probe_columns SET note = 'changed'"))->toContain('probe_columns.note cannot change');
});

it('lets an open row change and locks it once closed', function () {
    $row = newUlid();
    ownerDb()->statement("INSERT INTO probe_open VALUES ('{$row}', 'a shift', 'open')");

    expect(attempt("UPDATE probe_open SET note = 'counted'"))->toBeNull();
    expect(attempt("UPDATE probe_open SET status = 'closed'"))->toBeNull();
    expect(attempt("UPDATE probe_open SET note = 'changed after close'"))->toContain('this probe_open row is closed');
    expect(attempt("UPDATE probe_open SET status = 'open'"))->toContain('this probe_open row is closed');
    expect(attempt('DELETE FROM probe_open'))->toContain('permission denied');
});

it('never deletes a user', function () {
    $row = newUlid();
    expect(attempt("INSERT INTO probe_users VALUES ('{$row}', 'Amina')"))->toBeNull();
    expect(attempt("UPDATE probe_users SET name = 'Amina B.'"))->toBeNull();
    expect(attempt('DELETE FROM probe_users'))->toContain('permission denied');

    ownerDb()->statement('GRANT DELETE ON probe_users TO '.config('database.roles.app'));
    expect(attempt('DELETE FROM probe_users'))->toContain('rows are never deleted');
});

it('gives the app no way to change the schema or hand out privileges', function () {
    expect(attempt('CREATE TABLE app_made (id int)'))->toContain('permission denied');
    expect(attempt('DROP TABLE probe_append'))->toContain('must be owner');
    expect(attempt('ALTER TABLE probe_append DISABLE TRIGGER haq_append_only'))->toContain('must be owner');

    // GRANT without the grant option grants nothing (PostgreSQL warns rather than fails).
    attempt('GRANT SELECT ON probe_append TO '.config('database.roles.reporting'));
    /** @var object{v: bool} $row */
    $row = ownerDb()->selectOne("SELECT has_table_privilege(?, 'probe_append', 'SELECT') AS v", [config('database.roles.reporting')]);
    expect($row->v)->toBeFalse();
});

it('finds no drift after applying, and reports every widened grant and disabled trigger', function () {
    $check = new DriftCheck(probeMatrix(), app(Roles::class), new Applier(probeMatrix(), app(Roles::class)));
    expect($check->problems(appDb()))->toBe([]);

    $role = config('database.roles.app');
    ownerDb()->statement("GRANT UPDATE, TRUNCATE ON probe_append TO {$role}");
    ownerDb()->statement("GRANT UPDATE (note) ON probe_once TO {$role}");
    ownerDb()->statement('ALTER TABLE probe_users DISABLE TRIGGER haq_no_delete');

    expect($check->problems(appDb()))->toEqualCanonicalizing([
        'The app role holds UPDATE on probe_append, which the matrix does not allow.',
        'The app role holds TRUNCATE on probe_append, which the matrix does not allow.',
        'The app role can update probe_once.note, which the matrix does not allow.',
        'The haq_no_delete trigger on probe_users is disabled.',
    ]);
});

it('refuses to apply the matrix when a table is not classified', function () {
    ownerDb()->statement('CREATE TABLE probe_stray (id char(26) PRIMARY KEY)');
    try {
        expect(fn () => (new Applier(probeMatrix(), app(Roles::class)))->apply(ownerDb()))
            ->toThrow(UnclassifiedTable::class, 'probe_stray');
    } finally {
        ownerDb()->statement('DROP TABLE probe_stray');
    }
});

it('refuses a mutable-while-open table with no lock condition', function () {
    ownerDb()->statement('CREATE TABLE probe_unlocked (id char(26) PRIMARY KEY, status text)');
    try {
        $matrix = probeMatrix()->with([Rule::mutableWhileOpen('probe_unlocked')]);
        expect(fn () => (new Applier($matrix, app(Roles::class)))->apply(ownerDb()))
            ->toThrow(LogicException::class, 'names no lock column');
    } finally {
        ownerDb()->statement('DROP TABLE probe_unlocked');
    }
});

it('passes the nightly grant check on the migrated database', function () {
    // The probes are not in the real matrix, so check after dropping them.
    foreach (PROBES as $table) {
        ownerDb()->statement("DROP TABLE IF EXISTS {$table}");
    }

    $this->artisan('db:mutability --check')->assertSuccessful();

    ownerDb()->statement('GRANT TRUNCATE ON sessions TO '.config('database.roles.app'));
    $this->artisan('db:mutability --check')
        ->expectsOutputToContain('The app role holds TRUNCATE on sessions')
        ->assertFailed();
});
