<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Platform\Ulid;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the counter learns from selling (spec section 6) [F13]. A barcode links to the item a cashier picks
 * after an unknown scan; the link works at once but is unconfirmed until two further sales choose the
 * same item, and is flagged as conflicted if a different cashier picks a different item for that code.
 * Junk codes never learn. A search term that led to an item becomes an active alias after the same term
 * has led to the same item three times (a setting); terms under 3 characters are never stored.
 */
final readonly class Learning
{
    public function __construct(private Settings $settings, private AuditLog $audit) {}

    public static function isJunk(string $code): bool
    {
        $code = trim($code);
        if (mb_strlen($code) < 6) {
            return true;
        }
        if (preg_match('/^(.)\1+$/u', $code) === 1) {
            return true; // 000000, 111111 and the like
        }
        if (DB::table('barcode_ignore')->where('code', $code)->exists()) {
            return true;
        }

        return DB::table('barcodes')->where('code', $code)->where('status', '!=', 'disabled')->count() > 5;
    }

    /** Links a scanned code to the item the cashier picked. Returns false for a junk code, which never learns. */
    public function linkBarcode(string $code, string $itemId, ?string $userId): bool
    {
        $code = trim($code);
        if (self::isJunk($code)) {
            return false;
        }

        return DB::transaction(function () use ($code, $itemId, $userId): bool {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['barcode:'.$code]);
            $existing = DB::table('barcodes')->where('code', $code)->where('item_id', $itemId)->first();
            $now = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');
            if ($existing === null) {
                DB::table('barcodes')->insert([
                    'id' => Ulid::generate(), 'code' => $code, 'item_id' => $itemId, 'learned_at' => $now,
                    'learned_by' => $userId, 'status' => 'unconfirmed', 'confirmations' => 0, 'last_chosen_at' => $now,
                ]);
                $this->audit->record(new AuditEntry(action: 'catalogue.barcode_learned', actorId: $userId, subjectType: 'item', subjectId: $itemId, after: ['code' => $code]));
                $this->flagConflict($code, $itemId, $userId);
            } elseif ($existing->status === 'disabled') {
                // The cashier taught an undone link again: it starts over, unconfirmed.
                DB::table('barcodes')->where('id', $existing->id)->update(['status' => 'unconfirmed', 'confirmations' => 0, 'learned_by' => $userId, 'learned_at' => $now, 'last_chosen_at' => $now]);
            } else {
                DB::table('barcodes')->where('id', $existing->id)->update(['last_chosen_at' => $now]);
            }

            return true;
        });
    }

    /**
     * After a sale: a line that came from a scan confirms its link; a different item picked for the same
     * code by another cashier flags the code as conflicted.
     */
    public function recordScan(string $code, string $itemId, ?string $cashierId): void
    {
        DB::transaction(function () use ($code, $itemId, $cashierId): void {
            $now = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');
            $link = DB::table('barcodes')->where('code', $code)->where('item_id', $itemId)->lockForUpdate()->first();
            if ($link === null) {
                return;
            }
            $confirmations = (int) $link->confirmations + 1;
            DB::table('barcodes')->where('id', $link->id)->update([
                'confirmations' => $confirmations, 'last_chosen_at' => $now,
                'status' => $link->status === 'unconfirmed' && $confirmations >= 2 ? 'confirmed' : $link->status,
            ]);

            // A confirmed link (or one kept in review) no longer raises a conflict by being chosen again.
            if ($link->status === 'unconfirmed') {
                $this->flagConflict($code, $itemId, $cashierId);
            }
        });
    }

    /**
     * The candidates for a scanned code: at most 5, most recently chosen first (spec section 6).
     *
     * @return list<array{item_id: string, name: string, status: string}>
     */
    public function candidates(string $code): array
    {
        /** @var list<object{item_id: string, item_status: string, status: string}> $rows */
        $rows = DB::table('barcodes')->join('items', 'items.id', '=', 'barcodes.item_id')
            ->where('barcodes.code', trim($code))->where('barcodes.status', '!=', 'disabled')->where('items.status', '!=', 'archived')
            ->orderByDesc('barcodes.last_chosen_at')
            ->get(['barcodes.item_id', 'items.status as item_status', 'barcodes.status'])->all();

        // A merged item's code adds the item it now counts under [F18].
        $found = [];
        foreach ($rows as $r) {
            $id = $r->item_status === 'merged' ? Merges::rootOf($r->item_id) : $r->item_id;
            $found[$id] ??= $r->status;
        }
        /** @var array<string, object{id: string, display_name: string, status: string}> $items */
        $items = DB::table('items')->whereIn('id', array_keys($found))->whereNotIn('status', ['merged', 'archived'])->get(['id', 'display_name', 'status'])->keyBy('id')->all();
        $candidates = [];
        foreach ($found as $id => $status) {
            if (isset($items[$id])) {
                $candidates[] = ['item_id' => (string) $id, 'name' => $items[$id]->display_name, 'status' => $status];
            }
        }

        return array_slice($candidates, 0, 5);
    }

    /** Undoes a learned link; no sale changes [F13]. */
    public function undo(string $barcodeId, string $actorId): void
    {
        $link = DB::table('barcodes')->where('id', $barcodeId)->first();
        if ($link === null || $link->status === 'disabled') {
            return;
        }
        DB::table('barcodes')->where('id', $barcodeId)->update(['status' => 'disabled']);
        $this->audit->record(new AuditEntry(action: 'catalogue.barcode_undone', actorId: $actorId, subjectType: 'item', subjectId: (string) $link->item_id, before: ['code' => $link->code, 'status' => $link->status]));
    }

    /**
     * Settles a conflicted code in review (wireframe ReviewBarcodes): keep one item's link, and the others
     * are undone; or keep them all, and the counter asks which one each time. No sale changes.
     */
    public function settleConflict(string $code, ?string $keepItemId, string $actorId): void
    {
        DB::transaction(function () use ($code, $keepItemId, $actorId): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['barcode:'.$code]);
            /** @var list<object{id: string, item_id: string}> $links */
            $links = DB::table('barcodes')->where('code', $code)->where('status', '!=', 'disabled')->lockForUpdate()->get(['id', 'item_id'])->all();
            if ($links === []) {
                throw new MergeRefused('That barcode has no links left.');
            }
            if ($keepItemId !== null && ! in_array($keepItemId, array_column($links, 'item_id'), true)) {
                throw new MergeRefused('That item is not one of this barcode\'s links.');
            }
            $undone = 0;
            foreach ($links as $link) {
                if ($keepItemId !== null && $link->item_id !== $keepItemId) {
                    DB::table('barcodes')->where('id', $link->id)->update(['status' => 'disabled', 'conflicted' => false]);
                    $undone++;
                } else {
                    DB::table('barcodes')->where('id', $link->id)->update(['status' => 'confirmed', 'conflicted' => false]);
                }
            }
            $this->audit->record(new AuditEntry(action: 'catalogue.barcode_conflict_settled', actorId: $actorId, subjectType: 'item', subjectId: $keepItemId,
                after: ['code' => $code, 'kept' => $keepItemId === null ? 'all' : 'one', 'links_undone' => $undone]));
        });
    }

    /** Removes a search word that leads to the wrong item; it never comes back by itself [F13]. */
    public function removeAlias(string $aliasId, string $actorId): void
    {
        /** @var object{id: string, item_id: string, term: string, status: string}|null $alias */
        $alias = DB::table('aliases')->where('id', $aliasId)->first(['id', 'item_id', 'term', 'status']);
        if ($alias === null || $alias->status === 'removed') {
            return;
        }
        DB::transaction(function () use ($alias, $actorId): void {
            DB::table('aliases')->where('id', $alias->id)->update(['status' => 'removed']);
            $this->audit->record(new AuditEntry(action: 'catalogue.alias_removed', actorId: $actorId, subjectType: 'item', subjectId: $alias->item_id,
                before: ['term' => $alias->term, 'status' => $alias->status]));
        });
    }

    /** A different cashier choosing a different item for the same code makes the code conflicted. */
    private function flagConflict(string $code, string $itemId, ?string $userId): void
    {
        $others = DB::table('barcodes')->where('code', $code)->where('item_id', '!=', $itemId)->where('status', '!=', 'disabled')
            ->where(fn ($q) => $q->whereNull('learned_by')->orWhere('learned_by', '!=', $userId))->exists();
        if ($others && $userId !== null) {
            DB::table('barcodes')->where('code', $code)->where('status', '!=', 'disabled')->update(['conflicted' => true]);
        }
    }

    /** After a sale: the term the cashier searched to find the item counts towards an alias. */
    public function recordSearch(string $term, string $itemId): void
    {
        $term = NameBuilder::normalise($term);
        if (mb_strlen($term) < 3 || mb_strlen($term) > 60) {
            return;
        }
        $activation = $this->settings->get('catalogue.alias_activation');
        $activation = is_int($activation) ? $activation : 3;

        DB::transaction(function () use ($term, $itemId, $activation): void {
            $alias = DB::table('aliases')->where('item_id', $itemId)->where('term', $term)->lockForUpdate()->first();
            if ($alias !== null && $alias->status === 'removed') {
                return; // removed in review: it never learns again
            }
            if ($alias === null) {
                DB::table('aliases')->insert(['id' => Ulid::generate(), 'item_id' => $itemId, 'term' => $term, 'hits' => 1, 'status' => $activation <= 1 ? 'active' : 'candidate']);

                return;
            }
            $hits = (int) $alias->hits + 1;
            DB::table('aliases')->where('id', $alias->id)->update(['hits' => $hits, 'status' => $hits >= $activation ? 'active' : 'candidate']);
        });
    }
}
