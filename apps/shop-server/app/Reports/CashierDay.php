<?php

declare(strict_types=1);

namespace App\Reports;

use App\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Each person's day at the counter, for the dashboard (spec section 8) [F11, F12, S2, S21]: sales, below-range
 * confirms (and whether the next one needs a manager), parked sales discarded, items created, transfers with
 * no reference, drawer openings with no sale or with the key, and transfers that waited over 30 minutes.
 */
final readonly class CashierDay
{
    public function __construct(private Settings $settings, private Flags $flags) {}

    /**
     * @return list<array{id: string, name: string, role: string, sales: int, below_range: int, below_range_limit: int, needs_manager: bool, discarded: int, items_created: int, no_reference: int, no_sale: int, key_open: int, transfers_waited: int}>
     */
    public function forDay(string $day): array
    {
        $span = Period::day($day, $this->flags->zone());
        $limit = $this->settings->get('pricing.below_range_confirms');
        $limit = is_int($limit) ? $limit : 3;

        $sales = $this->byPerson("SELECT cashier_id AS id, count(*) AS n FROM sales WHERE kind = 'sale' AND business_day = ? GROUP BY cashier_id", [$day]);
        $belowRange = $this->byPerson(<<<'SQL'
            SELECT s.cashier_id AS id, count(*) AS n FROM sale_lines l JOIN sales s ON s.id = l.sale_id
            WHERE s.kind = 'sale' AND s.business_day = ? AND l.flags->>'outside' = 'below' AND l.approval_id IS NULL GROUP BY s.cashier_id
            SQL, [$day]);
        // The confirms used in each person's open shift, as the counter counts them (PriceRules).
        $inOpenShift = $this->byPerson(<<<'SQL'
            SELECT s.cashier_id AS id, count(*) AS n FROM sale_lines l JOIN sales s ON s.id = l.sale_id JOIN shifts sh ON sh.id = s.shift_id
            WHERE s.kind = 'sale' AND sh.status = 'open' AND l.flags->>'outside' = 'below' AND l.approval_id IS NULL GROUP BY s.cashier_id
            SQL, []);
        $discarded = $this->byPerson("SELECT actor_id AS id, count(*) AS n FROM parked_sale_events WHERE type = 'discarded' AND at >= ? AND at < ? AND actor_id IS NOT NULL GROUP BY actor_id",
            [$span->fromSql(), $span->toSql()]);
        $created = $this->byPerson("SELECT created_by AS id, count(*) AS n FROM items WHERE source IN ('cashier', 'typed') AND created_at >= ? AND created_at < ? AND created_by IS NOT NULL GROUP BY created_by",
            [$span->fromSql(), $span->toSql()]);
        $noReference = $this->byPerson(<<<'SQL'
            SELECT s.cashier_id AS id, count(*) AS n FROM payments pm JOIN sales s ON s.id = pm.sale_id
            WHERE s.kind = 'sale' AND s.business_day = ? AND pm.method = 'transfer' AND coalesce(btrim(pm.reference), '') = '' GROUP BY s.cashier_id
            SQL, [$day]);
        $noSale = $this->byPerson("SELECT actor_id AS id, count(*) AS n FROM cash_events WHERE type = 'no_sale_open' AND at >= ? AND at < ? AND actor_id IS NOT NULL GROUP BY actor_id",
            [$span->fromSql(), $span->toSql()]);
        $keyOpen = $this->byPerson("SELECT actor_id AS id, count(*) AS n FROM cash_events WHERE type = 'key_open' AND at >= ? AND at < ? AND actor_id IS NOT NULL GROUP BY actor_id",
            [$span->fromSql(), $span->toSql()]);
        $waited = [];
        foreach ($this->flags->list($span, false, ['transfer_waited']) as $flag) {
            if ($flag['cashier_id'] !== null) {
                $waited[$flag['cashier_id']] = ($waited[$flag['cashier_id']] ?? 0) + 1;
            }
        }

        $ids = array_keys($sales + $belowRange + $discarded + $created + $noReference + $noSale + $keyOpen + $waited);
        if ($ids === []) {
            return [];
        }
        /** @var list<object{id: string, name: string, role: string}> $people */
        $people = DB::table('users')->whereIn('id', $ids)->get(['id', 'name', 'role'])->all();

        $rows = array_map(fn (object $u) => [
            'id' => $u->id, 'name' => $u->name, 'role' => $u->role,
            'sales' => $sales[$u->id] ?? 0,
            'below_range' => $belowRange[$u->id] ?? 0, 'below_range_limit' => $limit,
            'needs_manager' => ($inOpenShift[$u->id] ?? 0) >= $limit,
            'discarded' => $discarded[$u->id] ?? 0, 'items_created' => $created[$u->id] ?? 0,
            'no_reference' => $noReference[$u->id] ?? 0, 'no_sale' => $noSale[$u->id] ?? 0, 'key_open' => $keyOpen[$u->id] ?? 0,
            'transfers_waited' => $waited[$u->id] ?? 0,
        ], $people);
        usort($rows, fn (array $a, array $b) => [$b['sales'], $a['name']] <=> [$a['sales'], $b['name']]);

        return $rows;
    }

    /**
     * @param  list<mixed>  $bindings
     * @return array<string, int>
     */
    private function byPerson(string $sql, array $bindings): array
    {
        /** @var list<object{id: string, n: int|string}> $rows */
        $rows = DB::select($sql, $bindings);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->id] = (int) $r->n;
        }

        return $out;
    }
}
