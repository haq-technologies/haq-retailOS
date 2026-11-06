<?php

declare(strict_types=1);

/*
 * One worker process for the multi-process suite. Usage: php tests/Concurrency/worker.php <Scenario class>
 * <worker number> <JSON arguments>. It boots the app, waits at the barrier until every worker is ready,
 * runs the scenario and prints its result as one JSON line.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness;
use Tests\Concurrency\Scenario;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $class, $worker, $json] = $argv + [null, null, '0', '{}'];
$scenario = new $class;
if (! $scenario instanceof Scenario) {
    fwrite(STDERR, "{$class} is not a scenario\n");
    exit(2);
}

/** @var array<string, mixed> $args */
$args = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);

// The barrier: the test holds this lock until every worker is waiting on it, then lets them all go.
DB::select('SELECT pg_advisory_lock_shared(?)', [Harness::BARRIER]);
DB::select('SELECT pg_advisory_unlock_shared(?)', [Harness::BARRIER]);

$result = $scenario->run((int) $worker, $args);
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
