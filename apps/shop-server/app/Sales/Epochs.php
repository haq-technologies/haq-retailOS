<?php

declare(strict_types=1);

namespace App\Sales;

use App\Organisation\BranchKey;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Restore epochs (spec section 4) [F1, S14, S19]. Each terminal numbers its receipts within an epoch:
 * T01-E1-000123. The first epoch begins at install; every restore, server replacement or reinstall
 * begins a new one, one more than the highest known anywhere, so a receipt number can never repeat.
 * Each epoch is recorded with the branch key's signature.
 */
final readonly class Epochs
{
    public function __construct(private BranchKey $key) {}

    /** The terminal's current epoch, beginning epoch 1 if it has none yet. Call inside a transaction. */
    public function current(Terminal $terminal): int
    {
        $epoch = DB::table('epochs')->where('terminal_id', $terminal->id)->max('epoch');
        if (is_numeric($epoch)) {
            return (int) $epoch;
        }

        // Two first sales at once meet here; the second finds the epoch the first began.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['epoch:'.$terminal->id]);
        $epoch = DB::table('epochs')->where('terminal_id', $terminal->id)->max('epoch');

        return is_numeric($epoch) ? (int) $epoch : $this->begin($terminal, 1, 'install');
    }

    /**
     * @param  int  $afterNumber  the receipt number the epoch counts on from: 1,000 after a restore (spec section 4) [F1]
     */
    public function begin(Terminal $terminal, int $epoch, string $reason, int $afterNumber = 0): int
    {
        $startedAt = CarbonImmutable::now()->format('Y-m-d\TH:i:s.u\Z');
        DB::table('epochs')->insert([
            'id' => Ulid::generate(),
            'terminal_id' => $terminal->id,
            'epoch' => $epoch,
            'started_at' => $startedAt,
            'reason' => $reason,
            'signature' => $this->key->sign(self::message($terminal->id, $epoch, $startedAt, $reason)),
        ]);
        DB::table('receipt_counters')->insert([
            'id' => Ulid::generate(), 'terminal_id' => $terminal->id, 'epoch' => $epoch, 'last_no' => $afterNumber,
        ]);

        return $epoch;
    }

    /** The exact text an epoch's signature covers. */
    public static function message(string $terminalId, int $epoch, string $startedAt, string $reason): string
    {
        return "haq-epoch-v1|{$terminalId}|{$epoch}|{$startedAt}|{$reason}";
    }
}
