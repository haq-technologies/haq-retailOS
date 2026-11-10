<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * What cashiers added while selling, waiting for a manager, the storekeeper or the owner (spec sections 3
 * and 6) [F13, C2, v1.3; wireframes ReviewNames, ReviewNewItems, ReviewBarcodes and MergeItems]: brands,
 * phone models and product words typed at the counter; new items and typed lines; learned barcodes,
 * conflicts and search words; and items that look like duplicates. Nothing here held up a sale.
 */
final readonly class ReviewQueue
{
    /** How alike two names must be (pg_trgm similarity) to be offered as possible duplicates. */
    private const DUPLICATE_SIMILARITY = 0.55;

    public function __construct(private Settings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $brands = $this->brands();
        $models = $this->models();
        $words = $this->words();
        $items = $this->newItems();
        $learned = $this->learned();
        $conflicts = $this->conflicts();
        $duplicates = $this->duplicates();

        return [
            'counts' => [
                'names' => count($brands) + count($models) + count($words),
                'items' => count($items),
                'barcodes' => count($learned) + count($conflicts),
                'duplicates' => count($duplicates),
            ],
            'brands' => $brands, 'models' => $models, 'words' => $words, 'items' => $items,
            'learned' => $learned, 'conflicts' => $conflicts, 'aliases' => $this->aliases(),
            'duplicates' => $duplicates, 'merges' => app(Merges::class)->recent(),
        ];
    }

    /** How many things wait in review, for the menu's badge. */
    public function total(): int
    {
        /** @var array{names: int, items: int, barcodes: int, duplicates: int} $counts */
        $counts = $this->all()['counts'];

        return array_sum($counts);
    }

    /**
     * @return list<array{id: string, name: string, typed_by: ?string, sales: int, items: string, first_sold: ?string, looks_like: ?array{id: string, name: string}}>
     */
    private function brands(): array
    {
        /** @var list<object{id: string, name: string, typed_by: ?string, sales: int|string, items: ?string, first_sold: ?string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT b.id, b.name, u.name AS typed_by,
                   (SELECT count(*) FROM sale_lines l JOIN items i ON i.id = l.item_id WHERE i.brand_id = b.id AND l.qty > 0) AS sales,
                   (SELECT string_agg(i.display_name, ', ' ORDER BY i.display_name) FROM items i WHERE i.brand_id = b.id AND i.status <> 'merged') AS items,
                   (SELECT min(s.recorded_at) FROM sale_lines l JOIN sales s ON s.id = l.sale_id JOIN items i ON i.id = l.item_id WHERE i.brand_id = b.id) AS first_sold
            FROM brands b LEFT JOIN users u ON u.id = b.created_by
            WHERE b.status = 'pending'
            ORDER BY b.created_at
            SQL);
        /** @var list<object{id: string, name: string}> $listed */
        $listed = DB::table('brands')->where('status', 'approved')->get(['id', 'name'])->all();

        return array_map(fn (object $r) => [
            'id' => $r->id, 'name' => $r->name, 'typed_by' => $r->typed_by, 'sales' => (int) $r->sales, 'items' => $r->items ?? '',
            'first_sold' => $r->first_sold, 'looks_like' => self::closest($r->name, $listed),
        ], $rows);
    }

    /**
     * @return list<array{id: string, name: string, list: string, family: ?string, typed_by: ?string, sales: int, items: string, looks_like: ?array{id: string, name: string}}>
     */
    private function models(): array
    {
        /** @var list<object{id: string, name: string, list: string, family: ?string, typed_by: ?string, sales: int|string, items: ?string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT v.id, v.name, v.list, f.name AS family,
                   (SELECT u.name FROM audit_log al JOIN users u ON u.id = al.actor_id WHERE al.action = 'catalogue.phone_model_typed' AND al.subject_id = v.id LIMIT 1) AS typed_by,
                   (SELECT count(*) FROM sale_lines l JOIN item_facets x ON x.item_id = l.item_id WHERE x.picklist_value_id = v.id AND l.qty > 0) AS sales,
                   (SELECT string_agg(i.display_name, ', ' ORDER BY i.display_name) FROM items i JOIN item_facets x ON x.item_id = i.id WHERE x.picklist_value_id = v.id AND i.status <> 'merged') AS items
            FROM picklist_values v LEFT JOIN picklist_values f ON f.id = v.parent_id
            WHERE v.status = 'pending'
            ORDER BY v.created_at
            SQL);
        $lists = [];

        return array_map(function (object $r) use (&$lists): array {
            /** @var list<object{id: string, name: string}> $listed */
            $listed = $lists[$r->list] ??= DB::table('picklist_values')->where('list', $r->list)->where('status', 'active')->get(['id', 'name'])->all();

            return [
                'id' => $r->id, 'name' => $r->name, 'list' => $r->list, 'family' => $r->family, 'typed_by' => $r->typed_by,
                'sales' => (int) $r->sales, 'items' => $r->items ?? '', 'looks_like' => self::closest($r->name, $listed),
            ];
        }, $rows);
    }

    /**
     * The listed name a typed one most likely means: a few letters apart (Oriamo and Oraimo, Hot60i and Hot
     * 60i), compared without case, spaces or dashes.
     *
     * @param  list<object{id: string, name: string}>  $listed
     * @return array{id: string, name: string}|null
     */
    private static function closest(string $typed, array $listed): ?array
    {
        $plain = fn (string $s) => preg_replace('/[\s\-_.]+/u', '', mb_strtolower($s)) ?? '';
        $mine = $plain($typed);
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($listed as $candidate) {
            $theirs = $plain($candidate->name);
            if ($theirs === '' || $mine === '') {
                continue;
            }
            $distance = levenshtein($mine, $theirs);
            // Two swapped letters are two edits; short names allow one, so "Itel" never suggests "Ugel".
            $longest = max(mb_strlen($mine), mb_strlen($theirs));
            $allowed = $longest <= 4 ? 1 : max(2, intdiv($longest, 4));
            if ($distance <= $allowed && $distance < $bestScore) {
                $best = ['id' => $candidate->id, 'name' => $candidate->name];
                $bestScore = $distance;
            }
        }

        return $best;
    }

    /**
     * Product words typed under "Not in the list", one row per word.
     *
     * @return list<array{word: string, item_ids: list<string>, names: string, typed_by: ?string, sales: int, low_kobo: ?int, high_kobo: ?int}>
     */
    private function words(): array
    {
        /** @var list<object{word: string, item_ids: string, names: string, typed_by: ?string, sales: int|string, low: ?int, high: ?int}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT min(i.typed_category_word) AS word, json_agg(i.id ORDER BY i.created_at)::text AS item_ids,
                   string_agg(i.display_name, ', ' ORDER BY i.display_name) AS names, string_agg(DISTINCT u.name, ', ') AS typed_by,
                   (SELECT count(*) FROM sale_lines l JOIN items j ON j.id = l.item_id WHERE l.qty > 0 AND j.status = 'pending_category' AND lower(j.typed_category_word) = lower(min(i.typed_category_word))) AS sales,
                   (SELECT min(l.unit_price_kobo) FROM sale_lines l JOIN items j ON j.id = l.item_id WHERE j.status = 'pending_category' AND lower(j.typed_category_word) = lower(min(i.typed_category_word))) AS low,
                   (SELECT max(l.unit_price_kobo) FROM sale_lines l JOIN items j ON j.id = l.item_id WHERE j.status = 'pending_category' AND lower(j.typed_category_word) = lower(min(i.typed_category_word))) AS high
            FROM items i LEFT JOIN users u ON u.id = i.created_by
            WHERE i.status = 'pending_category' AND i.typed_category_word IS NOT NULL
            GROUP BY lower(i.typed_category_word)
            ORDER BY min(i.created_at)
            SQL);

        return array_map(function (object $r): array {
            /** @var list<string> $ids */
            $ids = json_decode($r->item_ids, true) ?: [];

            return ['word' => $r->word, 'item_ids' => $ids, 'names' => $r->names, 'typed_by' => $r->typed_by, 'sales' => (int) $r->sales,
                'low_kobo' => $r->low === null ? null : (int) $r->low, 'high_kobo' => $r->high === null ? null : (int) $r->high];
        }, $rows);
    }

    /**
     * Items a cashier created while selling, and typed lines sold while new items needed approval.
     *
     * @return list<array{id: string, name: string, typed_line: bool, category: string, parts: string, created_by: ?string, created_at: string, sales: int, prices: list<int>, brand_waiting: ?string}>
     */
    private function newItems(): array
    {
        /** @var list<object{id: string, name: string, source: string, category: string, created_by: ?string, created_at: string, sales: int|string, prices: ?string, brand_status: ?string, brand: ?string, parts: ?string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT i.id, i.display_name AS name, i.source, c.name AS category, u.name AS created_by, i.created_at,
                   (SELECT count(*) FROM sale_lines l WHERE l.item_id = i.id AND l.qty > 0) AS sales,
                   (SELECT json_agg(DISTINCT l.unit_price_kobo)::text FROM sale_lines l WHERE l.item_id = i.id) AS prices,
                   b.status AS brand_status, b.name AS brand,
                   (SELECT string_agg(coalesce(v.name, x.text_value), ', ' ORDER BY x.facet_key) FROM item_facets x LEFT JOIN picklist_values v ON v.id = x.picklist_value_id WHERE x.item_id = i.id) AS parts
            FROM items i JOIN categories c ON c.id = i.category_id LEFT JOIN users u ON u.id = i.created_by LEFT JOIN brands b ON b.id = i.brand_id
            WHERE i.status = 'pending_review'
            ORDER BY i.created_at DESC
            SQL);

        return array_map(function (object $r): array {
            /** @var list<int|string> $prices */
            $prices = $r->prices === null ? [] : (json_decode($r->prices, true) ?: []);

            return [
                'id' => $r->id, 'name' => $r->name, 'typed_line' => $r->source === 'typed', 'category' => $r->category,
                'parts' => implode(', ', array_filter([$r->brand, $r->parts])), 'created_by' => $r->created_by, 'created_at' => $r->created_at,
                'sales' => (int) $r->sales, 'prices' => array_map(fn (int|string $p) => (int) $p, $prices),
                'brand_waiting' => $r->brand_status === 'pending' ? $r->brand : null,
            ];
        }, $rows);
    }

    /**
     * Learned links not yet confirmed by two further sales, and not in conflict.
     *
     * @return list<array{id: string, code: string, item_id: string, name: string, learned_by: ?string, learned_at: string, confirmations: int}>
     */
    private function learned(): array
    {
        /** @var list<object{id: string, code: string, item_id: string, name: string, learned_by: ?string, learned_at: string, confirmations: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT bc.id, bc.code, bc.item_id, i.display_name AS name, u.name AS learned_by, bc.learned_at, bc.confirmations
            FROM barcodes bc JOIN items i ON i.id = bc.item_id LEFT JOIN users u ON u.id = bc.learned_by
            WHERE bc.status = 'unconfirmed' AND NOT bc.conflicted AND i.status <> 'merged'
            ORDER BY bc.learned_at DESC LIMIT 200
            SQL);

        return array_map(fn (object $r) => ['id' => $r->id, 'code' => $r->code, 'item_id' => $r->item_id, 'name' => $r->name,
            'learned_by' => $r->learned_by, 'learned_at' => $r->learned_at, 'confirmations' => (int) $r->confirmations], $rows);
    }

    /**
     * Codes two cashiers linked to different items, one row per code.
     *
     * @return list<array{code: string, links: list<array{item_id: string, name: string, chosen_by: ?string, sales: int}>}>
     */
    private function conflicts(): array
    {
        /** @var list<object{code: string, item_id: string, name: string, chosen_by: ?string, sales: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT bc.code, bc.item_id, i.display_name AS name, u.name AS chosen_by,
                   (SELECT count(*) FROM sale_lines l WHERE l.item_id = bc.item_id AND l.qty > 0 AND l.flags->>'barcode' = bc.code) AS sales
            FROM barcodes bc JOIN items i ON i.id = bc.item_id LEFT JOIN users u ON u.id = bc.learned_by
            WHERE bc.conflicted AND bc.status <> 'disabled'
            ORDER BY bc.code, bc.learned_at
            SQL);
        $byCode = [];
        foreach ($rows as $r) {
            $byCode[$r->code][] = ['item_id' => $r->item_id, 'name' => $r->name, 'chosen_by' => $r->chosen_by, 'sales' => (int) $r->sales];
        }

        return array_map(fn (string $code, array $links) => ['code' => $code, 'links' => $links], array_keys($byCode), $byCode);
    }

    /**
     * Search words in use, and those close to it.
     *
     * @return list<array{id: string, term: string, item_id: string, name: string, hits: int, active: bool, needed: int}>
     */
    private function aliases(): array
    {
        $needed = $this->settings->get('catalogue.alias_activation');
        $needed = is_int($needed) ? $needed : 3;
        /** @var list<object{id: string, term: string, item_id: string, name: string, hits: int|string, status: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT a.id, a.term, a.item_id, i.display_name AS name, a.hits, a.status
            FROM aliases a JOIN items i ON i.id = a.item_id
            WHERE (a.status = 'active' OR (a.status = 'candidate' AND a.hits >= ?)) AND i.status <> 'merged'
            ORDER BY (a.status = 'active') DESC, a.hits DESC, a.term LIMIT 200
            SQL, [max(1, $needed - 1)]);

        return array_map(fn (object $r) => ['id' => $r->id, 'term' => $r->term, 'item_id' => $r->item_id, 'name' => $r->name,
            'hits' => (int) $r->hits, 'active' => $r->status === 'active', 'needed' => $needed], $rows);
    }

    /**
     * Items in the same category whose names are alike, at least one of them added at the counter, that
     * nobody has called "not the same". The one with more history is offered as the one to keep.
     *
     * @return list<array{keep: array{id: string, name: string, source: string, sales: int, barcodes: int, set_price_kobo: ?int}, merge: array{id: string, name: string, source: string, sales: int, barcodes: int, set_price_kobo: ?int}, reason: string}>
     */
    public function duplicates(): array
    {
        /** @var list<object{a_id: string, b_id: string, category: string}> $pairs */
        $pairs = DB::select(<<<'SQL'
            SELECT a.id AS a_id, b.id AS b_id, c.name AS category
            FROM items a JOIN items b ON b.category_id = a.category_id AND b.id <> a.id AND b.search_text % a.search_text
            JOIN categories c ON c.id = a.category_id
            WHERE (a.source IN ('cashier', 'typed') OR a.status IN ('pending_review', 'pending_category'))
              AND a.status NOT IN ('merged', 'archived') AND b.status NOT IN ('merged', 'archived')
              AND similarity(a.search_text, b.search_text) >= ?
              AND NOT EXISTS (SELECT 1 FROM merge_events m WHERE m.kind = 'not_same' AND m.item_id = least(a.id, b.id) AND m.into_id = greatest(a.id, b.id))
            ORDER BY similarity(a.search_text, b.search_text) DESC
            LIMIT 60
            SQL, [self::DUPLICATE_SIMILARITY]);

        $seen = [];
        $found = [];
        foreach ($pairs as $p) {
            $key = min($p->a_id, $p->b_id).'|'.max($p->a_id, $p->b_id);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $a = $this->summary($p->a_id);
            $b = $this->summary($p->b_id);
            // Keep the one from the starter catalogue or the owner, then the one with more sales.
            $keepA = [$a['source'] === 'starter' || $a['source'] === 'owner', $a['sales'], $b['id']] >= [$b['source'] === 'starter' || $b['source'] === 'owner', $b['sales'], $a['id']];
            [$keep, $merge] = $keepA ? [$a, $b] : [$b, $a];
            $letters = levenshtein(mb_strtolower($keep['name']), mb_strtolower($merge['name']));
            $reason = "Same category ({$p->category}); ".($letters <= 3 ? ($letters === 1 ? 'the names differ by one letter' : "the names differ by {$letters} letters") : 'the names are alike');
            $found[] = ['keep' => $keep, 'merge' => $merge, 'reason' => $reason];
            if (count($found) >= 20) {
                break;
            }
        }

        return $found;
    }

    /**
     * @return array{id: string, name: string, source: string, sales: int, barcodes: int, set_price_kobo: ?int}
     */
    private function summary(string $itemId): array
    {
        /** @var object{id: string, name: string, source: string, sales: int|string, barcodes: int|string, price: ?int} $r */
        $r = DB::selectOne(<<<'SQL'
            SELECT i.id, i.display_name AS name, i.source,
                   (SELECT count(*) FROM sale_lines l WHERE l.item_id = i.id AND l.qty > 0) AS sales,
                   (SELECT count(*) FROM barcodes bc WHERE bc.item_id = i.id AND bc.status <> 'disabled') AS barcodes,
                   (SELECT p.price_kobo FROM item_prices p WHERE p.item_id = i.id AND p.customer_type = 'retail' ORDER BY p.effective_from DESC LIMIT 1) AS price
            FROM items i WHERE i.id = ?
            SQL, [$itemId]);

        return ['id' => $r->id, 'name' => $r->name, 'source' => $r->source, 'sales' => (int) $r->sales, 'barcodes' => (int) $r->barcodes,
            'set_price_kobo' => $r->price === null ? null : (int) $r->price];
    }
}
