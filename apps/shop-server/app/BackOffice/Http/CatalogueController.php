<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\Catalogue\Items;
use App\Catalogue\Models\Brand;
use App\Catalogue\Models\Category;
use App\Catalogue\Models\CategoryTemplate;
use App\Catalogue\Models\Item;
use App\Catalogue\NameBuilder;
use App\Platform\Ulid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The item editor behind Review (spec section 6; wireframe ReviewNewItems): an item's category, brand,
 * facets and free text, chosen from the same pick-lists the counter uses, with its printed name always
 * from the name routine. Also the item search that merges and price setting use.
 */
final class CatalogueController
{
    use ActsForUser;

    /** The sellable categories, under their groups. */
    public function categories(Request $request): JsonResponse
    {
        $this->allowed($request, 'catalogue');
        /** @var list<object{id: string, name: string, word: ?string, group: ?string}> $rows */
        $rows = DB::table('categories as c')->leftJoin('categories as p', 'p.id', '=', 'c.parent_id')
            ->where('c.sellable', true)->where('c.code', '!=', '_not_in_list')
            ->orderByRaw('coalesce(p.position, c.position), c.position, c.name')->get(['c.id', 'c.name', 'c.word', 'p.name as group'])->all();

        return response()->json($rows);
    }

    /** What an item in a category is made of: each facet with its choices, in the order the counter asks. */
    public function form(Request $request, string $category): JsonResponse
    {
        $this->allowed($request, 'catalogue');
        abort_unless(Ulid::isValid($category), 404);
        $template = CategoryTemplate::query()->where('category_id', $category)->first();
        abort_if($template === null, 404);
        $keep = $request->query('keep');
        $keep = is_string($keep) ? array_filter(explode(',', $keep), Ulid::isValid(...)) : [];

        $facets = [];
        foreach ($template->facets as $facet) {
            /** @var array{key: string, label: string, type: string, list?: string} $facet */
            $options = match ($facet['type']) {
                'brand' => DB::table('brands')->where(fn ($q) => $q->where('status', 'approved')->orWhereIn('id', $keep))->orderBy('name')->get(['id', 'name'])->all(),
                'picklist' => DB::table('picklist_values')->where('list', $facet['list'] ?? '')
                    ->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', $keep))->orderBy('position')->orderBy('name')->get(['id', 'name'])->all(),
                default => null,
            };
            $facets[] = ['key' => $facet['key'], 'label' => $facet['label'], 'type' => $facet['type'], 'options' => $options];
        }

        return response()->json(['category_id' => $category, 'facets' => $facets]);
    }

    public function show(Request $request, string $item, Items $items): JsonResponse
    {
        $this->allowed($request, 'catalogue');
        $found = $this->item($item);

        return response()->json([
            'id' => $found->id, 'name' => $found->display_name, 'status' => $found->status, 'source' => $found->source,
            'category_id' => $found->category_id, 'brand_id' => $found->brand_id, 'free_text' => $found->free_text,
            'typed_category_word' => $found->typed_category_word, 'facets' => $items->facetsOf($found),
            'sales' => DB::table('sale_lines')->where('item_id', $found->id)->count(),
        ]);
    }

    /**
     * Saves an item's new parts. If they make it the same as another item, nothing changes and the other
     * item comes back, so the person can merge the two instead.
     */
    public function update(Request $request, string $item, Items $items): JsonResponse
    {
        $actor = $this->allowed($request, 'review');
        $found = $this->item($item);
        abort_if(in_array($found->status, ['merged', 'archived'], true), 422, 'This item is merged or archived.');
        if ($found->source === 'typed' && $found->status === 'pending_review') {
            $this->allowed($request, 'promote');
        }
        $data = $request->validate([
            'category_id' => ['required', 'string', 'size:26'],
            'brand_id' => ['nullable', 'string', 'size:26', 'exists:brands,id'],
            'facets' => ['present', 'array'],
            'facets.*' => ['nullable', 'string', 'max:80'],
            'free_text' => ['nullable', 'string', 'max:80'],
        ]);
        $category = Category::query()->where('id', $data['category_id'])->where('sellable', true)->where('code', '!=', '_not_in_list')->first();
        if ($category === null) {
            return response()->json(['message' => 'Choose the item\'s category.'], 422);
        }
        /** @var array<string, ?string> $raw */
        $raw = (array) $data['facets'];
        $facets = array_filter(array_map(fn (?string $v) => $v === null ? '' : $v, $raw), fn (string $v) => trim($v) !== '');
        unset($facets['brand']);
        $brand = isset($data['brand_id']) ? Brand::query()->whereKey((string) $data['brand_id'])->first() : null;
        // Reviewing an item takes it off the queue; an active item stays active.
        $status = in_array($found->status, ['pending_review', 'pending_category'], true) ? 'active' : $found->status;
        $freeText = isset($data['free_text']) ? (string) $data['free_text'] : null;
        if ($freeText !== null && trim($freeText) !== '' && $this->denied($freeText)) {
            return response()->json(['message' => 'Words such as "others" or "item" are not allowed in a name.'], 422);
        }

        try {
            $same = $items->change($found, $category, $facets, $brand, null, $freeText, null, $status, $actor->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($same !== null) {
            return response()->json(['message' => "That makes it the same as {$same->display_name}. Merge the two instead.", 'same' => ['id' => $same->id, 'name' => $same->display_name]], 409);
        }
        $found->refresh();

        return response()->json(['ok' => true, 'name' => $found->display_name]);
    }

    /** Items by name or code, for choosing the item to merge into or to price. */
    public function search(Request $request): JsonResponse
    {
        $this->allowed($request, 'catalogue');
        $q = NameBuilder::normalise($request->string('q')->toString());
        $except = $request->query('except');
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
        /** @var list<object{id: string, name: string, code: string, category: string}> $rows */
        $rows = DB::table('items as i')->join('categories as c', 'c.id', '=', 'i.category_id')
            ->whereNotIn('i.status', ['merged', 'archived'])
            ->where(fn ($w) => $w->where('i.search_text', 'like', $like)->orWhere('i.item_code', strtoupper($q))->orWhereRaw('i.search_text % ?', [$q]))
            ->when(is_string($except) && Ulid::isValid($except), fn ($w) => $w->where('i.id', '!=', $except))
            ->orderByRaw('similarity(i.search_text, ?) DESC', [$q])->limit(20)
            ->get(['i.id', 'i.display_name as name', 'i.item_code as code', 'c.name as category'])->all();

        return response()->json($rows);
    }

    /** The listed brands, or one pick-list's active values, for "Same as a listed brand / model". */
    public function names(Request $request, string $kind): JsonResponse
    {
        $this->allowed($request, 'names');
        if ($kind === 'brands') {
            return response()->json(DB::table('brands')->where('status', 'approved')->orderBy('name')->get(['id', 'name'])->all());
        }
        $list = $request->string('list')->toString();

        return response()->json(DB::table('picklist_values')->where('list', $list)->where('status', 'active')->orderBy('name')->get(['id', 'name'])->all());
    }

    private function item(string $id): Item
    {
        abort_unless(Ulid::isValid($id), 404);
        $found = Item::query()->find($id);
        abort_if($found === null, 404);

        return $found;
    }

    private function denied(string $text): bool
    {
        $seed = json_decode((string) @file_get_contents(config()->string('catalogue.seed')), true);
        $rules = is_array($seed) && is_array($seed['rules'] ?? null) ? $seed['rules'] : [];
        $list = is_array($rules['product_word_denylist'] ?? null) ? $rules['product_word_denylist'] : ['others', 'misc', 'item', 'test', 'unlisted'];
        $words = array_map(fn (mixed $w) => mb_strtolower(is_string($w) ? $w : ''), $list);

        return array_intersect(preg_split('/\s+/u', mb_strtolower($text)) ?: [], $words) !== [];
    }
}
