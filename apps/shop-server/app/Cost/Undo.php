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
 * Undoing a cost (spec section 2: every cost backfill is reversible) [F14, D48]. A mistyped cost, ₦1,200
 * for ₦12,000, would otherwise leave its estimates wrong for good, since a line with an estimate is not
 * offered again (D40). The undo writes new rows only: a cost entry that puts back the item's cost before it
 * (or none) for later sales, and for each earlier sale it estimated, an estimate that puts back the one
 * before (or none), so the sale waits for a cost again. Sales made after the cost was entered keep the cost
 * they were sold with, as every finished sale does. Only an item's newest cost still in force can be undone,
 * so two mistakes are undone newest first.
 */
final readonly class Undo
{
    /** A SQL expression: a sale line l's estimate now, the newest one, or NULL for none. */
    public const ESTIMATE = '(SELECT r.unit_cost_kobo FROM cost_resolutions r WHERE r.sale_line_id = l.id ORDER BY r.created_at DESC, r.id DESC LIMIT 1)';

    public function __construct(private AuditLog $audit) {}

    /**
     * What undoing this entry would do.
     *
     * @return array{entry_id: string, item_id: string, name: string, cost_kobo: int, entered_at: string, by: ?string, estimates: int, back_to_waiting: int, sold_since: int, cost_after_kobo: ?int}
     */
    public function preview(string $entryId): array
    {
        $entry = $this->undoable($entryId);
        $lines = $this->estimated($entryId);

        return [
            'entry_id' => $entryId, 'item_id' => $entry->item_id, 'name' => $entry->name, 'cost_kobo' => (int) $entry->unit_cost_kobo,
            'entered_at' => $entry->effective_from, 'by' => $entry->by,
            'estimates' => count($lines),
            'back_to_waiting' => count(array_filter($lines, fn (object $l) => $l->previous === null)),
            'sold_since' => $this->soldSince($entry),
            'cost_after_kobo' => $this->previousCost($entry),
        ];
    }

    /**
     * @return array{estimates: int, back_to_waiting: int}
     */
    public function apply(string $entryId, User $actor): array
    {
        return DB::transaction(function () use ($entryId, $actor): array {
            $itemId = DB::table('cost_entries')->where('id', $entryId)->value('item_id');
            if (! is_string($itemId)) {
                throw new InvalidArgumentException('That cost is not in the records.');
            }
            // The same lock as entering a cost, so an undo and a new cost never cross.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['cost:'.$itemId]);
            $entry = $this->undoable($entryId);
            $lines = $this->estimated($entryId);
            $previous = $this->previousCost($entry);
            $now = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');

            $undoId = Ulid::generate();
            // A record of the undo: the cost in force skips the undone entry and the undo alike (Costs::current).
            DB::table('cost_entries')->insert(['id' => $undoId, 'item_id' => $itemId, 'unit_cost_kobo' => $previous,
                'effective_from' => $now, 'entered_by' => $actor->id, 'undoes_id' => $entryId]);
            foreach ($lines as $l) {
                Costs::resolve([
                    'id' => Ulid::generate(), 'sale_line_id' => $l->id, 'item_id' => $itemId, 'unit_cost_kobo' => $l->previous,
                    'source' => 'undo', 'basis' => 'undo', 'cost_entry_id' => $undoId, 'resolved_by' => $actor->id, 'created_at' => $now,
                ]);
            }
            $back = count(array_filter($lines, fn (object $l) => $l->previous === null));
            $this->audit->record(new AuditEntry(action: 'cost.undone', actorId: $actor->id, subjectType: 'item', subjectId: $itemId,
                after: ['change' => 'cost undone', 'earlier_sales_put_back' => count($lines), 'waiting_for_a_cost_again' => $back],
                sensitiveBefore: ['unit_cost_kobo' => (int) $entry->unit_cost_kobo], sensitiveAfter: ['unit_cost_kobo' => $previous]));

            return ['estimates' => count($lines), 'back_to_waiting' => $back];
        });
    }

    /**
     * The costs entered lately, newest first, each with whether it can still be undone.
     *
     * @return list<array{entry_id: string, item_id: string, name: string, cost_kobo: ?int, entered_at: string, by: ?string, undone: bool, undo: bool, is_undo: bool}>
     */
    public static function recent(int $days = 30): array
    {
        $since = CarbonImmutable::now()->subDays($days)->format('Y-m-d H:i:s.uP');
        /** @var list<object{id: string, item_id: string, name: string, unit_cost_kobo: int|string|null, effective_from: string, by: ?string, undoes_id: ?string, undone: bool, newest: bool}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT c.id, c.item_id, i.display_name AS name, c.unit_cost_kobo, c.effective_from, u.name AS by, c.undoes_id,
                   EXISTS (SELECT 1 FROM cost_entries x WHERE x.undoes_id = c.id) AS undone,
                   NOT EXISTS (SELECT 1 FROM cost_entries n WHERE n.item_id = c.item_id AND (n.effective_from, n.id) > (c.effective_from, c.id)
                               AND n.undoes_id IS NULL AND NOT EXISTS (SELECT 1 FROM cost_entries x WHERE x.undoes_id = n.id)) AS newest
            FROM cost_entries c JOIN items i ON i.id = c.item_id LEFT JOIN users u ON u.id = c.entered_by
            WHERE c.effective_from >= ? ORDER BY c.effective_from DESC, c.id DESC LIMIT 50
            SQL, [$since]);

        return array_map(fn (object $r) => [
            'entry_id' => $r->id, 'item_id' => $r->item_id, 'name' => $r->name,
            'cost_kobo' => $r->unit_cost_kobo === null ? null : (int) $r->unit_cost_kobo, 'entered_at' => $r->effective_from, 'by' => $r->by,
            'undone' => (bool) $r->undone, 'is_undo' => $r->undoes_id !== null,
            'undo' => (bool) $r->newest && ! (bool) $r->undone && $r->undoes_id === null && $r->unit_cost_kobo !== null,
        ], $rows);
    }

    /**
     * @return object{item_id: string, name: string, unit_cost_kobo: int|string, effective_from: string, by: ?string}
     */
    private function undoable(string $entryId): object
    {
        /** @var object{id: string, item_id: string, name: string, unit_cost_kobo: int|string|null, effective_from: string, by: ?string, undoes_id: ?string}|null $entry */
        $entry = DB::table('cost_entries as c')->join('items as i', 'i.id', '=', 'c.item_id')->leftJoin('users as u', 'u.id', '=', 'c.entered_by')
            ->where('c.id', $entryId)->first(['c.id', 'c.item_id', 'i.display_name as name', 'c.unit_cost_kobo', 'c.effective_from', 'u.name as by', 'c.undoes_id']);
        if ($entry === null) {
            throw new InvalidArgumentException('That cost is not in the records.');
        }
        if ($entry->undoes_id !== null || $entry->unit_cost_kobo === null) {
            throw new InvalidArgumentException('That is an undo already. To set a cost again, enter it.');
        }
        if (DB::table('cost_entries')->where('undoes_id', $entryId)->exists()) {
            throw new InvalidArgumentException('That cost was undone already.');
        }
        // A newer cost still in force: not an undo, and not undone.
        $newer = DB::table('cost_entries as n')->where('n.item_id', $entry->item_id)->where('n.id', '!=', $entryId)
            ->whereRaw('(n.effective_from, n.id) > (?::timestamptz, ?)', [$entry->effective_from, $entryId])->whereNull('n.undoes_id')
            ->whereNotExists(fn ($q) => $q->from('cost_entries as x')->whereColumn('x.undoes_id', 'n.id'))->exists();
        if ($newer) {
            throw new InvalidArgumentException("A newer cost of {$entry->name} was entered since. Only the newest cost can be undone.");
        }

        /** @var object{item_id: string, name: string, unit_cost_kobo: int|string, effective_from: string, by: ?string} */
        return $entry;
    }

    /**
     * The lines this entry estimated whose estimate is still its own, with the estimate before it (or none).
     *
     * @return list<object{id: string, previous: ?int}>
     */
    private function estimated(string $entryId): array
    {
        /** @var list<object{id: string, previous: int|string|null}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT r.sale_line_id AS id,
                   (SELECT p.unit_cost_kobo FROM cost_resolutions p WHERE p.sale_line_id = r.sale_line_id AND (p.created_at, p.id) < (r.created_at, r.id)
                    ORDER BY p.created_at DESC, p.id DESC LIMIT 1) AS previous
            FROM cost_resolutions r
            WHERE r.cost_entry_id = ?
              AND NOT EXISTS (SELECT 1 FROM cost_resolutions n WHERE n.sale_line_id = r.sale_line_id AND (n.created_at, n.id) > (r.created_at, r.id))
            SQL, [$entryId]);

        return array_map(fn (object $r) => (object) ['id' => $r->id, 'previous' => $r->previous === null ? null : (int) $r->previous], $rows);
    }

    /**
     * The item's cost before this entry, which later sales take again, or none.
     *
     * @param  object{item_id: string, effective_from: string}  $entry
     */
    private function previousCost(object $entry): ?int
    {
        $cost = Costs::inForce($entry->item_id)->where('effective_from', '<', $entry->effective_from)
            ->orderByDesc('effective_from')->orderByDesc('id')->value('unit_cost_kobo');

        return is_numeric($cost) ? (int) $cost : null;
    }

    /**
     * Sales of the item made since the entry, which took its cost at sale and keep it.
     *
     * @param  object{item_id: string, unit_cost_kobo: int|string, effective_from: string}  $entry
     */
    private function soldSince(object $entry): int
    {
        $roots = Merges::rootsCte();
        /** @var object{n: int|string} $row */
        $row = DB::selectOne(<<<SQL
            WITH RECURSIVE {$roots}
            SELECT count(*) AS n FROM sale_lines l JOIN sales s ON s.id = l.sale_id JOIN item_roots r ON r.item_id = l.item_id
            WHERE r.root_id = ? AND s.recorded_at >= ? AND l.cost_snapshot_kobo = ?
            SQL, [$entry->item_id, $entry->effective_from, (int) $entry->unit_cost_kobo]);

        return (int) $row->n;
    }
}
