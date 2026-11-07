<?php

declare(strict_types=1);

namespace App\Reports;

use App\Platform\Money;
use App\Sales\Parked;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The flag list (spec sections 8 and 12) [F11, F12, F14, S2, S12, S21]: sales below cost, when saved and
 * again when a later cost entry turns earlier sales into losses; prices below the floor or outside the
 * usual range; transfers with no reference or waiting over 30 minutes; discarded parked sales; drawer
 * openings with no sale or with the key; shifts that closed by themselves; approvals by a manager who had
 * not signed in; lockouts after wrong PINs; and sales re-entered after a restore, which are back-dated
 * (spec section 9) [F1].
 *
 * Each flag is read from the append-only record that raised it, so a flag can never be lost or deleted.
 * Marking one as seen adds a row to flag_marks; the flag stays listed, with who saw it. Cost amounts are
 * left out for anyone who may not see cost (spec section 3) [F10].
 *
 * @phpstan-type Flag array{key: string, kind: string, label: string, at: string, title: string, detail: string, who: ?string, cashier_id: ?string, approver: ?string, terminal: ?string, sale_ids: list<string>, receipt_number: ?string, amount_kobo: ?int, cost_kobo?: int, live: bool, seen_by: ?string, seen_at: ?string}
 */
final readonly class Flags
{
    /** Each kind's short name, as the filter chips and the printed summary show it. */
    public const KINDS = [
        'below_cost' => 'Below cost',
        'below_cost_later' => 'Now below cost',
        'below_floor' => 'Below the floor',
        'unusual_price' => 'Unusual price',
        'no_reference' => 'No transfer reference',
        'transfer_waited' => 'Transfer waited over 30 minutes',
        'discarded' => 'Parked sale discarded',
        'no_sale' => 'Drawer opened, no sale',
        'key_open' => 'Drawer opened with key',
        'auto_closed' => 'Shift closed by itself',
        'approval_away' => 'Approved while not signed in',
        'locked_out' => 'Locked out',
        'back_entry' => 'Re-entered after a restore',
    ];

    /** What goes to the dashboard's Needs your attention until someone marks it as seen. */
    public const ATTENTION = ['below_cost', 'below_cost_later', 'below_floor', 'transfer_waited', 'approval_away', 'locked_out'];

    public function __construct(private Settings $settings) {}

    public function zone(): string
    {
        $zone = $this->settings->get('business.timezone');

        return is_string($zone) ? $zone : 'Africa/Lagos';
    }

    /**
     * Every flag raised in the period, newest first.
     *
     * @param  list<string>|null  $kinds  only these kinds
     * @return list<Flag>
     */
    public function list(Period $period, bool $withCost, ?array $kinds = null, ?string $cashierId = null): array
    {
        $wanted = $kinds ?? array_keys(self::KINDS);
        $belowCost = $this->settings->get('flags.below_cost') !== false;
        $rows = [];
        foreach ($wanted as $kind) {
            $found = match ($kind) {
                'below_cost' => $belowCost ? $this->belowCost($period, $withCost) : [],
                'below_cost_later' => $belowCost ? $this->belowCostLater($period, $withCost) : [],
                'below_floor' => $this->belowFloor($period),
                'unusual_price' => $this->unusualPrice($period),
                'no_reference' => $this->noReference($period),
                'transfer_waited' => $this->transferWaited($period),
                'discarded' => $this->discarded($period),
                'no_sale', 'key_open' => $this->drawer($period, $kind),
                'auto_closed' => $this->autoClosed($period),
                'approval_away' => $this->approvalAway($period),
                'locked_out' => $this->lockedOut($period),
                'back_entry' => $this->backEntries($period),
                default => [],
            };
            array_push($rows, ...$found);
        }
        if ($cashierId !== null) {
            $rows = array_values(array_filter($rows, fn (array $r) => $r['cashier_id'] === $cashierId));
        }
        usort($rows, fn (array $a, array $b) => [$b['at'], $b['key']] <=> [$a['at'], $a['key']]);

        return $this->withMarks($rows);
    }

    /**
     * How many of each kind, for the chips and the printed summary.
     *
     * @param  list<Flag>  $flags
     * @return array<string, int>
     */
    public static function counts(array $flags): array
    {
        $counts = array_fill_keys(array_keys(self::KINDS), 0);
        foreach ($flags as $flag) {
            $counts[$flag['kind']]++;
        }

        return $counts;
    }

    /** Whether a key names a record that raises that kind of flag. */
    public static function exists(string $key): bool
    {
        if (preg_match('/^([a-z_]+):([0-9a-hjkmnp-tv-z]{26})$/', $key, $m) !== 1 || ! isset(self::KINDS[$m[1]])) {
            return false;
        }
        [$table, $column] = match ($m[1]) {
            'below_cost', 'below_floor', 'unusual_price' => ['sale_lines', 'id'],
            'below_cost_later' => ['cost_entries', 'id'],
            'no_reference' => ['payments', 'id'],
            'transfer_waited' => ['parked_sales', 'id'],
            'discarded' => ['parked_sale_events', 'id'],
            'no_sale', 'key_open' => ['cash_events', 'id'],
            'auto_closed' => ['shifts', 'id'],
            'approval_away' => ['approvals', 'id'],
            'back_entry' => ['back_entries', 'id'],
            default => ['audit_log', 'id'],
        };

        return DB::table($table)->where($column, $m[2])->exists();
    }

    /**
     * @return list<Flag>
     */
    private function belowCost(Period $p, bool $withCost): array
    {
        /** @var list<object{id: string, name: string, qty: int, price: int, cost: int, sale_id: string, receipt_number: string, at: string, cashier_id: string, cashier: string, terminal: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT l.id, l.name_snapshot AS name, l.qty, l.unit_price_kobo AS price, l.cost_snapshot_kobo AS cost, s.id AS sale_id,
                   s.receipt_number, s.recorded_at AS at, u.id AS cashier_id, u.name AS cashier, t.name AS terminal
            FROM sale_lines l JOIN sales s ON s.id = l.sale_id JOIN users u ON u.id = s.cashier_id JOIN terminals t ON t.id = s.terminal_id
            WHERE s.kind IN ('sale', 'back_entry') AND l.cost_snapshot_kobo IS NOT NULL AND l.unit_price_kobo < l.cost_snapshot_kobo
              AND s.recorded_at >= ? AND s.recorded_at < ?
            SQL, [$p->fromSql(), $p->toSql()]);

        return array_map(fn (object $r) => $this->row('below_cost', $r->id, $r->at, 'Sold below cost',
            self::qty((int) $r->qty).$r->name.' at '.Money::naira((int) $r->price).($withCost ? '; cost '.Money::naira((int) $r->cost) : ''),
            who: $r->cashier, cashierId: $r->cashier_id, terminal: $r->terminal, saleIds: [$r->sale_id], receipt: $r->receipt_number,
            amount: (int) $r->price * (int) $r->qty, cost: $withCost ? (int) $r->cost : null), $rows);
    }

    /**
     * One flag per cost entry that turned earlier sales into losses.
     *
     * @return list<Flag>
     */
    private function belowCostLater(Period $p, bool $withCost): array
    {
        /** @var list<object{id: string, at: string, cost: int, n: int, low: int, high: int, name: string, first_sold: string, cashiers: string, cashier_id: string, terminals: string, sale_ids: string, revenue: int}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT r.cost_entry_id AS id, min(r.created_at) AS at, max(r.unit_cost_kobo) AS cost, count(*) AS n,
                   min(l.unit_price_kobo) AS low, max(l.unit_price_kobo) AS high, min(i.display_name) AS name, min(s.recorded_at) AS first_sold,
                   string_agg(DISTINCT u.name, ', ') AS cashiers, min(u.id) AS cashier_id, string_agg(DISTINCT t.name, ', ') AS terminals,
                   json_agg(s.id ORDER BY s.recorded_at)::text AS sale_ids, sum(l.line_total_kobo) AS revenue
            FROM cost_resolutions r JOIN sale_lines l ON l.id = r.sale_line_id JOIN sales s ON s.id = l.sale_id
            JOIN items i ON i.id = r.item_id JOIN users u ON u.id = s.cashier_id JOIN terminals t ON t.id = s.terminal_id
            WHERE s.kind IN ('sale', 'back_entry') AND l.unit_price_kobo < r.unit_cost_kobo AND r.created_at >= ? AND r.created_at < ?
              -- An undone cost raised no real loss; an undo only puts back what was there (D48).
              AND r.source <> 'undo' AND NOT EXISTS (SELECT 1 FROM cost_entries x WHERE x.undoes_id = r.cost_entry_id)
            GROUP BY r.cost_entry_id
            SQL, [$p->fromSql(), $p->toSql()]);

        return array_map(function (object $r) use ($withCost): array {
            $n = (int) $r->n;
            $price = (int) $r->low === (int) $r->high ? Money::naira((int) $r->low) : Money::naira((int) $r->low).' to '.Money::naira((int) $r->high);
            /** @var list<string> $sales */
            $sales = json_decode($r->sale_ids, true) ?: [];
            $detail = ($n === 1 ? '1 earlier sale of ' : "{$n} earlier sales of ").$r->name.' at '.$price.($withCost ? '; the cost entered is '.Money::naira((int) $r->cost) : '')
                .'; first sold on '.$this->date($r->first_sold);

            return $this->row('below_cost_later', $r->id, $r->at, $n === 1 ? 'An earlier sale is now below cost' : 'Earlier sales are now below cost', $detail,
                who: 'Sold by '.$r->cashiers, cashierId: str_contains($r->cashiers, ',') ? null : $r->cashier_id, terminal: $r->terminals,
                saleIds: array_values(array_unique($sales)), amount: (int) $r->revenue, cost: $withCost ? (int) $r->cost : null);
        }, $rows);
    }

    /**
     * @return list<Flag>
     */
    private function belowFloor(Period $p): array
    {
        return array_map(fn (object $r) => $this->row('below_floor', $r->id, $r->at, 'Below the floor, approved',
            self::qty((int) $r->qty).$r->name.' at '.Money::naira((int) $r->price).self::usual($r->low, $r->high),
            who: $r->cashier, cashierId: $r->cashier_id, approver: $r->approver, terminal: $r->terminal, saleIds: [$r->sale_id],
            receipt: $r->receipt_number, amount: (int) $r->price * (int) $r->qty), $this->priceLines($p, "l.flags->>'below_floor' = 'true'"));
    }

    /**
     * Prices outside the usual range that the cashier confirmed or retyped, and the ones over the shift's
     * confirm limit that a manager approved [S5].
     *
     * @return list<Flag>
     */
    private function unusualPrice(Period $p): array
    {
        $lines = $this->priceLines($p, "(l.flags->>'confirmed' = 'true' OR (l.flags->>'outside' = 'below' AND l.approval_id IS NOT NULL AND coalesce(l.flags->>'below_floor', '') <> 'true'))");

        return array_map(function (object $r): array {
            $title = match (true) {
                $r->retyped === 'true' => 'Very high price, retyped by the cashier',
                $r->approver !== null => 'Unusual price, approved by a manager',
                default => 'Unusual price, confirmed by the cashier',
            };

            return $this->row('unusual_price', $r->id, $r->at, $title, self::qty((int) $r->qty).$r->name.' at '.Money::naira((int) $r->price).self::usual($r->low, $r->high),
                who: $r->cashier, cashierId: $r->cashier_id, approver: $r->approver, terminal: $r->terminal, saleIds: [$r->sale_id],
                receipt: $r->receipt_number, amount: (int) $r->price * (int) $r->qty);
        }, $lines);
    }

    /**
     * @return list<object{id: string, name: string, qty: int, price: int, low: ?string, high: ?string, retyped: ?string, sale_id: string, receipt_number: string, at: string, cashier_id: string, cashier: string, terminal: string, approver: ?string}>
     */
    private function priceLines(Period $p, string $where): array
    {
        /** @var list<object{id: string, name: string, qty: int, price: int, low: ?string, high: ?string, retyped: ?string, sale_id: string, receipt_number: string, at: string, cashier_id: string, cashier: string, terminal: string, approver: ?string}> */
        return DB::select(<<<SQL
            SELECT l.id, l.name_snapshot AS name, l.qty, l.unit_price_kobo AS price, l.flags->'range'->>0 AS low, l.flags->'range'->>1 AS high,
                   l.flags->>'retyped' AS retyped, s.id AS sale_id, s.receipt_number, s.recorded_at AS at, u.id AS cashier_id, u.name AS cashier,
                   t.name AS terminal, m.name AS approver
            FROM sale_lines l JOIN sales s ON s.id = l.sale_id JOIN users u ON u.id = s.cashier_id JOIN terminals t ON t.id = s.terminal_id
            LEFT JOIN approvals a ON a.id = l.approval_id LEFT JOIN users m ON m.id = a.approver_id
            WHERE s.kind IN ('sale', 'back_entry') AND {$where} AND s.recorded_at >= ? AND s.recorded_at < ?
            SQL, [$p->fromSql(), $p->toSql()]);
    }

    /**
     * @return list<Flag>
     */
    private function noReference(Period $p): array
    {
        /** @var list<object{id: string, amount: int, alert_seen: ?bool, sale_id: string, receipt_number: string, at: string, cashier_id: string, cashier: string, terminal: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT pm.id, pm.amount_kobo AS amount, pm.alert_seen, s.id AS sale_id, s.receipt_number, s.recorded_at AS at,
                   u.id AS cashier_id, u.name AS cashier, t.name AS terminal
            FROM payments pm JOIN sales s ON s.id = pm.sale_id JOIN users u ON u.id = s.cashier_id JOIN terminals t ON t.id = s.terminal_id
            WHERE s.kind IN ('sale', 'back_entry') AND pm.method = 'transfer' AND coalesce(btrim(pm.reference), '') = ''
              AND s.recorded_at >= ? AND s.recorded_at < ?
            SQL, [$p->fromSql(), $p->toSql()]);

        return array_map(fn (object $r) => $this->row('no_reference', $r->id, $r->at, 'Transfer with no reference',
            Money::naira((int) $r->amount).($r->alert_seen === true ? '; the bank-alert tick was given' : ''),
            who: $r->cashier, cashierId: $r->cashier_id, terminal: $r->terminal, saleIds: [$r->sale_id], receipt: $r->receipt_number,
            amount: (int) $r->amount), $rows);
    }

    /**
     * Awaiting transfer over 30 minutes [S2]: finished or discarded after the wait, or still waiting now.
     *
     * @return list<Flag>
     */
    private function transferWaited(Period $p): array
    {
        /** @var list<object{id: string, amount: int, label: ?string, parked_at: string, status: string, cashier_id: string, cashier: string, terminal: string, ended_at: ?string, ended_how: ?string, finished_on: ?string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT ps.id, ps.amount_kobo AS amount, ps.label, ps.parked_at, ps.status, u.id AS cashier_id, u.name AS cashier, t.name AS terminal,
                   e.at AS ended_at, e.type AS ended_how, et.name AS finished_on
            FROM parked_sales ps JOIN users u ON u.id = ps.parked_by JOIN terminals t ON t.id = ps.terminal_id
            LEFT JOIN LATERAL (
                SELECT x.at, x.type, x.terminal_id FROM parked_sale_events x
                WHERE x.parked_sale_id = ps.id AND x.type IN ('transfer_confirmed', 'discarded') ORDER BY x.at LIMIT 1
            ) e ON true
            LEFT JOIN terminals et ON et.id = e.terminal_id
            WHERE ps.kind = 'awaiting_transfer' AND ps.parked_at < ? AND (e.at IS NULL OR e.at >= ?)
            SQL, [$p->toSql(), $p->fromSql()]);

        $limit = Parked::TRANSFER_FLAG_MINUTES;
        $now = CarbonImmutable::now();
        $flags = [];
        foreach ($rows as $r) {
            $parked = CarbonImmutable::parse($r->parked_at);
            $live = $r->ended_at === null && $r->status === 'open';
            if ($r->ended_at === null && ! $live) {
                continue; // resumed as a held cart elsewhere: no longer waiting here
            }
            $end = $r->ended_at === null ? $now : CarbonImmutable::parse($r->ended_at);
            $minutes = (int) floor($parked->diffInSeconds($end) / 60);
            if ($minutes <= $limit) {
                continue;
            }
            $at = $live ? $parked->addMinutes($limit) : $end;
            if (! $p->contains($at)) {
                continue;
            }
            $label = $r->label === null ? '' : ', labelled “'.$r->label.'”';
            $detail = match (true) {
                $live => Money::naira((int) $r->amount)." on {$r->terminal}{$label}; waiting {$minutes} minutes. Check the bank alert, then finish or discard it",
                $r->ended_how === 'discarded' => Money::naira((int) $r->amount)."{$label}; waited {$minutes} minutes, then discarded at ".$this->time($end),
                default => Money::naira((int) $r->amount)."{$label}; waited {$minutes} minutes, finished at ".$this->time($end).($r->finished_on === null ? '' : " on {$r->finished_on}"),
            };
            $flags[] = $this->row('transfer_waited', $r->id, $at->format('Y-m-d H:i:s.uP'), $live ? "Transfer waiting {$minutes} minutes" : 'Transfer waited over 30 minutes', $detail,
                who: $r->cashier, cashierId: $r->cashier_id, terminal: $r->terminal, amount: (int) $r->amount, live: $live);
        }

        return $flags;
    }

    /**
     * @return list<Flag>
     */
    private function discarded(Period $p): array
    {
        /** @var list<object{id: string, at: string, reason: ?string, amount: int, kind: string, label: ?string, actor_id: ?string, actor: ?string, terminal: ?string, approver: ?string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT e.id, e.at, e.reason, ps.amount_kobo AS amount, ps.kind, ps.label, u.id AS actor_id, u.name AS actor, t.name AS terminal, m.name AS approver
            FROM parked_sale_events e JOIN parked_sales ps ON ps.id = e.parked_sale_id
            LEFT JOIN users u ON u.id = e.actor_id LEFT JOIN terminals t ON t.id = e.terminal_id
            LEFT JOIN approvals a ON a.id = e.approval_id LEFT JOIN users m ON m.id = a.approver_id
            WHERE e.type = 'discarded' AND e.at >= ? AND e.at < ?
            SQL, [$p->fromSql(), $p->toSql()]);

        return array_map(fn (object $r) => $this->row('discarded', $r->id, $r->at, $r->kind === 'awaiting_transfer' ? 'Awaiting transfer discarded' : 'Parked sale discarded',
            Money::naira((int) $r->amount).($r->label === null ? '' : ', “'.$r->label.'”').'; reason: '.($r->reason ?? 'none given'),
            who: $r->actor, cashierId: $r->actor_id, approver: $r->approver, terminal: $r->terminal, amount: (int) $r->amount), $rows);
    }

    /**
     * Drawer openings with no sale, and with the key [F12, S21].
     *
     * @return list<Flag>
     */
    private function drawer(Period $p, string $kind): array
    {
        /** @var list<object{id: string, at: string, reason: ?string, sale_id: ?string, receipt_number: ?string, actor_id: ?string, actor: ?string, terminal: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT c.id, c.at, c.reason, c.sale_id, s.receipt_number, u.id AS actor_id, u.name AS actor, t.name AS terminal
            FROM cash_events c JOIN drawers d ON d.id = c.drawer_id JOIN terminals t ON t.id = d.terminal_id
            LEFT JOIN users u ON u.id = c.actor_id LEFT JOIN sales s ON s.id = c.sale_id
            WHERE c.type = ? AND c.at >= ? AND c.at < ?
            SQL, [$kind === 'no_sale' ? 'no_sale_open' : 'key_open', $p->fromSql(), $p->toSql()]);

        return array_map(fn (object $r) => $this->row($kind, $r->id, $r->at,
            $kind === 'no_sale' ? 'Drawer opened with no sale' : 'Drawer opened with the key',
            $kind === 'no_sale' ? 'Reason: '.($r->reason ?? 'none given') : ($r->sale_id === null ? 'Opened with the key, with no sale' : 'The drawer did not open for a cash sale'),
            who: $r->actor, cashierId: $r->actor_id, terminal: $r->terminal, saleIds: $r->sale_id === null ? [] : [$r->sale_id], receipt: $r->receipt_number), $rows);
    }

    /**
     * A shift left open 4 hours past closing time, closed as not counted [S12].
     *
     * @return list<Flag>
     */
    private function autoClosed(Period $p): array
    {
        /** @var list<object{id: string, at: string, business_day: string, drawer: string, terminal: string, opener_id: ?string, opener: ?string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT sh.id, sh.closed_at AS at, sh.business_day, d.label AS drawer, t.name AS terminal, u.id AS opener_id, u.name AS opener
            FROM shifts sh JOIN drawers d ON d.id = sh.drawer_id JOIN terminals t ON t.id = d.terminal_id LEFT JOIN users u ON u.id = sh.opened_by
            WHERE sh.status = 'auto_closed' AND sh.closed_at >= ? AND sh.closed_at < ?
            SQL, [$p->fromSql(), $p->toSql()]);

        return array_map(fn (object $r) => $this->row('auto_closed', $r->id, $r->at, 'Shift closed by itself, not counted',
            "The shift of {$this->date($r->business_day)} on {$r->drawer} was still open 4 hours after closing time",
            who: $r->opener, cashierId: $r->opener_id, terminal: $r->terminal), $rows);
    }

    /**
     * A sale lost to a restore and re-entered from a counter's journal, under its original receipt number
     * and time (spec section 9) [F1]. Listed when it was re-entered, since that is when it reached the books.
     *
     * @return list<Flag>
     */
    private function backEntries(Period $p): array
    {
        /** @var list<object{id: string, at: string, sold_at: string, sale_id: string, receipt_number: string, total: int, cashier_id: string, cashier: string, terminal: string, by: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT b.id, b.entered_at AS at, b.sold_at, s.id AS sale_id, s.receipt_number, s.total_kobo AS total,
                   u.id AS cashier_id, u.name AS cashier, t.name AS terminal, m.name AS by
            FROM back_entries b JOIN sales s ON s.id = b.sale_id JOIN users u ON u.id = s.cashier_id
            JOIN terminals t ON t.id = s.terminal_id JOIN users m ON m.id = b.entered_by
            WHERE b.entered_at >= ? AND b.entered_at < ?
            SQL, [$p->fromSql(), $p->toSql()]);

        return array_map(fn (object $r) => $this->row('back_entry', $r->id, $r->at, "{$r->receipt_number} re-entered after the restore",
            "Sold {$this->date($r->sold_at)} at {$this->time(CarbonImmutable::parse($r->sold_at))} for ".Money::naira((int) $r->total)." · re-entered by {$r->by}",
            who: $r->cashier, cashierId: $r->cashier_id, approver: $r->by, terminal: $r->terminal, saleIds: [$r->sale_id], receipt: $r->receipt_number, amount: (int) $r->total), $rows);
    }

    /**
     * An approval by a manager who had not signed in at this branch that business day [F8, S3].
     *
     * @return list<Flag>
     */
    private function approvalAway(Period $p): array
    {
        /** @var list<object{id: string, at: string, action: string, price: ?int, item: ?string, approver: string, requester_id: ?string, requester: ?string, terminal: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT a.id, a.bound_at AS at, a.action, a.price_kobo AS price, i.display_name AS item, m.name AS approver,
                   r.id AS requester_id, r.name AS requester, t.name AS terminal
            FROM approvals a JOIN users m ON m.id = a.approver_id LEFT JOIN users r ON r.id = a.requested_by
            JOIN terminals t ON t.id = a.terminal_id LEFT JOIN items i ON i.id = a.item_id
            WHERE a.flagged AND a.bound_at IS NOT NULL AND a.bound_at >= ? AND a.bound_at < ?
            SQL, [$p->fromSql(), $p->toSql()]);

        return array_map(function (object $r): array {
            $what = match ($r->action) {
                'price_below_floor' => 'a price below the floor',
                'below_range_confirm_limit' => 'a price below the usual range',
                'discard_parked_sale' => 'a discarded parked sale',
                default => str_replace('_', ' ', $r->action),
            };
            $detail = "{$r->approver} approved {$what}".($r->item === null ? '' : " for {$r->item}").($r->price === null ? '' : ' at '.Money::naira((int) $r->price))
                .', without signing in at this branch that day';

            return $this->row('approval_away', $r->id, $r->at, 'Approved by a manager not signed in', $detail,
                who: $r->requester, cashierId: $r->requester_id, approver: $r->approver, terminal: $r->terminal);
        }, $rows);
    }

    /**
     * @return list<Flag>
     */
    private function lockedOut(Period $p): array
    {
        /** @var list<object{id: string, at: string, until: ?string, user_id: ?string, person: ?string, terminal: ?string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT al.id, al.recorded_at AS at, al.redacted_after->>'until' AS until, u.id AS user_id, u.name AS person, t.name AS terminal
            FROM audit_log al LEFT JOIN users u ON u.id = al.subject_id LEFT JOIN terminals t ON t.id = al.terminal_id
            WHERE al.action = 'identity.locked_out' AND al.recorded_at >= ? AND al.recorded_at < ?
            SQL, [$p->fromSql(), $p->toSql()]);

        return array_map(fn (object $r) => $this->row('locked_out', $r->id, $r->at, ($r->person ?? 'Someone').' was locked out after wrong PINs',
            $r->until === null ? 'Too many wrong PINs' : 'Unlocks by itself at '.$this->time(CarbonImmutable::parse($r->until)),
            who: $r->person, cashierId: $r->user_id, terminal: $r->terminal), $rows);
    }

    /**
     * @param  list<string>  $saleIds
     * @return Flag
     */
    private function row(string $kind, string $id, string $at, string $title, string $detail, ?string $who = null, ?string $cashierId = null,
        ?string $approver = null, ?string $terminal = null, array $saleIds = [], ?string $receipt = null, ?int $amount = null, ?int $cost = null, bool $live = false): array
    {
        $row = [
            'key' => "{$kind}:{$id}", 'kind' => $kind, 'label' => self::KINDS[$kind] ?? $kind,
            'at' => CarbonImmutable::parse($at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'title' => $title, 'detail' => $detail, 'who' => $who, 'cashier_id' => $cashierId, 'approver' => $approver, 'terminal' => $terminal,
            'sale_ids' => $saleIds, 'receipt_number' => $receipt, 'amount_kobo' => $amount, 'live' => $live, 'seen_by' => null, 'seen_at' => null,
        ];
        if ($cost !== null) {
            $row['cost_kobo'] = $cost;
        }

        return $row;
    }

    /**
     * @param  list<Flag>  $rows
     * @return list<Flag>
     */
    private function withMarks(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $marks = [];
        foreach (array_chunk(array_column($rows, 'key'), 1000) as $keys) {
            /** @var list<object{flag_key: string, seen_at: string, name: string}> $found */
            $found = DB::table('flag_marks as f')->join('users as u', 'u.id', '=', 'f.seen_by')->whereIn('f.flag_key', $keys)
                ->orderBy('f.seen_at')->get(['f.flag_key', 'f.seen_at', 'u.name'])->all();
            foreach ($found as $m) {
                $marks[$m->flag_key] ??= $m;
            }
        }
        foreach ($rows as $i => $row) {
            $mark = $marks[$row['key']] ?? null;
            if ($mark !== null) {
                $rows[$i]['seen_by'] = $mark->name;
                $rows[$i]['seen_at'] = CarbonImmutable::parse($mark->seen_at)->utc()->format('Y-m-d\TH:i:s.u\Z');
            }
        }

        return $rows;
    }

    private static function qty(int $qty): string
    {
        return $qty > 1 ? "{$qty} × " : '';
    }

    private static function usual(?string $low, ?string $high): string
    {
        if ($low === null || $high === null) {
            return '';
        }

        return $low === $high ? '; usual '.Money::naira((int) $low) : '; usual '.Money::naira((int) $low).' to '.Money::naira((int) $high);
    }

    private function time(CarbonImmutable $at): string
    {
        return $at->setTimezone($this->zone())->format('H:i');
    }

    private function date(string $at): string
    {
        return CarbonImmutable::parse($at)->setTimezone($this->zone())->format('j M');
    }
}
