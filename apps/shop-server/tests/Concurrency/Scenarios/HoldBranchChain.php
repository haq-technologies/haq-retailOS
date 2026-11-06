<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Scenario;

/** Records on the branch chain and holds its tail lock for a while before committing. */
final class HoldBranchChain implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $holdMs = is_int($args['hold_ms'] ?? null) ? $args['hold_ms'] : 1500;
        DB::transaction(function () use ($holdMs): void {
            app(AuditLog::class)->record(new AuditEntry(action: 'test.slow'));
            usleep($holdMs * 1000);
        });

        return ['held_ms' => $holdMs];
    }
}
