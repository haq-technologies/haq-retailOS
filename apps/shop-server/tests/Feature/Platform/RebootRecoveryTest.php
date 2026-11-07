<?php

declare(strict_types=1);

use App\Platform\Database\Unavailable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

/*
 * Reboot recovery (spec section 4; check E2) [D46]: while the database is still starting after a power cut
 * or an update, a request is answered 503 with Retry-After, never a 500 error: a page gets "Starting, please
 * wait", which opens what was asked for by itself, and a counter's API call sees the server as not ready
 * yet, so the counter shows Reconnecting with its sale kept.
 */

function databaseError(string $message): QueryException
{
    return new QueryException('pgsql', 'select * from "sessions" where "id" = ?', [], new PDOException($message));
}

beforeEach(function () {
    Route::middleware('web')->get('/_test/db/{kind}', function (string $kind) {
        throw databaseError($kind === 'starting'
            ? 'SQLSTATE[08006] [7] connection to server at "db" (172.18.0.2), port 5432 failed: FATAL:  the database system is starting up'
            : 'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "sales_pkey"');
    });
    Route::middleware('web')->get('/api/_test/db', fn () => throw databaseError('SQLSTATE[08006] [7] connection to server at "db" (172.18.0.2), port 5432 failed: Connection refused'));
});

it('shows the starting page, not an error, while the database starts', function () {
    $response = $this->get('/_test/db/starting');
    $response->assertStatus(503)->assertHeader('Retry-After', '5')->assertSee('Starting, please wait')->assertSee('opens the counter by itself');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('tells a counter the server is not ready yet, so it shows Reconnecting', function () {
    $this->getJson('/api/_test/db')->assertStatus(503)->assertHeader('Retry-After', '5')
        ->assertJsonPath('message', 'The shop server is starting. It answers again in a moment.');
});

it('still reports any other database error as an error', function () {
    $this->withoutExceptionHandling();
    expect(fn () => $this->get('/_test/db/other'))->toThrow(QueryException::class);
    expect(Unavailable::is(databaseError('SQLSTATE[23505]: Unique violation')))->toBeFalse();
    expect(Unavailable::is(databaseError('SQLSTATE[08006] [7] could not translate host name "db" to address')))->toBeTrue();
    expect(Unavailable::is(new RuntimeException('Connection refused')))->toBeFalse();
});
