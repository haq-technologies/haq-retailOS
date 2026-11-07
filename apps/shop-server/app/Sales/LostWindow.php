<?php

declare(strict_types=1);

namespace App\Sales;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The lost-window screen (spec section 9; wireframe LostWindow) [F1]: the last restore, whether each
 * counter has reported its journal since, and the sales the journals hold and the restored data lacks,
 * waiting to be re-entered or already re-entered. Selling prices only; no cost.
 */
final class LostWindow
{
    /** How long the screen stays in the back office's menu after a restore. */
    public const SHOWN_DAYS = 30;

    /** Lost sales still to re-enter. */
    public static function waiting(): int
    {
        return DB::table('lost_sales')->whereNotExists(fn ($q) => $q->from('back_entries')->whereColumn('back_entries.lost_sale_id', 'lost_sales.id'))->count();
    }

    /** Whether the screen belongs in the menu: a recent restore, or a lost sale still to re-enter. */
    public static function shown(): bool
    {
        $since = CarbonImmutable::now()->subDays(self::SHOWN_DAYS)->format('Y-m-d H:i:s.uP');

        return self::waiting() > 0 || DB::table('restores')->where('at', '>=', $since)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public static function view(): array
    {
        /** @var object{id: string, reason: string, epochs: string, restored_to: ?string, at: string}|null $restore */
        $restore = DB::table('restores')->orderByDesc('at')->first();

        $counters = [];
        foreach (DB::table('terminals')->where('kind', 'counter')->whereNull('deactivated_at')->orderBy('code')->get(['id', 'code', 'name']) as $t) {
            $epoch = DB::table('epochs')->where('terminal_id', $t->id)->max('epoch');
            $report = $restore === null ? null : DB::table('journal_reports')->where('terminal_id', $t->id)
                ->where('reported_at', '>=', $restore->at)->orderByDesc('reported_at')->first(['reported_at', 'missing', 'raised_to']);
            $counters[] = [
                'code' => $t->code, 'name' => $t->name, 'epoch' => is_numeric($epoch) ? (int) $epoch : null,
                'reported_at' => $report?->reported_at, 'missing' => $report === null ? null : (int) $report->missing,
                'raised_to' => $report?->raised_to === null ? null : (int) $report->raised_to,
            ];
        }

        $since = CarbonImmutable::now()->subDays(self::SHOWN_DAYS)->format('Y-m-d H:i:s.uP');
        $rows = DB::table('lost_sales as l')->join('terminals as t', 't.id', '=', 'l.terminal_id')
            ->join('journal_reports as r', 'r.id', '=', 'l.journal_report_id')
            ->leftJoin('back_entries as b', 'b.lost_sale_id', '=', 'l.id')
            ->leftJoin('users as u', 'u.id', '=', 'b.entered_by')
            ->where(fn ($q) => $q->whereNull('b.id')->orWhere('r.reported_at', '>=', $since))
            ->orderByRaw('b.id IS NOT NULL')->orderBy('l.sold_at')->orderBy('t.code')->orderBy('l.epoch')->orderBy('l.receipt_no')
            ->get(['l.id', 'l.receipt_number', 't.code as terminal', 't.name as terminal_name', 'l.sold_at', 'l.business_day', 'l.cashier_name',
                'l.customer_type', 'l.total_kobo', 'l.entry', 'b.sale_id', 'b.entered_at', 'u.name as entered_by']);

        $lost = [];
        foreach ($rows as $r) {
            /** @var array{lines: list<array{item_id: ?string, name: string, colour: ?string, qty: int, unit_price_kobo: int}>, payments: list<array{method: string, amount_kobo: int, tendered_kobo: ?int, reference: ?string}>} $entry */
            $entry = json_decode((string) $r->entry, true, flags: JSON_THROW_ON_ERROR);
            $ids = array_values(array_filter(array_column($entry['lines'], 'item_id'), 'is_string'));
            /** @var array<string, string> $names */
            $names = $ids === [] ? [] : DB::table('items')->whereIn('id', $ids)->pluck('display_name', 'id')->all();
            $lost[] = [
                'id' => $r->id, 'receipt_number' => $r->receipt_number, 'terminal' => $r->terminal, 'terminal_name' => $r->terminal_name,
                'sold_at' => $r->sold_at, 'business_day' => $r->business_day, 'cashier' => $r->cashier_name,
                'customer_type' => $r->customer_type, 'total_kobo' => (int) $r->total_kobo,
                'lines' => array_map(fn (array $l) => [
                    'name' => $l['name'], 'colour' => $l['colour'], 'qty' => $l['qty'], 'unit_price_kobo' => $l['unit_price_kobo'],
                    'item_found' => is_string($l['item_id']) && isset($names[$l['item_id']]),
                ], $entry['lines']),
                'payments' => array_map(fn (array $p) => ['method' => $p['method'], 'amount_kobo' => $p['amount_kobo'], 'reference' => $p['reference']], $entry['payments']),
                'entered' => $r->sale_id === null ? null : ['sale_id' => $r->sale_id, 'at' => $r->entered_at, 'by' => $r->entered_by],
            ];
        }

        return [
            'restore' => $restore === null ? null : [
                'at' => $restore->at, 'reason' => $restore->reason, 'restored_to' => $restore->restored_to,
                'epochs' => json_decode($restore->epochs, true, flags: JSON_THROW_ON_ERROR),
            ],
            'counters' => $counters,
            'lost' => $lost,
            'waiting' => count(array_filter($lost, fn (array $l) => $l['entered'] === null)),
        ];
    }
}
