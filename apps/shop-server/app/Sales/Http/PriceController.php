<?php

declare(strict_types=1);

namespace App\Sales\Http;

use App\Catalogue\Models\Item;
use App\Identity\Models\User;
use App\Sales\Pricing;
use App\Sales\SaleRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * An item's price as the counter fills it in, and setting a price (the owner and the manager) [S4].
 */
final class PriceController
{
    public function show(Request $request, Item $item, Pricing $pricing): JsonResponse
    {
        $type = $request->query('customer_type') === 'wholesale' ? 'wholesale' : 'retail';

        return response()->json(['item_id' => $item->id, 'customer_type' => $type, ...$pricing->info($item, $type)]);
    }

    public function store(Request $request, Item $item, Pricing $pricing): JsonResponse
    {
        $data = $request->validate(['customer_type' => ['required', 'in:retail,wholesale'], 'price_kobo' => ['required', 'integer', 'min:1']]);
        $actor = $request->attributes->get('acting_user');
        if (! $actor instanceof User) {
            throw new LogicException('The ActingUser middleware must run first.');
        }

        try {
            $pricing->set($item, (string) $data['customer_type'], (int) $data['price_kobo'], $actor);
        } catch (SaleRefused $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['item_id' => $item->id, ...$pricing->info($item, (string) $data['customer_type'])]);
    }
}
