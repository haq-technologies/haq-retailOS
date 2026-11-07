<?php

declare(strict_types=1);

use App\Audit\Console\VerifyCommand;
use App\Health\CatchUp;
use App\Platform\Console\MutabilityCommand;
use Illuminate\Support\Facades\Schedule;

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
