<?php

declare(strict_types=1);

namespace App\Backups\Console;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Backups\Status;
use App\Organisation\BranchKey;
use App\Organisation\Models\Terminal;
use App\Sales\Epochs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * After a restore, a server replacement or a reinstall (spec sections 4 and 9) [F1, S14, S19]: every
 * counter starts a new epoch, one more than the highest known in the restored database or in the epoch
 * file on the backup disk, and its receipt numbers start after 1,000, so no receipt number can repeat.
 * The branch key comes back from the backup disk if this machine has none, so the epochs stay signed by
 * the same key.
 */
final class AfterRestoreCommand extends Command
{
    protected $signature = 'shop:after-restore {--reason=restore : restore, replacement or reinstall}';

    protected $description = 'Start a new receipt epoch on every counter after a restore';

    public function handle(Epochs $epochs, AuditLog $audit): int
    {
        $reason = (string) $this->option('reason');
        if (! in_array($reason, ['restore', 'replacement', 'reinstall'], true)) {
            $this->components->error('The reason is restore, replacement or reinstall.');

            return self::INVALID;
        }

        $status = Status::fromConfig();
        $this->bringBackKey($status);
        $file = $status->read('epochs.json');
        /** @var array<string, int> $known */
        $known = is_array($file['epochs'] ?? null) ? $file['epochs'] : [];

        $started = [];
        DB::transaction(function () use ($epochs, $reason, $known, &$started): void {
            foreach (Terminal::query()->where('kind', 'counter')->orderBy('code')->lockForUpdate()->get() as $terminal) {
                $inDatabase = DB::table('epochs')->where('terminal_id', $terminal->id)->max('epoch');
                $highest = max(is_numeric($inDatabase) ? (int) $inDatabase : 0, (int) ($known[$terminal->code] ?? 0));
                $started[$terminal->code] = $epochs->begin($terminal, $highest + 1, $reason, afterNumber: 1000);
            }
        });

        $audit->record(new AuditEntry(action: 'backup.epochs_started', subjectType: 'branch', after: ['reason' => $reason, 'epochs' => $started]));
        if ($status->enabled()) {
            $status->write('epochs.json', ['written_at' => now()->toIso8601String(), 'epochs' => TickCommand::highestEpochs()]);
        }

        foreach ($started as $code => $epoch) {
            $this->line("{$code} now numbers receipts {$code}-E{$epoch}-001001 onwards.");
        }
        $this->components->warn('Before trading resumes, check each counter has reconnected; sales on its journal that the restored copy lacks are re-entered as back entries (spec section 9).');

        return self::SUCCESS;
    }

    private function bringBackKey(Status $status): void
    {
        $key = app(BranchKey::class)->path();
        $copy = $status->path('keys/branch.key');
        if (is_file($key) || ! $status->enabled() || ! is_file($copy)) {
            return;
        }
        if (! is_dir(dirname($key))) {
            mkdir(dirname($key), 0o700, true);
        }
        copy($copy, $key);
        chmod($key, 0o600);
        $this->line('The branch key came back from the backup disk.');
    }
}
