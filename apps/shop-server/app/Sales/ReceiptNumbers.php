<?php

declare(strict_types=1);

namespace App\Sales;

use App\Organisation\Models\Terminal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gapless receipt numbers per terminal and epoch (spec section 4) [F1]. The counter is allocated inside
 * the sale's transaction from a locked row, so a failed sale rolls its number back and leaves no gap,
 * and terminals never wait on each other.
 */
final class ReceiptNumbers
{
    /**
     * @return array{number: int, formatted: string}
     */
    public static function next(Terminal $terminal, int $epoch): array
    {
        /** @var object{id: string, last_no: int}|null $counter */
        $counter = DB::table('receipt_counters')->where('terminal_id', $terminal->id)->where('epoch', $epoch)->lockForUpdate()->first(['id', 'last_no']);
        if ($counter === null) {
            throw new RuntimeException("Terminal {$terminal->code} has no receipt counter for epoch {$epoch}.");
        }
        $number = (int) $counter->last_no + 1;
        DB::table('receipt_counters')->where('id', $counter->id)->update(['last_no' => $number]);

        return ['number' => $number, 'formatted' => self::format($terminal->code, $epoch, $number)];
    }

    public static function format(string $terminalCode, int $epoch, int $number): string
    {
        return sprintf('%s-E%d-%06d', $terminalCode, $epoch, $number);
    }
}
