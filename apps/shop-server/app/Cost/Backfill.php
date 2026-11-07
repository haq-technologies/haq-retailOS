<?php

declare(strict_types=1);

namespace App\Cost;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Catalogue\Merges;
use App\Identity\Models\User;
use App\Platform\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Entering a cost, with the backfill of earlier sales (spec section 8) [F14]. The owner sees a preview
 * before anything is written: how many sales, how much revenue, the estimated profit, which sales were
 * below the new cost, and the share of revenue with a cost before and after. On save, each item gets a
 * cost entry (later sales take it), and each chosen earlier line with no cost gets an estimate in
 * cost_resolutions. No sale line changes. Every entry is audited, its amount in the sensitive stream.
 */
final readonly class Backfill
{
    public function __construct(private AuditLog $audit) {}

    /**
     * @param  list<string>  $itemIds
     * @return array{items: list<array{item_id: string, name: string, sales: int, qty: int, revenue_kobo: int, profit_kobo: int, first_at: ?string}>, sales: int, revenue_kobo: int, profit_kobo: int, below_cost: list<array{receipt_number: string, item: string, unit_price_kobo: int, recorded_at: string, cashier: string}>, coverage_now: int, coverage_after: int}
     */
    public function preview(array $itemIds, int $unitCostKobo, string $basis, ?CarbonImmutable $from = null): array
    {
        $this->check($itemIds, $unitCostKobo, $basis, $from);
        $lines = $this->lines($itemIds, $basis, $from);

        $items = [];
        $below = [];
        foreach ($lines as $l) {
            $row = $items[$l->item_id] ?? ['item_id' => $l->item_id, 'name' => $l->name, 'sales' => 0, 'qty' => 0, 'revenue_kobo' => 0, 'profit_kobo' => 0, 'first_at' => null];
            $row['sales']++;
            $row['qty'] += (int) $l->qty;
            $row['revenue_kobo'] += (int) $l->line_total_kobo;
            $row['profit_kobo'] += (int) $l->line_total_kobo - (int) $l->qty * $unitCostKobo;
            $row['first_at'] ??= $l->recorded_at;
            $items[$l->item_id] = $row;
            if ((int) $l->unit_price_kobo < $unitCostKobo) {
                $below[] = ['receipt_number' => $l->receipt_number, 'item' => $l->name, 'unit_price_kobo' => (int) $l->unit_price_kobo, 'recorded_at' => $l->recorded_at, 'cashier' => $l->cashier];
            }
        }
        $revenue = array_sum(array_column($items, 'revenue_kobo'));
        [$covered, $total] = self::coverage();

        return [
            'items' => array_values($items),
            'sales' => count($lines),
            'revenue_kobo' => $revenue,
            'profit_kobo' => array_sum(array_column($items, 'profit_kobo')),
            'below_cost' => $below,
            'coverage_now' => $total === 0 ? 0 : intdiv($covered * 100, $total),
            'coverage_after' => $total === 0 ? 0 : intdiv(($covered + $revenue) * 100, $total),
        ];
    }

    /**
     * @param  list<string>  $itemIds
     * @return array{entries: int, resolved: int}
     */
    public function apply(User $actor, array $itemIds, int $unitCostKobo, CarbonImmutable $asOf, string $basis, ?CarbonImmutable $from = null, string $source = 'backfill_estimate'): array
    {
        $this->check($itemIds, $unitCostKobo, $basis, $from);
        $now = CarbonImmutable::now();
        // A cost dated today counts from now; one dated earlier counts from the start of that day.
        $effective = $asOf->isSameDay($now) || $asOf->isFuture() ? $now : $asOf->startOfDay();

        return DB::transaction(function () use ($actor, $itemIds, $unitCostKobo, $effective, $basis, $from, $source, $now): array {
            $resolved = 0;
            foreach ($itemIds as $itemId) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['cost:'.$itemId]);
                $entryId = Ulid::generate();
                DB::table('cost_entries')->insert(['id' => $entryId, 'item_id' => $itemId, 'unit_cost_kobo' => $unitCostKobo,
                    'effective_from' => $effective->format('Y-m-d H:i:s.uP'), 'entered_by' => $actor->id]);
                $lines = $this->lines([$itemId], $basis, $from);
                foreach ($lines as $l) {
                    DB::table('cost_resolutions')->insert([
                        'id' => Ulid::generate(), 'sale_line_id' => $l->id, 'item_id' => $itemId, 'unit_cost_kobo' => $unitCostKobo,
                        'source' => $source, 'basis' => $basis, 'cost_entry_id' => $entryId, 'resolved_by' => $actor->id,
                        'created_at' => $now->format('Y-m-d H:i:s.uP'),
                    ]);
                }
                $resolved += count($lines);
                $this->audit->record(new AuditEntry(action: 'cost.entered', actorId: $actor->id, subjectType: 'item', subjectId: $itemId,
                    after: ['change' => 'cost entered', 'earlier_sales_estimated' => count($lines), 'basis' => $basis],
                    sensitiveAfter: ['unit_cost_kobo' => $unitCostKobo, 'effective_from' => $effective->format('Y-m-d H:i:s.uP')]));
            }

            return ['entries' => count($itemIds), 'resolved' => $resolved];
        });
    }

    /**
     * Revenue with a cost (confirmed at sale or estimated) and all revenue, since trading began.
     *
     * @return array{0: int, 1: int}
     */
    public static function coverage(): array
    {
        /** @var object{covered: int|string|null, total: int|string|null} $row */
        $row = DB::selectOne(<<<'SQL'
            SELECT coalesce(sum(CASE WHEN l.cost_snapshot_kobo IS NOT NULL OR EXISTS (SELECT 1 FROM cost_resolutions r WHERE r.sale_line_id = l.id) THEN l.line_total_kobo END), 0) AS covered,
                   coalesce(sum(l.line_total_kobo), 0) AS total
            FROM sale_lines l JOIN sales s ON s.id = l.sale_id WHERE s.kind IN ('sale', 'back_entry')
            SQL);

        return [(int) $row->covered, (int) $row->total];
    }

    /**
     * The sale lines of these items with no cost: none at sale and none estimated since.
     *
     * @param  list<string>  $itemIds
     * @return list<object{id: string, item_id: string, name: string, qty: int, unit_price_kobo: int, line_total_kobo: int, recorded_at: string, receipt_number: string, cashier: string}>
     */
    private function lines(array $itemIds, string $basis, ?CarbonImmutable $from): array
    {
        // Lines of items merged into a chosen item count under it, as every report does [F18].
        $in = implode(', ', array_fill(0, count($itemIds), '?'));
        $since = $basis === 'from_date' && $from !== null ? $from->startOfDay()->format('Y-m-d H:i:s.uP') : null;
        $bindings = [...$itemIds, ...($since === null ? [] : [$since])];
        $since = $since === null ? '' : 'AND s.recorded_at >= ?';
        $roots = Merges::rootsCte();

        /** @var list<object{id: string, item_id: string, name: string, qty: int, unit_price_kobo: int, line_total_kobo: int, recorded_at: string, receipt_number: string, cashier: string}> $rows */
        $rows = DB::select(<<<SQL
            WITH RECURSIVE {$roots}
            SELECT l.id, r.root_id AS item_id, i.display_name AS name, l.qty, l.unit_price_kobo, l.line_total_kobo, s.recorded_at, s.receipt_number, u.name AS cashier
            FROM sale_lines l JOIN sales s ON s.id = l.sale_id JOIN item_roots r ON r.item_id = l.item_id JOIN items i ON i.id = r.root_id
            JOIN users u ON u.id = s.cashier_id
            WHERE r.root_id IN ({$in}) AND s.kind IN ('sale', 'back_entry') AND l.cost_snapshot_kobo IS NULL
              AND NOT EXISTS (SELECT 1 FROM cost_resolutions c WHERE c.sale_line_id = l.id) {$since}
            ORDER BY s.recorded_at, l.id
            SQL, $bindings);

        return $rows;
    }

    /**
     * @param  list<string>  $itemIds
     */
    private function check(array $itemIds, int $unitCostKobo, string $basis, ?CarbonImmutable $from): void
    {
        if ($itemIds === [] || count($itemIds) > 200) {
            throw new InvalidArgumentException('Choose between 1 and 200 items.');
        }
        if (DB::table('items')->whereIn('id', $itemIds)->where('status', 'merged')->exists()) {
            throw new InvalidArgumentException('One of these items was merged into another. Enter the cost for the item it counts under.');
        }
        if ($unitCostKobo < 0 || $unitCostKobo > 100_000_000_00) {
            throw new InvalidArgumentException('That cost is not a sensible amount.');
        }
        if (! in_array($basis, ['first_null', 'from_date'], true) || ($basis === 'from_date' && $from === null)) {
            throw new InvalidArgumentException('Choose which earlier sales the cost is for.');
        }
    }
}
