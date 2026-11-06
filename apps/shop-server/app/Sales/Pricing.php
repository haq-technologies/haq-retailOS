<?php

declare(strict_types=1);

namespace App\Sales;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Catalogue\Models\Item;
use App\Identity\Models\User;
use App\Platform\Ulid;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Prices (spec section 7) [S4, S5, F11].
 *
 * The price pad fills in with the item's set price for the customer type; with none, the last price sold
 * for that item and type, or (a shop setting, for shops that haggle) the median of the last 10 sales; with
 * no sale yet, it is empty. The usual range is the set price, or else the 10th to 90th percentile of the
 * item's last 20 sales for the type, given at least 5. A new item inherits a range from the sibling that
 * shares most of its facets, otherwise from its category (D30). With nothing to inherit there is no range,
 * and the line goes on the owner's first-sale price list.
 */
final readonly class Pricing
{
    private const HISTORY = 20;

    private const MIN_SALES = 5;

    public function __construct(private Settings $settings, private AuditLog $audit) {}

    /**
     * @return array{set_price: ?int, prefill: ?int, prefill_source: ?string, range: array{low: int, high: int, source: string}|null}
     */
    public function info(Item $item, string $customerType): array
    {
        $set = $this->setPrice($item->id, $customerType);
        $prefill = $set;
        $source = $set === null ? null : 'set_price';
        if ($set === null) {
            $useMedian = $this->settings->get('pricing.fill_fallback') === 'median';
            $recent = $this->recentPrices($item->id, $customerType, $useMedian ? 10 : 1);
            if ($recent !== []) {
                $prefill = $useMedian ? self::median($recent) : $recent[0];
                $source = $useMedian ? 'median' : 'last_price';
            }
        }

        return ['set_price' => $set, 'prefill' => $prefill, 'prefill_source' => $source, 'range' => $this->range($item, $customerType, $set)];
    }

    /**
     * @return array{low: int, high: int, source: string}|null
     */
    public function range(Item $item, string $customerType, ?int $setPrice = null): ?array
    {
        $setPrice ??= $this->setPrice($item->id, $customerType);
        if ($setPrice !== null) {
            return ['low' => $setPrice, 'high' => $setPrice, 'source' => 'set_price'];
        }

        $own = $this->percentiles($this->recentPrices($item->id, $customerType, self::HISTORY));
        if ($own !== null) {
            return [...$own, 'source' => 'history'];
        }

        $sibling = $this->closestSibling($item, $customerType);
        if ($sibling !== null) {
            $range = $this->percentiles($this->recentPrices($sibling, $customerType, self::HISTORY));
            if ($range !== null) {
                return [...$range, 'source' => 'sibling'];
            }
        }

        $category = self::ints(DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')->join('items as i', 'i.id', '=', 'l.item_id')
            ->where('i.category_id', $item->category_id)->where('s.customer_type', $customerType)->where('s.kind', 'sale')
            ->orderByDesc('s.recorded_at')->limit(50)->pluck('l.unit_price_kobo')->all());
        $range = $this->percentiles($category);

        return $range === null ? null : [...$range, 'source' => 'category'];
    }

    /** The owner and the manager set prices [S4]. */
    public function set(Item $item, string $customerType, int $priceKobo, User $actor): void
    {
        if (! in_array($actor->role->value, ['manager', 'owner'], true)) {
            throw new SaleRefused('Only a manager or the owner sets prices.');
        }
        if ($priceKobo < 1) {
            throw new SaleRefused('A set price must be more than nothing.');
        }
        DB::transaction(function () use ($item, $customerType, $priceKobo, $actor): void {
            $old = $this->setPrice($item->id, $customerType);
            DB::table('item_prices')->insert([
                'id' => Ulid::generate(), 'item_id' => $item->id, 'customer_type' => $customerType, 'price_kobo' => $priceKobo,
                'effective_from' => CarbonImmutable::now()->format('Y-m-d H:i:s.uP'), 'set_by' => $actor->id,
            ]);
            $this->audit->record(new AuditEntry(action: 'price.set', actorId: $actor->id, subjectType: 'item', subjectId: $item->id,
                before: ['customer_type' => $customerType, 'price_kobo' => $old], after: ['customer_type' => $customerType, 'price_kobo' => $priceKobo]));
        });
    }

    public function setPrice(string $itemId, string $customerType): ?int
    {
        $price = DB::table('item_prices')->where('item_id', $itemId)->where('customer_type', $customerType)
            ->orderByDesc('effective_from')->value('price_kobo');

        return is_numeric($price) ? (int) $price : null;
    }

    /**
     * @return list<int> Most recent first.
     */
    private function recentPrices(string $itemId, string $customerType, int $limit): array
    {
        return self::ints(DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->where('l.item_id', $itemId)->where('s.customer_type', $customerType)->where('s.kind', 'sale')
            ->orderByDesc('s.recorded_at')->limit($limit)->pluck('l.unit_price_kobo')->all());
    }

    /**
     * @param  array<mixed>  $values
     * @return list<int>
     */
    private static function ints(array $values): array
    {
        return array_values(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $values));
    }

    /** The sibling in the same category that shares most facet values with the item and has enough sales. */
    private function closestSibling(Item $item, string $customerType): ?string
    {
        $mine = DB::table('item_facets')->where('item_id', $item->id)->get(['facet_key', 'picklist_value_id', 'text_value'])
            ->map(fn (object $f) => $f->facet_key.'='.($f->picklist_value_id ?? 't:'.mb_strtolower((string) $f->text_value)))->all();

        $candidates = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')->join('items as i', 'i.id', '=', 'l.item_id')
            ->where('i.category_id', $item->category_id)->where('i.id', '!=', $item->id)->where('s.customer_type', $customerType)
            ->groupBy('l.item_id')->havingRaw('count(*) >= ?', [self::MIN_SALES])
            ->selectRaw('l.item_id, count(*) as sales')->get();

        $best = null;
        $bestScore = [-1, -1];
        foreach ($candidates as $candidate) {
            $theirs = DB::table('item_facets')->where('item_id', $candidate->item_id)->get(['facet_key', 'picklist_value_id', 'text_value'])
                ->map(fn (object $f) => $f->facet_key.'='.($f->picklist_value_id ?? 't:'.mb_strtolower((string) $f->text_value)))->all();
            $score = [count(array_intersect($mine, $theirs)), (int) $candidate->sales];
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (string) $candidate->item_id;
            }
        }

        return $best;
    }

    /**
     * The 10th and 90th percentiles (nearest rank), given at least five prices.
     *
     * @param  list<int>  $prices
     * @return array{low: int, high: int}|null
     */
    private function percentiles(array $prices): ?array
    {
        $n = count($prices);
        if ($n < self::MIN_SALES) {
            return null;
        }
        sort($prices);

        return ['low' => $prices[max(0, (int) ceil(0.10 * $n) - 1)], 'high' => $prices[max(0, (int) ceil(0.90 * $n) - 1)]];
    }

    /**
     * @param  list<int>  $prices
     */
    private static function median(array $prices): int
    {
        sort($prices);
        $n = count($prices);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $prices[$mid] : intdiv($prices[$mid - 1] + $prices[$mid], 2);
    }
}
