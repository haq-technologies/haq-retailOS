<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Scenario;

/** Appends entries to one chain, each in its own committed transaction, as a busy counter would. */
final class AppendAuditEntries implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $terminal = isset($args['terminal']) && is_string($args['terminal']) ? $args['terminal'] : null;
        $count = is_int($args['count'] ?? null) ? $args['count'] : 10;
        $log = app(AuditLog::class);

        for ($i = 1; $i <= $count; $i++) {
            DB::transaction(fn () => $log->record(new AuditEntry(
                action: 'test.append',
                terminalId: $terminal,
                after: ['worker' => $worker, 'n' => $i],
                sensitiveAfter: $i % 5 === 0 ? ['unit_cost_kobo' => 120_000 + $i] : null,
            )));
        }

        return ['worker' => $worker, 'appended' => $count];
    }
}
