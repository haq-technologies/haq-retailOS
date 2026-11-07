<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\Catalogue\Models\Item;
use App\Cost\Backfill;
use App\Cost\Costs;
use App\Reports\NeedsCost;
use App\Sales\Pricing;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The Needs-cost queue, one item's costs, the backfill preview and saving a cost (spec section 8)
 * [F14, FU6] (wireframes NeedsCost and NeedsCostBulk). Only those who may enter cost reach it.
 */
final class CostController
{
    use ActsForUser;

    public function queue(Request $request): JsonResponse
    {
        $this->allowed($request, 'costs');
        $search = $request->query('search');

        return response()->json(NeedsCost::queue(is_string($search) ? $search : null, 200));
    }

    public function item(Request $request, Item $item, Pricing $pricing): JsonResponse
    {
        $this->allowed($request, 'costs');
        $usual = $pricing->info($item, 'retail');
        /** @var list<object{unit_cost_kobo: int|string, effective_from: string, name: ?string}> $history */
        $history = DB::table('cost_entries as c')->leftJoin('users as u', 'u.id', '=', 'c.entered_by')->where('c.item_id', $item->id)
            ->orderByDesc('c.effective_from')->limit(10)->get(['c.unit_cost_kobo', 'c.effective_from', 'u.name'])->all();

        return response()->json([
            'item_id' => $item->id,
            'name' => $item->display_name,
            'usual_price_kobo' => $usual['prefill'],
            'current_cost_kobo' => Costs::current($item->id),
            'history' => array_map(fn (object $h) => ['unit_cost_kobo' => (int) $h->unit_cost_kobo, 'effective_from' => $h->effective_from, 'by' => $h->name], $history),
        ]);
    }

    public function preview(Request $request, Backfill $backfill): JsonResponse
    {
        $this->allowed($request, 'costs');
        [$items, $cost, , $basis, $from] = $this->input($request);
        try {
            return response()->json($backfill->preview($items, $cost, $basis, $from));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function save(Request $request, Backfill $backfill): JsonResponse
    {
        $actor = $this->allowed($request, 'costs');
        [$items, $cost, $asOf, $basis, $from] = $this->input($request);
        try {
            $result = $backfill->apply($actor, $items, $cost, $asOf, $basis, $from);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /**
     * @return array{0: list<string>, 1: int, 2: CarbonImmutable, 3: string, 4: ?CarbonImmutable}
     */
    private function input(Request $request): array
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:200'],
            'item_ids.*' => ['string', 'size:26', 'exists:items,id'],
            'unit_cost_kobo' => ['required', 'integer', 'min:0'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'basis' => ['required', 'in:first_null,from_date'],
            'from' => ['nullable', 'required_if:basis,from_date', 'date_format:Y-m-d'],
        ]);
        /** @var list<string> $items */
        $items = array_values(array_unique(array_map('strval', (array) $data['item_ids'])));
        $asOf = isset($data['as_of']) ? CarbonImmutable::parse((string) $data['as_of']) : CarbonImmutable::now();
        if ($asOf->isFuture() && ! $asOf->isToday()) {
            abort(422, 'A cost cannot be dated in the future.');
        }

        return [$items, (int) $data['unit_cost_kobo'], $asOf, (string) $data['basis'], isset($data['from']) ? CarbonImmutable::parse((string) $data['from']) : null];
    }
}
