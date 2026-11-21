<?php

declare(strict_types=1);

use App\Health\FailedTasks;
use App\Health\Health;
use App\Identity\Enrolment;
use App\Platform\Logging\ErrorCounts;
use App\Platform\Logging\IsoTimes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\People;
use Tests\Support\Shop;

/*
 * Errors someone hears of (the logging and error-tracking review, 20 November 2026): a scheduled task's own
 * output when it fails (LG2), a counter screen's errors (LG3), and one plain line for the owner on the health
 * page with the details for HAQ support (LG6).
 */

beforeEach(function () {
    [$this->t1] = Shop::create()['terminals'];
    $this->device = People::enrol($this->t1);
});

/** Runs $act with the default log written to a file of its own, and returns what it wrote. */
function errorsTestCapture(Closure $act): string
{
    $path = storage_path('framework/testing/log-'.Str::random(8).'.log');
    config(['logging.channels.errors_test' => ['driver' => 'single', 'path' => $path, 'level' => 'debug', 'tap' => [IsoTimes::class]]]);
    $before = Log::getDefaultDriver();
    Log::setDefaultDriver('errors_test');
    try {
        $act();
    } finally {
        Log::setDefaultDriver($before);
        Log::forgetChannel('errors_test');
    }
    $text = is_file($path) ? (string) file_get_contents($path) : '';
    @unlink($path);

    return $text;
}

/** @return list<Event> the server's own schedule, from routes/console.php */
function errorsTestSchedule(): array
{
    test()->artisan('schedule:list')->assertSuccessful();

    return app(Schedule::class)->events();
}

it("keeps every scheduled task's output, never /dev/null (LG2)", function () {
    $events = errorsTestSchedule();

    expect($events)->not->toBeEmpty();
    foreach ($events as $event) {
        expect($event->output)->not->toBe('/dev/null')->toStartWith(storage_path('logs/schedule-'));
    }
});

it("writes a failed task's own output to the log under its name, and keeps the failure for a day (LG2)", function () {
    $event = collect(errorsTestSchedule())->first(fn (Event $e) => str_ends_with((string) $e->command, 'audit:root'));
    file_put_contents($event->output, "SQLSTATE[42501]: Insufficient privilege: 7 ERROR:  permission denied for table audit_roots\n");
    $event->exitCode = 1;

    $log = errorsTestCapture(fn () => $event->callAfterCallbacks(app()));

    expect($log)->toContain('The scheduled task audit:root failed.')
        ->toContain('permission denied for table audit_roots')
        ->and(FailedTasks::lastDay())->toHaveCount(1)
        ->and(FailedTasks::lastDay()[0])->toMatchArray(['task' => 'audit:root', 'count' => 1]);
    @unlink($event->output);
});

it('names a task from the command the scheduler runs', function () {
    expect(FailedTasks::name("'/usr/local/bin/php' 'artisan' db:mutability --check"))->toBe('db:mutability --check')
        ->and(FailedTasks::name('backups:tick'))->toBe('backups:tick');
});

it("takes a screen's error from an enrolled device into the log, counted, with its counter (LG3)", function () {
    $log = errorsTestCapture(function () {
        $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/device/errors', [
            'kind' => 'crash', 'message' => 'TypeError: lines is undefined', 'stack' => "at CartPanel\nat CounterScreen", 'page' => '/counter',
        ])->assertStatus(202)->assertJsonStructure(['reference']);
    });

    expect($log)->toContain('A screen on this device failed')
        ->toContain('TypeError: lines is undefined')
        ->toContain('"terminal":"'.$this->t1->code.'"')
        ->and(ErrorCounts::lastDay()[ErrorCounts::CRASH])->toBe(1);
});

it('refuses error reports from a device that is not enrolled, or that are not reports (LG3)', function () {
    $this->postJson('/api/device/errors', ['kind' => 'crash', 'message' => 'x'])->assertUnauthorized();
    $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/device/errors', ['kind' => 'cart', 'message' => 'x'])->assertUnprocessable();
    $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/device/errors', ['kind' => 'browser', 'message' => str_repeat('x', 501)])->assertUnprocessable();

    expect(ErrorCounts::lastDay()[ErrorCounts::CRASH] + ErrorCounts::lastDay()[ErrorCounts::BROWSER])->toBe(0);
});

it('takes at most 20 reports a minute from one device (LG3, LG8)', function () {
    $statuses = [];
    for ($i = 0; $i < 21; $i++) {
        $statuses[] = $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/device/errors', ['kind' => 'browser', 'message' => "Error {$i}"])->status();
    }

    expect(array_count_values($statuses))->toBe([202 => 20, 429 => 1]);
});

it('tells the owner in one plain line once the server has had errors, and not for one alone (LG6)', function () {
    $errorsWarning = fn () => collect(app(Health::class)->warnings())->firstWhere('kind', 'errors');

    ErrorCounts::add(ErrorCounts::ERROR);
    ErrorCounts::add(ErrorCounts::CRASH);
    expect($errorsWarning())->toBeNull();

    ErrorCounts::add(ErrorCounts::ERROR);
    // Browser errors that left the screen working are for HAQ support only.
    ErrorCounts::add(ErrorCounts::BROWSER);
    $warning = $errorsWarning();

    expect($warning['title'])->toBe('The server had errors')
        ->and($warning['detail'])->toStartWith('It logged 3 errors since ')
        ->and($warning['detail'])->toContain('Call HAQ support');
});

it('forgets errors after a day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00:00', 'UTC'));
    ErrorCounts::add(ErrorCounts::ERROR);
    expect(ErrorCounts::lastDay()[ErrorCounts::ERROR])->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-21 08:30:00', 'UTC'));
    expect(ErrorCounts::lastDay())->toMatchArray([ErrorCounts::ERROR => 0, 'first_at' => null]);
    $this->travelBack();
});

it('shows HAQ support what the errors were: counts, failed tasks and failed jobs (LG6)', function () {
    ErrorCounts::add(ErrorCounts::ERROR);
    ErrorCounts::add(ErrorCounts::BROWSER);
    FailedTasks::record('backups:tick');

    $this->artisan('health:errors')
        ->expectsOutputToContain('Errors the server logged:     1')
        ->expectsOutputToContain('Browser errors (screen kept): 1')
        ->expectsOutputToContain('backups:tick: 1 times')
        ->expectsOutputToContain('No queue job failed.')
        ->assertSuccessful();
});
