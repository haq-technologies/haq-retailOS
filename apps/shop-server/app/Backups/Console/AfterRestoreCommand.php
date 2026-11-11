<?php

declare(strict_types=1);

namespace App\Backups\Console;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Backups\KeptKeys;
use App\Backups\Status;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Sales\Epochs;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * After a restore, a server replacement or a reinstall (spec sections 4 and 9) [F1, S14, S19]: every
 * counter starts a new epoch, one more than the highest known in the restored database or in the epoch
 * file on the backup disk, and its receipt numbers start after 1,000, so no receipt number can repeat.
 * The branch key comes back from the backup disk if this machine has none, so the epochs stay signed by
 * the same key, and the shop's certificate authority comes back in place of one a spare made for itself, so
 * the counters trust this server as before (D69). The restore is recorded: each counter then reports its journal before it sells again, the
 * lost-window screen lists what the journals hold and the restored data lacks, and every counter takes a
 * fresh copy of the catalogue.
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
        $kept = KeptKeys::fromConfig()->bringBack();
        foreach ($kept['done'] as $line) {
            $this->line($line);
        }
        foreach ($kept['warnings'] as $line) {
            $this->components->warn($line);
        }
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
            $newest = DB::table('sales')->max('recorded_at');
            DB::table('restores')->insert([
                'id' => Ulid::generate(), 'reason' => $reason, 'epochs' => json_encode((object) $started, JSON_THROW_ON_ERROR),
                'restored_to' => is_string($newest) ? $newest : null, 'at' => CarbonImmutable::now()->format('Y-m-d H:i:s.uP'),
            ]);
        });

        $audit->record(new AuditEntry(action: 'backup.epochs_started', subjectType: 'branch', after: ['reason' => $reason, 'epochs' => $started]));
        if ($status->enabled()) {
            TickCommand::keepEpochs($status);
        }

        foreach ($started as $code => $epoch) {
            $this->line("{$code} now numbers receipts {$code}-E{$epoch}-001001 onwards.");
        }
        $this->components->warn('Each counter reports its journal when it reconnects, before it sells again. A manager then re-enters the sales the journals hold and this copy lacks, on the Lost sales screen of the back office (spec section 9).');

        return self::SUCCESS;
    }
}
