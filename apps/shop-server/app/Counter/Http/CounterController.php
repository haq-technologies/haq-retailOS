<?php

declare(strict_types=1);

namespace App\Counter\Http;

use App\Catalogue\Learning;
use App\Catalogue\NewItemRefused;
use App\Catalogue\NewItems;
use App\Counter\Feed;
use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Shifts\Shifts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The counter's own API: its copy of the catalogue, prices, barcodes, new items and its shift.
 */
final class CounterController
{
    public function snapshot(Request $request, Feed $feed): JsonResponse
    {
        $cursor = $request->query('cursor');

        return response()->json($feed->snapshot(is_string($cursor) ? $cursor : null));
    }

    public function changes(Request $request, Feed $feed): JsonResponse
    {
        $after = $request->query('after');

        return response()->json($feed->changes(is_numeric($after) ? max(0, (int) $after) : 0));
    }

    public function prices(Request $request, Feed $feed): JsonResponse
    {
        $data = $request->validate(['item_ids' => ['required', 'array', 'max:100'], 'item_ids.*' => ['string', 'size:26']]);
        /** @var list<string> $ids */
        $ids = array_values(array_map('strval', $data['item_ids']));

        return response()->json(['prices' => $feed->prices($ids)]);
    }

    /** A scanned code: junk, or its candidates (at most 5, most recently chosen first) [F13]. */
    public function barcode(string $code, Learning $learning): JsonResponse
    {
        $junk = Learning::isJunk($code);

        return response()->json(['code' => $code, 'junk' => $junk, 'candidates' => $junk ? [] : $learning->candidates($code)]);
    }

    /** The cashier matched an unknown code to an item: the link works at once, unconfirmed [F13]. */
    public function learn(Request $request, Learning $learning): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:120'], 'item_id' => ['required', 'string', 'size:26', 'exists:items,id']]);
        $learned = $learning->linkBarcode((string) $data['code'], (string) $data['item_id'], $this->actor($request)->id);

        return response()->json(['learned' => $learned]);
    }

    public function createItem(Request $request, NewItems $newItems, Feed $feed): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'string', 'size:26'],
            'not_in_list_word' => ['nullable', 'string', 'max:40'],
            'facets' => ['sometimes', 'array'],
            'facets.*' => ['string', 'max:80'],
            'brand_id' => ['nullable', 'string', 'size:26'],
            'brand_text' => ['nullable', 'string', 'max:60'],
            'phone_model_text' => ['nullable', 'string', 'max:60'],
            'free_text' => ['nullable', 'string', 'max:80'],
            'confirm_new' => ['sometimes', 'boolean'],
        ]);

        try {
            /** @var array{category_id?: ?string, not_in_list_word?: ?string, facets?: array<string, string>, brand_id?: ?string, brand_text?: ?string, phone_model_text?: ?string, free_text?: ?string, confirm_new?: bool} $data */
            $item = $newItems->create($data, $this->actor($request));
        } catch (NewItemRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'similar' => $e->similar], 422);
        }

        return response()->json([
            'item' => DB::table('items')->where('id', $item->id)->first(['id', 'category_id', 'brand_id', 'item_code', 'display_name', 'search_text', 'status']),
            'prices' => $feed->prices([$item->id]),
        ], $item->wasRecentlyCreated ? 201 : 200);
    }

    public function shift(Request $request, Shifts $shifts): JsonResponse
    {
        return response()->json(['shift' => $shifts->current($this->terminal($request))]);
    }

    /** Opens the drawer's shift with the counted float (spec section 9). */
    public function openShift(Request $request, Shifts $shifts): JsonResponse
    {
        $data = $request->validate(['opening_float_kobo' => ['required', 'integer', 'min:0', 'max:100000000000']]);
        $terminal = $this->terminal($request);
        if (! $terminal->canSell()) {
            return response()->json(['message' => 'This device does not take sales.'], 403);
        }
        if ($shifts->current($terminal) !== null) {
            return response()->json(['message' => 'A shift is already open on this counter.', 'shift' => $shifts->current($terminal)], 409);
        }
        DB::transaction(fn () => $shifts->openFor($terminal, $this->actor($request)->id, (int) $data['opening_float_kobo']));

        return response()->json(['shift' => $shifts->current($terminal)], 201);
    }

    private function terminal(Request $request): Terminal
    {
        $terminal = $request->attributes->get('terminal');
        if (! $terminal instanceof Terminal) {
            throw new LogicException('The EnrolledTerminal middleware must run first.');
        }

        return $terminal;
    }

    private function actor(Request $request): User
    {
        $actor = $request->attributes->get('acting_user');
        if (! $actor instanceof User) {
            throw new LogicException('The ActingUser middleware must run first.');
        }

        return $actor;
    }
}
