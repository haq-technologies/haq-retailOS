<?php

declare(strict_types=1);

namespace App\Health\Console;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Backups\Status;
use App\Health\Snapshot;
use App\Health\SupportAccess;
use App\Platform\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Every minute, in the scheduler (spec section 4) [F15, F24, D45]: checks the server's clock, collects what
 * the health page shows from the backup disk, the certificate and the host's report, and passes a Back up
 * now from the back office to the backup service. SSH sign-ins in the host's report go on the audit trail and the
 * owner's flag list (D110).
 */
final class CheckCommand extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Check the clock and collect the server health the back office shows';

    public function handle(Clock $clock, AuditLog $audit, SupportAccess $support): int
    {
        $reason = $clock->check();
        if ($reason !== null) {
            $audit->record(new AuditEntry(action: 'clock.unverified', subjectType: 'server', after: ['reason' => $reason, 'server_time' => now()->toIso8601String()]));
            $this->components->warn('The server clock is now unverified: '.$reason.'.');
        }
        if (Snapshot::forwardBackupRequest()) {
            $this->line('Asked the backup service for a backup now.');
        }
        Cache::forever(Snapshot::KEY, Snapshot::collect());
        $status = Status::fromConfig();
        if ($status->enabled() && ($new = $support->signIns($status->read('host.json'))) > 0) {
            $this->line("Recorded {$new} sign-ins to the server.");
        }

        return self::SUCCESS;
    }
}
