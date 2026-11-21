<?php

declare(strict_types=1);

namespace App\Sales\Console;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The nightly clear-up of transfer senders (D112, finding DP6): a sender's name and phone are kept only
 * while the bank could still reverse the transfer, payments.transfer_contact_days (90), then deleted. The
 * sale, its payment and its bank reference stay; the audit trail records how many were cleared, never who.
 */
#[AsCommand(name: 'payments:clear-contacts')]
class ClearTransferContactsCommand extends Command
{
    /** The cache key the catch-up schedule reads for the last run. */
    public const HEALTH_KEY = 'health.transfer_contacts_cleared';

    protected $signature = 'payments:clear-contacts';

    protected $description = 'Delete transfer senders\' names and phones older than the reversal window';

    public function handle(Settings $settings, AuditLog $audit): int
    {
        $days = $settings->get('payments.transfer_contact_days');
        $days = is_int($days) && $days > 0 ? $days : 90;
        $before = CarbonImmutable::now()->subDays($days);

        $count = DB::transaction(function () use ($before, $days, $audit): int {
            $count = DB::table('transfer_contacts')->where('recorded_at', '<', $before->format('Y-m-d H:i:s.uP'))->delete();
            if ($count > 0) {
                $audit->record(new AuditEntry(action: 'payments.transfer_contacts_cleared', subjectType: 'transfer_contacts',
                    after: ['count' => $count, 'kept_days' => $days, 'recorded_before' => $before->toIso8601String()]));
            }

            return $count;
        });
        Cache::forever(self::HEALTH_KEY, ['checked_at' => CarbonImmutable::now()->toIso8601String(), 'problems' => []]);

        $this->components->info($count === 0 ? 'No transfer senders to clear.' : "Cleared {$count} transfer senders older than {$days} days.");

        return self::SUCCESS;
    }
}
