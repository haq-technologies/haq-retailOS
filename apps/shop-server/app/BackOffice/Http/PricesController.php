<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\Catalogue\Models\Item;
use App\Platform\Ulid;
use App\Reports\Dashboard;
use App\Reports\FirstSales;
use App\Reports\Period;
use App\Sales\Pricing;
use App\Sales\SaleRefused;
use App\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Prices (spec sections 3 and 7) [S4; wireframe FirstSalePrices]: the first sales of the day, where one tap
 * makes a price the set price, and setting an item's retail or wholesale price. The owner and managers set
 * prices; a price set reaches every counter through the feed and is kept in the price history.
 */
final class PricesController
{
    use ActsForUser;

    public function firstSales(Request $request, Dashboard $dashboard, Settings $settings): JsonResponse
    {
        $this->allowed($request, 'prices');
        $day = $request->query('day');
        $day = is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : $dashboard->today();
        $zone = $settings->get('business.timezone');

        return response()->json(['day' => $day, 'items' => FirstSales::forDay($day, Period::day($day, is_string($zone) ? $zone : 'Africa/Lagos'))]);
    }

    /** One item's prices for both customer types, with the recent history. */
    public function item(Request $request, string $item, Pricing $pricing): JsonResponse
    {
        $this->allowed($request, 'prices');
        $found = $this->find($item);
        $types = [];
        foreach (['retail', 'wholesale'] as $type) {
            $info = $pricing->info($found, $type);
            /** @var list<object{price_kobo: int|string|null, effective_from: string, by: ?string}> $history */
            $history = DB::table('item_prices as p')->leftJoin('users as u', 'u.id', '=', 'p.set_by')->where('p.item_id', $found->id)->where('p.customer_type', $type)
                ->orderByDesc('p.effective_from')->orderByDesc('p.id')->limit(6)->get(['p.price_kobo', 'p.effective_from', 'u.name as by'])->all();
            $types[$type] = [...$info, 'history' => array_map(fn (object $h) => ['price_kobo' => $h->price_kobo === null ? null : (int) $h->price_kobo, 'at' => $h->effective_from, 'by' => $h->by], $history)];
        }

        return response()->json(['item_id' => $found->id, 'name' => $found->display_name, 'prices' => $types]);
    }

    public function set(Request $request, Pricing $pricing): JsonResponse
    {
        $actor = $this->allowed($request, 'prices');
        $data = $request->validate([
            'item_id' => ['required', 'string', 'size:26'], 'customer_type' => ['required', 'in:retail,wholesale'],
            'price_kobo' => ['required', 'integer', 'min:1', 'max:10000000000'],
        ]);
        $item = $this->find((string) $data['item_id']);
        try {
            $pricing->set($item, (string) $data['customer_type'], (int) $data['price_kobo'], $actor);
        } catch (SaleRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function undo(Request $request, Pricing $pricing): JsonResponse
    {
        $actor = $this->allowed($request, 'prices');
        $data = $request->validate(['item_id' => ['required', 'string', 'size:26'], 'customer_type' => ['required', 'in:retail,wholesale']]);
        $item = $this->find((string) $data['item_id']);
        try {
            $now = $pricing->undoLast($item, (string) $data['customer_type'], $actor);
        } catch (SaleRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'price_kobo' => $now]);
    }

    private function find(string $id): Item
    {
        abort_unless(Ulid::isValid($id), 404);
        $item = Item::query()->find($id);
        abort_if($item === null || in_array($item->status, ['merged', 'archived'], true), 404);

        return $item;
    }
}
