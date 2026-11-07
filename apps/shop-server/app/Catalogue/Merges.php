<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Catalogue\Models\Item;
use App\Identity\Models\User;
use App\Platform\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Merging duplicate items, and the undo (spec sections 5, 6 and 14 C) [F18]. A merge changes no sale:
 * the merged item points at the one it now counts under, receipts keep what they printed, and reports
 * follow the pointer. An item can only be merged into one that is not merged itself, so a merge can never
 * make a cycle. An undo restores the item as it was, and is a new row in merge_events, never an edit.
 */
final readonly class Merges
{
    public function __construct(private AuditLog $audit) {}

    /** Merges one item into another; returns the merge's ID. */
    public function merge(string $fromId, string $intoId, User $actor): string
    {
        if ($fromId === $intoId) {
            throw new MergeRefused('An item cannot be merged into itself.');
        }

        return DB::transaction(function () use ($fromId, $intoId, $actor): string {
            // Both rows are locked in a fixed order, so two opposite merges at once cannot deadlock.
            $locked = Item::query()->whereIn('id', [$fromId, $intoId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $locked->get($fromId);
            $into = $locked->get($intoId);
            if (! $from instanceof Item || ! $into instanceof Item) {
                throw new MergeRefused('One of these items is not in the catalogue.');
            }
            if ($from->status === 'merged') {
                throw new MergeRefused("{$from->display_name} is already merged into another item.");
            }
            if ($into->status === 'merged') {
                // Merging into a merged item could close a loop; the one it counts under is the right target.
                throw new MergeRefused("{$into->display_name} is itself merged into another item, so merging into it would make a loop. Merge into the item it counts under.");
            }
            if ($into->status === 'archived') {
                throw new MergeRefused("{$into->display_name} is archived. Choose an item that is still sold.");
            }

            $previous = $from->status;
            $from->update(['status' => 'merged', 'merged_into_id' => $into->id]);
            $id = Ulid::generate();
            DB::table('merge_events')->insert([
                'id' => $id, 'kind' => 'merge', 'item_id' => $from->id, 'into_id' => $into->id, 'previous_status' => $previous,
                'actor_id' => $actor->id, 'at' => CarbonImmutable::now()->format('Y-m-d H:i:s.uP'),
            ]);
            $this->audit->record(new AuditEntry(action: 'catalogue.item_merged', actorId: $actor->id, subjectType: 'item', subjectId: $from->id,
                before: ['name' => $from->display_name, 'status' => $previous], after: ['into' => $into->id, 'into_name' => $into->display_name, 'merge_id' => $id]));

            return $id;
        });
    }

    /** Undoes a merge: the item is sold on its own again, with its history back. */
    public function undo(string $mergeId, User $actor): void
    {
        DB::transaction(function () use ($mergeId, $actor): void {
            /** @var object{id: string, item_id: string, into_id: string, previous_status: ?string}|null $merge */
            $merge = DB::table('merge_events')->where('id', $mergeId)->where('kind', 'merge')->first(['id', 'item_id', 'into_id', 'previous_status']);
            if ($merge === null) {
                throw new MergeRefused('That merge is not known.');
            }
            // merge_events is append-only, so the item's row is the lock: two undos of one merge queue here.
            $item = Item::query()->whereKey($merge->item_id)->lockForUpdate()->first();
            if (DB::table('merge_events')->where('undoes_id', $mergeId)->exists()) {
                throw new MergeRefused('That merge was already undone.');
            }
            if ($item === null || $item->status !== 'merged' || $item->merged_into_id !== $merge->into_id) {
                throw new MergeRefused('That item has changed since the merge, so it cannot be undone from here.');
            }
            $status = $merge->previous_status ?? 'active';
            $item->update(['status' => $status, 'merged_into_id' => null]);
            DB::table('merge_events')->insert([
                'id' => Ulid::generate(), 'kind' => 'undo', 'item_id' => $merge->item_id, 'into_id' => $merge->into_id, 'previous_status' => 'merged',
                'undoes_id' => $mergeId, 'actor_id' => $actor->id, 'at' => CarbonImmutable::now()->format('Y-m-d H:i:s.uP'),
            ]);
            $this->audit->record(new AuditEntry(action: 'catalogue.item_merge_undone', actorId: $actor->id, subjectType: 'item', subjectId: $merge->item_id,
                before: ['status' => 'merged', 'into' => $merge->into_id], after: ['status' => $status, 'merge_id' => $mergeId]));
        });
    }

    /** Records that two suggested duplicates are different items, so they are not suggested again. */
    public function notSame(string $itemId, string $otherId, User $actor): void
    {
        if ($itemId === $otherId) {
            throw new MergeRefused('Choose two different items.');
        }
        [$a, $b] = $itemId < $otherId ? [$itemId, $otherId] : [$otherId, $itemId];
        DB::transaction(function () use ($a, $b, $actor): void {
            DB::table('merge_events')->insert([
                'id' => Ulid::generate(), 'kind' => 'not_same', 'item_id' => $a, 'into_id' => $b,
                'actor_id' => $actor->id, 'at' => CarbonImmutable::now()->format('Y-m-d H:i:s.uP'),
            ]);
            $this->audit->record(new AuditEntry(action: 'catalogue.items_not_same', actorId: $actor->id, subjectType: 'item', subjectId: $a, after: ['other' => $b]));
        });
    }

    /**
     * Merges not yet undone, newest first, with how many sales now count together.
     *
     * @return list<array{id: string, item_id: string, name: string, into_id: string, into_name: string, by: string, at: string, sales: int}>
     */
    public function recent(int $limit = 10): array
    {
        /** @var list<object{id: string, item_id: string, name: string, into_id: string, into_name: string, by: string, at: string, sales: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT m.id, m.item_id, f.display_name AS name, m.into_id, t.display_name AS into_name, u.name AS by, m.at,
                   (SELECT count(*) FROM sale_lines l WHERE l.item_id IN (m.item_id, m.into_id)) AS sales
            FROM merge_events m JOIN items f ON f.id = m.item_id JOIN items t ON t.id = m.into_id JOIN users u ON u.id = m.actor_id
            WHERE m.kind = 'merge' AND f.status = 'merged' AND f.merged_into_id = m.into_id
              AND NOT EXISTS (SELECT 1 FROM merge_events x WHERE x.undoes_id = m.id)
            ORDER BY m.at DESC LIMIT ?
            SQL, [$limit]);

        return array_map(fn (object $r) => ['id' => $r->id, 'item_id' => $r->item_id, 'name' => $r->name, 'into_id' => $r->into_id, 'into_name' => $r->into_name,
            'by' => $r->by, 'at' => $r->at, 'sales' => (int) $r->sales], $rows);
    }

    /** The item an item now counts under: itself, or the end of its merge pointers. */
    public static function rootOf(string $itemId): string
    {
        $seen = [];
        $id = $itemId;
        while (! isset($seen[$id])) {
            $seen[$id] = true;
            /** @var object{status: string, merged_into_id: ?string}|null $row */
            $row = DB::table('items')->where('id', $id)->first(['status', 'merged_into_id']);
            if ($row === null || $row->status !== 'merged' || $row->merged_into_id === null) {
                return $id;
            }
            $id = $row->merged_into_id;
        }

        return $id;
    }

    /**
     * A common table expression, item_roots(item_id, root_id), mapping every item to the one it counts
     * under, for reports that combine history through the pointer. Use it as "WITH RECURSIVE <this>".
     */
    public static function rootsCte(): string
    {
        return <<<'SQL'
            item_roots(item_id, root_id, depth) AS (
                SELECT id, id, 0 FROM items WHERE status <> 'merged' OR merged_into_id IS NULL
                UNION ALL
                SELECT i.id, r.root_id, r.depth + 1 FROM items i JOIN item_roots r ON i.merged_into_id = r.item_id
                WHERE i.status = 'merged' AND r.depth < 20
            )
            SQL;
    }
}
