<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use Tests\Concurrency\Scenario;

/** Logs a failed sale on the branch chain while another worker holds its lock. */
final class RecordFailureWhileBusy implements Scenario
{
    public function run(int $worker, array $args): array
    {
        usleep(300_000); // let the other worker take the branch chain first
        $started = microtime(true);
        $id = app(AuditLog::class)->recordFailure(new AuditEntry(action: 'sale.failed', after: ['reason' => 'test']));

        return ['spooled' => $id === null, 'ms' => (int) round((microtime(true) - $started) * 1000)];
    }
}
