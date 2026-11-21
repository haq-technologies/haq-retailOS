<?php

declare(strict_types=1);

use App\Identity\Role;
use App\Platform\Http\RequestContext;
use App\Platform\Logging\ErrorCounts;
use App\Platform\Logging\IsoTimes;
use App\Platform\Logging\ReportThrottle;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * What the server's log keeps (the logging and error-tracking review, 20 November 2026): never a query's
 * values (LG1), each error tied to its request (LG5), one fault written a few times a minute (LG8), a typing
 * mistake as a message, not an error (LG9), and every time with its zone (LG10).
 */

/** Runs $act with the default log written to a file of its own, and returns what it wrote. */
function loggingTestCapture(Closure $act): string
{
    $path = storage_path('framework/testing/log-'.Str::random(8).'.log');
    config(['logging.channels.logging_test' => ['driver' => 'single', 'path' => $path, 'level' => 'debug', 'replace_placeholders' => true, 'tap' => [IsoTimes::class]]]);
    $before = Log::getDefaultDriver();
    Log::setDefaultDriver('logging_test');
    try {
        $act();
    } finally {
        Log::setDefaultDriver($before);
        Log::forgetChannel('logging_test');
    }
    $text = is_file($path) ? (string) file_get_contents($path) : '';
    @unlink($path);

    return $text;
}

it('logs a failed query with its SQL and placeholders, never its values (LG1)', function () {
    $log = loggingTestCapture(function () {
        try {
            // A savepoint of its own, so the failed query leaves the test's transaction usable.
            DB::transaction(fn () => DB::select('select ? as pin_hash from logging_test_no_such_table', ['$argon2id$v=19$secret-hash']));
        } catch (QueryException $e) {
            report($e);
        }
    });

    expect($log)->toContain('logging_test_no_such_table')
        ->toContain('select ? as pin_hash')
        ->not->toContain('secret-hash');
});

it("leaves out PostgreSQL's DETAIL line, which can hold a whole row, and keeps the trace (LG1)", function () {
    $pdo = new PDOException("SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"name\" of relation \"users\" violates not-null constraint\nDETAIL:  Failing row contains (01abc, null, \$argon2id\$v=19\$row-secret).");
    $e = new QueryException('pgsql', 'insert into "users" ("pin_hash") values (?)', ['$argon2id$v=19$row-secret'], $pdo, [], null, true);
    $line = __LINE__ - 1;

    $log = loggingTestCapture(fn () => report($e));

    expect($log)->toContain('Not null violation')
        ->toContain('insert into "users" ("pin_hash") values (?)')
        ->toContain('DETAIL: left out of the log')
        ->not->toContain('row-secret')
        // Rewritten in place, so the trace still points at where the query failed.
        ->and($e->getLine())->toBe($line);
});

it('stamps every log line with its zone (LG10)', function () {
    $log = loggingTestCapture(fn () => Log::info('A line with its time.'));

    expect($log)->toMatch('/^\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}\+00:00\] \w+\.INFO: A line with its time\./');
});

it('writes the same error a few times a minute and counts every one (LG8)', function () {
    $log = loggingTestCapture(function () {
        for ($i = 0; $i < 8; $i++) {
            report(new RuntimeException('The same fault, poll '.$i.'.'));
        }
        report(new RuntimeException('A different fault.'));
    });

    // Count entries, not mentions: an entry names its message again in the exception it carries.
    expect(preg_match_all('/\.ERROR: The same fault, poll/', $log))->toBe(ReportThrottle::PER_MINUTE)
        ->and($log)->toContain('A different fault.')
        ->and(ErrorCounts::lastDay()[ErrorCounts::ERROR])->toBe(9);
});

it('ties every answer and its log lines to a request ID, and names it in a 500 (LG5)', function () {
    config(['app.debug' => false]);
    Route::get('/api/logging-test/fails', fn () => throw new RuntimeException('A fault in a request.'));

    $response = null;
    $log = loggingTestCapture(function () use (&$response) {
        $response = $this->getJson('/api/logging-test/fails', [RequestContext::HEADER => str_repeat('ab', 16)]);
    });

    $response->assertStatus(500)->assertHeader(RequestContext::HEADER, str_repeat('ab', 16));
    expect($response->json('reference'))->toBe('ababab')
        ->and($response->json('message'))->toContain('tell HAQ support the reference ababab')
        ->and($response->json('message'))->not->toContain('A fault in a request')
        ->and($log)->toContain('A fault in a request.')
        ->toContain('"request_id":"'.str_repeat('ab', 16).'"')
        ->toContain('"request":"GET /api/logging-test/fails"');
});

it('makes its own request ID when nginx gave none, or one that is not its own (LG5)', function () {
    $made = $this->get('/up')->headers->get(RequestContext::HEADER);
    $spoofed = $this->get('/up', [RequestContext::HEADER => 'not-an-id; DROP'])->headers->get(RequestContext::HEADER);

    expect($made)->toMatch('/^[0-9a-f]{32}$/')
        ->and($spoofed)->toMatch('/^[0-9a-f]{32}$/')
        ->and($spoofed)->not->toBe($made);
});

it('turns away a PIN of the wrong length as a message, not a server error (LG9)', function () {
    $log = loggingTestCapture(function () {
        $this->artisan('shop:add-user', ['name' => 'Halima', '--role' => Role::Owner->value, '--pin' => '5862'])
            ->expectsOutputToContain("An owner's PIN is 6 digits. Nobody was added.")
            ->assertFailed();
    });

    expect($log)->toBe('')
        ->and(DB::table('users')->where('name', 'Halima')->exists())->toBeFalse();
});
