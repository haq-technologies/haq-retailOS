<?php

declare(strict_types=1);

namespace App\Backups\Console;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Backups\RestoreCheck;
use App\Backups\Status;
use App\Organisation\BranchKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Every five minutes, beside the backup service (spec section 9) [S14, F24]: keeps a copy of the branch key
 * and the highest epoch of each counter on the backup disk, so a restore elsewhere can carry on both, and
 * verifies a test restore that the backup service has made ready.
 */
final class TickCommand extends Command
{
    protected $signature = 'backups:tick';

    protected $description = 'Keep the key and epochs on the backup disk, and verify a ready test restore';

    public function handle(RestoreCheck $check, AuditLog $audit): int
    {
        $status = Status::fromConfig();
        if (! $status->enabled()) {
            return self::SUCCESS;
        }

        $this->keepKey($status);
        $status->write('epochs.json', ['written_at' => now()->toIso8601String(), 'epochs' => self::highestEpochs()]);

        $restore = $status->read('restore.json');
        $label = is_string($restore['label'] ?? null) ? $restore['label'] : null;
        if (($restore['state'] ?? null) !== 'ready' || $label === null || preg_match('/^[0-9A-Za-z_-]+$/', $label) !== 1) {
            return self::SUCCESS;
        }
        if (is_file($status->path("verified-{$label}.json"))) {
            return self::SUCCESS;
        }

        $port = is_int($restore['port'] ?? null) ? $restore['port'] : config()->integer('backups.restore_port');
        $result = $check->run(config()->string('backups.restore_host'), $port);
        $status->write("verified-{$label}.json", [...$result, 'label' => $label, 'checked_at' => now()->toIso8601String()]);
        // The label goes in the values: an audit subject is always a ULID.
        $audit->record(new AuditEntry(action: $result['ok'] ? 'backup.restore_verified' : 'backup.restore_failed',
            subjectType: 'backup', after: ['label' => $label, 'counts' => $result['counts'], 'problems' => $result['problems']]));
        $this->line($result['ok'] ? "The test restore of {$label} verified." : "The test restore of {$label} FAILED: ".implode(' ', $result['problems']));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, int> terminal code => highest epoch
     */
    public static function highestEpochs(): array
    {
        /** @var array<string, int|string> $rows */
        $rows = DB::table('epochs')->join('terminals', 'terminals.id', '=', 'epochs.terminal_id')
            ->groupBy('terminals.code')->selectRaw('terminals.code, max(epochs.epoch) AS epoch')->pluck('epoch', 'code')->all();

        $epochs = array_map(fn (int|string $e) => (int) $e, $rows);
        ksort($epochs);

        return $epochs;
    }

    /** The branch key's copy on the backup disk; the same box's database is readable anyway (spec section 9). */
    private function keepKey(Status $status): void
    {
        $key = app(BranchKey::class)->path();
        if (! is_file($key)) {
            return;
        }
        $dir = $status->path('keys');
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $copy = $dir.'/branch.key';
        if (! is_file($copy) || hash_file('sha256', $copy) !== hash_file('sha256', $key)) {
            copy($key, $copy);
            chmod($copy, 0o600);
        }
    }
}
