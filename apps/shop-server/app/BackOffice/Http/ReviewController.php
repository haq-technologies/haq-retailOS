<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\Catalogue\Learning;
use App\Catalogue\MergeRefused;
use App\Catalogue\Merges;
use App\Catalogue\Models\Brand;
use App\Catalogue\Models\Category;
use App\Catalogue\Models\Item;
use App\Catalogue\Models\PicklistValue;
use App\Catalogue\ReviewQueue;
use App\Catalogue\Reviews;
use App\Platform\Ulid;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Review (spec sections 3 and 6; wireframes ReviewNames, ReviewNewItems, ReviewBarcodes and MergeItems):
 * what cashiers added while selling. Managers, the storekeeper and the owner review items, barcodes, search
 * words and duplicates; managers and the owner approve brands and phone models; only the owner creates a
 * category. Every answer is audited, and none changes a sale.
 */
final class ReviewController
{
    use ActsForUser;

    public function index(Request $request, ReviewQueue $queue): JsonResponse
    {
        $this->allowed($request, 'review');

        return response()->json($queue->all());
    }

    public function approveBrand(Request $request, string $brand, Reviews $reviews): JsonResponse
    {
        $actor = $this->allowed($request, 'names');

        return $this->answer(fn () => $reviews->approveBrand($this->find(Brand::class, $brand), $actor));
    }

    public function mergeBrand(Request $request, string $brand, Reviews $reviews): JsonResponse
    {
        $actor = $this->allowed($request, 'names');
        $into = $this->find(Brand::class, $request->string('into_id')->toString());

        return $this->answer(fn () => $reviews->mergeBrand($this->find(Brand::class, $brand), $into, $actor));
    }

    public function approveModel(Request $request, string $value, Reviews $reviews): JsonResponse
    {
        $actor = $this->allowed($request, 'names');

        return $this->answer(fn () => $reviews->approveModel($this->find(PicklistValue::class, $value), $actor));
    }

    public function mergeModel(Request $request, string $value, Reviews $reviews): JsonResponse
    {
        $actor = $this->allowed($request, 'names');
        $into = $this->find(PicklistValue::class, $request->string('into_id')->toString());

        return $this->answer(fn () => $reviews->mergeModel($this->find(PicklistValue::class, $value), $into, $actor));
    }

    /** The owner creates a category for a typed product word. */
    public function createCategory(Request $request, Reviews $reviews): JsonResponse
    {
        $actor = $this->allowed($request, 'categories');
        $data = $request->validate([
            'typed' => ['required', 'string', 'max:80'], 'word' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:60'],
            'parent_id' => ['nullable', 'string', 'size:26'],
        ]);
        $parent = isset($data['parent_id']) ? $this->find(Category::class, (string) $data['parent_id']) : null;

        return $this->answer(function () use ($reviews, $data, $parent, $actor): array {
            $done = $reviews->createCategory((string) $data['typed'], (string) $data['word'], (string) $data['name'], $parent, $actor);

            return ['category_id' => $done['category']->id, 'moved' => $done['moved'], 'merged' => $done['merged']];
        });
    }

    /** The groups a new category can go under. */
    public function groups(Request $request): JsonResponse
    {
        $this->allowed($request, 'categories');

        return response()->json(DB::table('categories')->where('sellable', false)->orderBy('position')->orderBy('name')->get(['id', 'name'])->all());
    }

    public function approveItem(Request $request, string $item, Reviews $reviews): JsonResponse
    {
        $actor = $this->allowed($request, 'review');

        return $this->answer(fn () => $reviews->approveItem($this->find(Item::class, $item), $actor));
    }

    public function mergeItem(Request $request, string $item, Merges $merges): JsonResponse
    {
        $actor = $this->allowed($request, 'review');
        $into = $request->string('into_id')->toString();
        abort_unless(Ulid::isValid($item) && Ulid::isValid($into), 404);

        return $this->answer(fn () => ['merge_id' => $merges->merge($item, $into, $actor)]);
    }

    public function undoMerge(Request $request, string $merge, Merges $merges): JsonResponse
    {
        $actor = $this->allowed($request, 'review');
        abort_unless(Ulid::isValid($merge), 404);

        return $this->answer(fn () => $merges->undo($merge, $actor));
    }

    public function notSame(Request $request, Merges $merges): JsonResponse
    {
        $actor = $this->allowed($request, 'review');
        $data = $request->validate(['item_id' => ['required', 'string', 'size:26', 'exists:items,id'], 'other_id' => ['required', 'string', 'size:26', 'exists:items,id']]);

        return $this->answer(fn () => $merges->notSame((string) $data['item_id'], (string) $data['other_id'], $actor));
    }

    public function undoBarcode(Request $request, string $barcode, Learning $learning): JsonResponse
    {
        $actor = $this->allowed($request, 'review');
        abort_unless(Ulid::isValid($barcode), 404);

        return $this->answer(fn () => $learning->undo($barcode, $actor->id));
    }

    public function settleConflict(Request $request, Learning $learning): JsonResponse
    {
        $actor = $this->allowed($request, 'review');
        $data = $request->validate(['code' => ['required', 'string', 'max:120'], 'keep_item_id' => ['nullable', 'string', 'size:26']]);

        return $this->answer(fn () => $learning->settleConflict((string) $data['code'], isset($data['keep_item_id']) ? (string) $data['keep_item_id'] : null, $actor->id));
    }

    public function removeAlias(Request $request, string $alias, Learning $learning): JsonResponse
    {
        $actor = $this->allowed($request, 'review');
        abort_unless(Ulid::isValid($alias), 404);

        return $this->answer(fn () => $learning->removeAlias($alias, $actor->id));
    }

    /**
     * @param  Closure(): mixed  $do
     */
    private function answer(Closure $do): JsonResponse
    {
        try {
            $result = $do();
        } catch (MergeRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(is_array($result) ? ['ok' => true, ...$result] : ['ok' => true]);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    private function find(string $model, string $id): mixed
    {
        abort_unless(Ulid::isValid($id), 404);
        $found = $model::query()->find($id);
        abort_if($found === null, 404);

        return $found;
    }
}
