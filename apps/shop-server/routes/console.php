<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// The nightly grant check (spec section 4) [F3]. A missed night runs at the next start of the
// scheduler; catch-up scheduling for every job comes with the health page.
Schedule::command('db:mutability --check')->dailyAt('02:30')->withoutOverlapping();

// Audit (spec section 10) [F4]: a root every minute, the failure spool ingested every minute, and every
// chain verified nightly.
Schedule::command('audit:root')->everyMinute()->withoutOverlapping();
Schedule::command('audit:ingest-spool')->everyMinute()->withoutOverlapping();
Schedule::command('audit:verify')->dailyAt('02:45')->withoutOverlapping();
