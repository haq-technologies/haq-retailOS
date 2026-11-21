<?php

declare(strict_types=1);

use App\Audit\Console\VerifyCommand;
use App\Health\CatchUp;
use App\Health\FailedTasks;
use App\Platform\Console\MutabilityCommand;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

// Catch-up scheduling (spec section 4) [F24, D45]: each nightly check runs once a day, in the night window
// or at the next quiet moment if the server was off at night.
$due = fn (string $key) => fn (): bool => app(CatchUp::class)->due($key);

// The nightly grant check (spec section 4) [F3].
Schedule::command('db:mutability --check')->everyFiveMinutes()->when($due(MutabilityCommand::HEALTH_KEY))->withoutOverlapping();

// Audit (spec section 10) [F4]: a root every minute, the failure spool ingested every minute, and every
// chain verified nightly.
Schedule::command('audit:root')->everyMinute()->withoutOverlapping();
Schedule::command('audit:ingest-spool')->everyMinute()->withoutOverlapping();
Schedule::command('audit:verify')->everyFiveMinutes()->when($due(VerifyCommand::HEALTH_KEY))->withoutOverlapping();

// A shift left open four hours past closing time closes as unclosed [S12].
Schedule::command('shifts:auto-close')->everyFiveMinutes()->withoutOverlapping();

// Beside the backup service: the key and epochs on the backup disk, and the weekly test restore's checks.
Schedule::command('backups:tick')->everyFiveMinutes()->withoutOverlapping();

// The server clock, and what the health page shows from the backup disk and the host (D45).
Schedule::command('health:check')->everyMinute()->withoutOverlapping();

// Every task above, and any added later (finding LG2). Laravel runs a task as `… > /dev/null 2>&1` and logs
// only "failed with exit code [1]", so the reason was lost. Now its output is kept in storage/logs (one file
// per task, written over on each run, so it never grows), and a failed task's output goes to the scheduler's
// own log under its name. The failure is kept for a day for the health page and `haq errors` (finding LG6).
foreach (Schedule::events() as $event) {
    $task = FailedTasks::name($event->command ?? (string) $event->description);
    $event->onFailureWithOutput(function (Stringable $output) use ($task): void {
        Log::error("The scheduled task {$task} failed.", ['output' => Str::limit(trim((string) $output), 4000)]);
        FailedTasks::record($task);
    });
}
