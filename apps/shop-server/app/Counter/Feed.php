<?php

declare(strict_types=1);

namespace App\Counter;

use App\Catalogue\Items;
use App\Catalogue\Models\Brand;
use App\Catalogue\Models\Category;
use App\Catalogue\Models\CategoryTemplate;
use App\Catalogue\Models\Item;
use App\Catalogue\Shapes;
use App\Sales\Pricing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The counter's copy of the catalogue (spec section 4) [R5, F25]: a snapshot to start from, then the
 * committed changes after the last seq the counter saw, polled every 2 seconds. Rows are sent as the
 * counter needs them to search, drill and name items offline; nothing about cost ever leaves (spec
 * section 2). A row that no longer exists comes back under "removed".
 */
final readonly class Feed
{
    /** Tables in the order a counter applies them, with the columns it receives. */
    private const COLUMNS = [
        'categories' => ['id', 'code', 'parent_id', 'name', 'word', 'sellable', 'aliases', 'position'],
        'category_templates' => ['id', 'category_id', 'facets', 'drill_order', 'name_order', 'asks_colour'],
        'picklist_values' => ['id', 'list', 'name', 'aliases', 'parent_id', 'status', 'position'],
        'brands' => ['id', 'name', 'aliases', 'shown_first_in', 'status', 'merged_into_id'],
        'product_models' => ['id', 'brand_id', 'name', 'categories', 'status'],
        'items' => ['id', 'category_id', 'brand_id', 'item_code', 'display_name', 'search_text', 'status', 'merged_into_id'],
        'item_facets' => ['id', 'item_id', 'facet_key', 'picklist_value_id', 'text_value'],
        'barcodes' => ['id', 'code', 'item_id', 'status', 'conflicted', 'last_chosen_at'],
        'aliases' => ['id', 'item_id', 'term', 'status'],
    ];

    private const JSON = ['aliases', 'facets', 'drill_order', 'name_order', 'shown_first_in', 'categories'];

    public function __construct(private Pricing $pricing, private Items $items) {}

    /**
     * The feed's generation: the newest restore, or "install" before any. A restore takes the change log
     * back to the backup, so a counter holding another generation (or a seq past the log's end) throws its
     * copy away and starts again from a snapshot (spec section 4) [F1].
     */
    public static function generation(): string
    {
        $id = DB::table('restores')->orderByDesc('at')->value('id');

        return is_string($id) ? $id : 'install';
    }

    /** The seq every committed change up to now has. */
    public static function seqNow(): int
    {
        $max = DB::table('catalogue_changes')->max('seq');

        return is_numeric($max) ? (int) $max : 0;
    }

    /**
     * One page of the snapshot: every table in turn, by id. The first page fixes the seq the counter polls
     * from afterwards; changes made while it pages arrive again as changes, which is harmless.
     *
     * @return array{seq: int, generation: string, rows: array<string, list<array<string, mixed>>>, prices: list<array<string, mixed>>, next: ?string}
     */
    public function snapshot(?string $cursor, int $limit = 2000): array
    {
        [$seq, $table, $afterId] = $this->decodeCursor($cursor);
        $tables = [...array_keys(self::COLUMNS), 'prices'];
        $index = array_search($table, $tables, true);
        $index = $index === false ? 0 : $index;
        $rows = [];
        $prices = [];
        $remaining = $limit;

        for ($i = $index; $i < count($tables) && $remaining > 0; $i++) {
            $name = $tables[$i];
            $from = $i === $index ? $afterId : '';
            if ($name === 'prices') {
                $ids = $this->pricedItemIds($from, $remaining);
                $prices = $this->prices($ids);
                $remaining -= count($ids);
                $last = end($ids);
            } else {
                $page = $this->read($name, fn ($q) => $q->where('id', '>', $from)->orderBy('id')->limit($remaining));
                $rows[$name] = $page;
                $remaining -= count($page);
                $lastRow = end($page);
                $last = $lastRow === false || ! is_string($lastRow['id'] ?? null) ? false : $lastRow['id'];
            }
            if ($remaining <= 0 && $last !== false) {
                return ['seq' => $seq, 'generation' => self::generation(), 'rows' => $rows, 'prices' => $prices, 'next' => $this->encodeCursor($seq, $name, $last)];
            }
        }

        return ['seq' => $seq, 'generation' => self::generation(), 'rows' => $rows, 'prices' => $prices, 'next' => null];
    }

    /**
     * The committed changes after $after, at most $limit of them, newest state of each row. A counter of
     * another generation, or one past the end of the log, is told to start again from a snapshot.
     *
     * @return array{seq: int, generation: string, reset: bool, more: bool, rows: array<string, list<array<string, mixed>>>, removed: array<string, list<string>>, prices: list<array<string, mixed>>}
     */
    public function changes(int $after, int $limit = 1000, ?string $generation = null): array
    {
        $now = self::generation();
        if (($generation !== null && $generation !== $now) || $after > self::seqNow()) {
            return ['seq' => 0, 'generation' => $now, 'reset' => true, 'more' => false, 'rows' => [], 'removed' => [], 'prices' => []];
        }

        $changes = DB::table('catalogue_changes')->where('seq', '>', $after)->orderBy('seq')->limit($limit + 1)->get(['seq', 'table_name', 'row_id']);
        $more = $changes->count() > $limit;
        $changes = $changes->take($limit);
        $max = $changes->max('seq');
        $seq = is_numeric($max) ? (int) $max : $after;

        $byTable = [];
        foreach ($changes as $change) {
            $byTable[(string) $change->table_name][trim((string) $change->row_id)] = true;
        }

        $rows = [];
        $removed = [];
        foreach (array_keys(self::COLUMNS) as $table) {
            $ids = array_keys($byTable[$table] ?? []);
            if ($ids === []) {
                continue;
            }
            $found = $this->read($table, fn ($q) => $q->whereIn('id', $ids));
            $rows[$table] = $found;
            $foundIds = array_map(fn (array $r) => is_string($r['id'] ?? null) ? $r['id'] : '', $found);
            $gone = array_values(array_diff($ids, $foundIds));
            if ($gone !== []) {
                $removed[$table] = $gone;
            }
        }
        $priced = array_values(array_unique([...array_keys($byTable['item_prices'] ?? []), ...array_keys($byTable['sale_lines'] ?? [])]));

        return ['seq' => $seq, 'generation' => $now, 'reset' => false, 'more' => $more, 'rows' => $rows, 'removed' => $removed, 'prices' => $this->prices($priced)];
    }

    /**
     * Price pre-fill and usual range for each item, retail and wholesale (spec section 7) [S4], and its
     * sales in the last 30 days, which search ranks by [S10]. No cost.
     *
     * @param  list<string>  $itemIds
     * @return list<array{item_id: string, retail: array<string, mixed>, wholesale: array<string, mixed>, sold_30d: int}>
     */
    public function prices(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $since = CarbonImmutable::now()->subDays(30)->format('Y-m-d H:i:s.uP');
        /** @var array<string, int|string> $sold */
        $sold = DB::table('sale_lines')->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->whereIn('sale_lines.item_id', $itemIds)->where('sales.recorded_at', '>=', $since)
            ->groupBy('sale_lines.item_id')->selectRaw('sale_lines.item_id, sum(sale_lines.qty) AS sold')->pluck('sold', 'item_id')->all();
        $out = [];
        foreach (Item::query()->whereIn('id', $itemIds)->orderBy('id')->get() as $item) {
            $out[] = [
                'item_id' => $item->id, 'retail' => $this->pricing->info($item, 'retail'), 'wholesale' => $this->pricing->info($item, 'wholesale'),
                'sold_30d' => (int) ($sold[$item->id] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The generated shapes (spec section 6) [R2]: combinations a category's generator offers, searchable and
     * on the tiles before anyone has sold them. Selling one creates its item.
     *
     * @return list<array{id: string, category_id: string, facets: array<string, string>, display_name: string}>
     */
    public function shapes(): array
    {
        $out = [];
        foreach (CategoryTemplate::query()->whereNotNull('generator')->orderBy('category_id')->get() as $template) {
            $category = Category::query()->find($template->category_id);
            if ($category === null || ! $category->sellable) {
                continue;
            }
            foreach (Shapes::for($template) as $shape) {
                $facets = array_filter($shape, fn (?string $v) => $v !== null);
                ksort($facets);
                $out[] = [
                    'id' => 'shape:'.$category->id.':'.implode(',', array_map(fn (string $k, string $v) => "{$k}={$v}", array_keys($facets), $facets)),
                    'category_id' => $category->id,
                    'facets' => $facets,
                    'display_name' => $this->items->previewName($category, $facets, null, null, null),
                ];
            }
        }

        return $out;
    }

    /**
     * Creates the item for a generated shape on its first sale, or finds it; with a brand chosen at the
     * counter's brand step, that brand's item (D54). The brand must be a listed one; a merged brand counts
     * as the one it was merged into.
     *
     * @param  array<string, string>  $facets
     */
    public function itemForShape(string $categoryId, array $facets, ?string $brandId = null): ?Item
    {
        $brand = null;
        if ($brandId !== null) {
            $brand = Brand::query()->find($brandId);
            for ($hops = 0; $brand !== null && $brand->status === 'merged' && $brand->merged_into_id !== null && $hops < 10; $hops++) {
                $brand = Brand::query()->find($brand->merged_into_id);
            }
            if ($brand === null || $brand->status !== 'approved') {
                return null;
            }
        }
        $template = CategoryTemplate::query()->where('category_id', $categoryId)->first();
        $category = Category::query()->find($categoryId);
        if ($template === null || $category === null) {
            return null;
        }
        ksort($facets);
        foreach (Shapes::for($template) as $shape) {
            $wanted = array_filter($shape, fn (?string $v) => $v !== null);
            ksort($wanted);
            if ($wanted === $facets) {
                return $this->items->findOrCreate($category, $facets, brand: $brand, source: 'generated');
            }
        }

        return null;
    }

    /**
     * @param  callable(Builder): mixed  $scope
     * @return list<array<string, mixed>>
     */
    private function read(string $table, callable $scope): array
    {
        $query = DB::table($table)->select(self::COLUMNS[$table]);
        $scope($query);

        return array_values(array_map(function (object $row): array {
            $out = (array) $row;
            foreach (self::JSON as $column) {
                if (isset($out[$column]) && is_string($out[$column])) {
                    $out[$column] = json_decode($out[$column], true);
                }
            }
            if (isset($out['item_code']) && is_string($out['item_code'])) {
                $out['item_code'] = trim($out['item_code']);
            }

            return $out;
        }, $query->get()->all()));
    }

    /**
     * Items with their own price history: a set price or a sale. Others take the range of their family,
     * which the counter asks for when it adds one.
     *
     * @return list<string>
     */
    private function pricedItemIds(string $afterId, int $limit): array
    {
        /** @var list<string> $ids */
        $ids = DB::table('items')->where('id', '>', $afterId)
            ->where(fn ($q) => $q->whereExists(fn ($e) => $e->from('item_prices')->whereColumn('item_prices.item_id', 'items.id'))
                ->orWhereExists(fn ($e) => $e->from('sale_lines')->whereColumn('sale_lines.item_id', 'items.id')))
            ->orderBy('id')->limit($limit)->pluck('id')->map(fn (mixed $id) => is_string($id) ? $id : '')->all();

        return $ids;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function decodeCursor(?string $cursor): array
    {
        if ($cursor !== null && preg_match('/^(\d+)\.([a-z_]+)\.([0-9a-z]{26})$/', $cursor, $m) === 1) {
            return [(int) $m[1], $m[2], $m[3]];
        }

        return [self::seqNow(), 'categories', ''];
    }

    private function encodeCursor(int $seq, string $table, string $lastId): string
    {
        return "{$seq}.{$table}.{$lastId}";
    }
}
