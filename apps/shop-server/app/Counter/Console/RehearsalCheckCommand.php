<?php

declare(strict_types=1);

namespace App\Counter\Console;

use App\Audit\Verifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Gate G3's check after the scripted sales and the power cuts (spec section 14, checks B and D): receipt
 * numbers gapless on every counter and epoch, every sale whole (its lines and its payments add up to its
 * total), every receipt printed or listed as pending, every audit chain verified and the grants intact.
 */
final class RehearsalCheckCommand extends Command
{
    protected $signature = 'shop:rehearsal-check';

    protected $description = 'Gate G3: check every sale, receipt number, print job and audit chain after the rehearsal';

    public function handle(Verifier $verifier): int
    {
        $failures = 0;
        $check = function (bool $ok, string $what) use (&$failures): void {
            $this->line(($ok ? 'PASS  ' : 'FAIL  ').$what);
            $failures += $ok ? 0 : 1;
        };

        /** @var list<object{code: string, epoch: int, n: int, low: int, high: int, reason: string}> $runs */
        $runs = DB::select(<<<'SQL'
            SELECT t.code, s.epoch, count(*) AS n, min(s.receipt_no) AS low, max(s.receipt_no) AS high, e.reason
            FROM sales s JOIN terminals t ON t.id = s.terminal_id
            JOIN epochs e ON e.terminal_id = s.terminal_id AND e.epoch = s.epoch
            GROUP BY t.code, s.epoch, e.reason ORDER BY t.code, s.epoch
            SQL);
        foreach ($runs as $r) {
            $first = in_array($r->reason, ['restore', 'replacement', 'reinstall'], true) ? 1001 : 1;
            $check(self::gapless((int) $r->n, (int) $r->low, (int) $r->high, $first),
                "{$r->code} epoch {$r->epoch}: {$r->n} receipts, numbers {$r->low} to {$r->high} with no gap");
        }
        $check($runs !== [], 'there are sales to check');

        /** @var object{n: int|string}|null $unbalanced */
        $unbalanced = DB::selectOne(<<<'SQL'
            SELECT count(*) AS n FROM sales s
            WHERE s.total_kobo <> (SELECT coalesce(sum(line_total_kobo), 0) FROM sale_lines WHERE sale_id = s.id)
               OR s.total_kobo <> (SELECT coalesce(sum(amount_kobo), 0) FROM payments WHERE sale_id = s.id)
            SQL);
        $check((int) ($unbalanced->n ?? 1) === 0, 'every sale is whole: its lines and its payments add up to its total');

        /** @var array<string, int|string> $raw */
        $raw = DB::table('print_jobs')->where('kind', 'receipt')->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->all();
        $jobs = array_map(fn (int|string $n) => (int) $n, $raw);
        // A back entry prints nothing: the customer already holds the receipt (spec section 9) [F1].
        $sales = DB::table('sales')->where('kind', 'sale')->count();
        $check(array_sum($jobs) === $sales, "every sale has its receipt job ({$sales} sales; printed ".($jobs['printed'] ?? 0).', pending '.($jobs['pending'] ?? 0).', failed '.($jobs['failed'] ?? 0).')');
        /** @var list<string> $waiting */
        $waiting = DB::table('print_jobs as j')->join('sales as s', 's.id', '=', 'j.sale_id')->whereIn('j.status', ['pending', 'failed'])->pluck('s.receipt_number')->all();
        if ($waiting !== []) {
            $this->line('      Receipts still to print (each counter offers them as Print pending receipt): '.implode(', ', array_slice($waiting, 0, 20)).(count($waiting) > 20 ? ' …' : ''));
        }

        $problems = $verifier->problems();
        $check($problems === [], 'every audit chain and root verifies'.($problems === [] ? '' : ': '.implode(' ', array_slice($problems, 0, 3))));
        $check(Artisan::call('db:mutability', ['--check' => true]) === 0, 'the grants and triggers match the mutability matrix');

        $this->line($failures === 0 ? 'The rehearsal check passed.' : "The rehearsal check FAILED ({$failures}).");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Receipt numbers on one counter and epoch are gapless when n numbers run from the first to n more. */
    public static function gapless(int $count, int $low, int $high, int $first): bool
    {
        return $low === $first && $high - $low + 1 === $count;
    }
}
