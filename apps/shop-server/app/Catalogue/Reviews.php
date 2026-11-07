<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Catalogue\Models\Brand;
use App\Catalogue\Models\Category;
use App\Catalogue\Models\CategoryTemplate;
use App\Catalogue\Models\Item;
use App\Catalogue\Models\PicklistValue;
use App\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The answers to the review queue (spec sections 3 and 6) [C2, v1.3; wireframes ReviewNames and
 * ReviewNewItems]: approving or merging a brand or phone model a cashier typed, creating a category for a
 * product word typed under "Not in the list", and approving an item a cashier created. Nothing here changes
 * a sale: receipts keep what they printed. Where an answer makes an item the same as another, the two are
 * merged (and the merge can be undone). Every answer is audited.
 */
final readonly class Reviews
{
    public function __construct(private Items $items, private Merges $merges, private AuditLog $audit) {}

    public function approveBrand(Brand $brand, User $actor): void
    {
        if ($brand->status !== 'pending') {
            throw new MergeRefused("{$brand->name} is not waiting for review.");
        }
        DB::transaction(function () use ($brand, $actor): void {
            $brand->update(['status' => 'approved']);
            $this->audit->record(new AuditEntry(action: 'catalogue.brand_approved', actorId: $actor->id, subjectType: 'brand', subjectId: $brand->id, after: ['name' => $brand->name]));
        });
    }

    /**
     * A typed brand that is a listed one spelt differently: its items move to the listed brand, and any
     * that become the same as an existing item are merged into it.
     *
     * @return array{moved: int, merged: int}
     */
    public function mergeBrand(Brand $brand, Brand $into, User $actor): array
    {
        if ($brand->status !== 'pending') {
            throw new MergeRefused("{$brand->name} is not waiting for review.");
        }
        if ($into->id === $brand->id || $into->status !== 'approved') {
            throw new MergeRefused('Choose a brand from the list.');
        }

        return DB::transaction(function () use ($brand, $into, $actor): array {
            $counts = ['moved' => 0, 'merged' => 0];
            foreach (Item::query()->where('brand_id', $brand->id)->where('status', '!=', 'merged')->orderBy('id')->get() as $item) {
                $this->moveOrMerge($item, fn (Item $i) => $this->items->change($i, $this->category($i), $this->items->facetsOf($i), $into, null, $i->free_text,
                    $i->typed_category_word, $i->status, $actor->id), $actor, $counts);
            }
            $brand->update(['status' => 'merged', 'merged_into_id' => $into->id]);
            $this->audit->record(new AuditEntry(action: 'catalogue.brand_merged', actorId: $actor->id, subjectType: 'brand', subjectId: $brand->id,
                before: ['name' => $brand->name], after: ['into' => $into->id, 'into_name' => $into->name, ...$counts]));

            return $counts;
        });
    }

    public function approveModel(PicklistValue $value, User $actor): void
    {
        if ($value->status !== 'pending') {
            throw new MergeRefused("{$value->name} is not waiting for review.");
        }
        DB::transaction(function () use ($value, $actor): void {
            $value->update(['status' => 'active']);
            $this->audit->record(new AuditEntry(action: 'catalogue.phone_model_approved', actorId: $actor->id, subjectType: 'picklist_value', subjectId: $value->id, after: ['name' => $value->name, 'list' => $value->list]));
        });
    }

    /**
     * A typed phone model that is a listed one written differently: its items take the listed model.
     *
     * @return array{moved: int, merged: int}
     */
    public function mergeModel(PicklistValue $value, PicklistValue $into, User $actor): array
    {
        if ($value->status !== 'pending') {
            throw new MergeRefused("{$value->name} is not waiting for review.");
        }
        if ($into->id === $value->id || $into->list !== $value->list || $into->status !== 'active') {
            throw new MergeRefused('Choose a phone model from the same list.');
        }

        return DB::transaction(function () use ($value, $into, $actor): array {
            $counts = ['moved' => 0, 'merged' => 0];
            $ids = DB::table('item_facets')->where('picklist_value_id', $value->id)->pluck('item_id')->all();
            foreach (Item::query()->whereIn('id', $ids)->where('status', '!=', 'merged')->orderBy('id')->get() as $item) {
                $facets = array_map(fn (string $v) => $v === $value->id ? $into->id : $v, $this->items->facetsOf($item));
                $this->moveOrMerge($item, fn (Item $i) => $this->items->change($i, $this->category($i), $facets, $this->brand($i), $i->brand_text, $i->free_text,
                    $i->typed_category_word, $i->status, $actor->id), $actor, $counts);
            }
            $value->update(['status' => 'archived']);
            $this->audit->record(new AuditEntry(action: 'catalogue.phone_model_merged', actorId: $actor->id, subjectType: 'picklist_value', subjectId: $value->id,
                before: ['name' => $value->name], after: ['into' => $into->id, 'into_name' => $into->name, ...$counts]));

            return $counts;
        });
    }

    /**
     * The owner creates a category for a product word cashiers typed under "Not in the list" (spec section
     * 3: only the owner creates categories). Every item waiting with the typed word moves into it; the
     * category's word is what its receipts print, which the owner may spell differently.
     *
     * @return array{category: Category, moved: int, merged: int}
     */
    public function createCategory(string $typed, string $word, string $name, ?Category $parent, User $actor): array
    {
        $word = trim(preg_replace('/\s+/u', ' ', $word) ?? '');
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (mb_strlen($word) < 3 || mb_strlen($name) < 3 || mb_strlen($name) > 60 || mb_strlen($word) > 40) {
            throw new MergeRefused('Give the category a name and the word its receipts print, each 3 to 40 letters.');
        }
        if ($parent !== null && $parent->sellable) {
            throw new MergeRefused('Put the new category under a group, not under a sellable category.');
        }
        if (Category::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new MergeRefused("There is already a category called {$name}.");
        }

        return DB::transaction(function () use ($typed, $word, $name, $parent, $actor): array {
            $base = Str::slug($name, '_');
            $code = $base === '' ? 'category' : mb_substr($base, 0, 30);
            for ($n = 2; Category::query()->where('code', $code)->exists(); $n++) {
                $code = mb_substr($base, 0, 30).'_'.$n;
            }
            $category = Category::query()->create([
                'code' => $code, 'parent_id' => $parent?->id, 'name' => $name, 'word' => $word, 'sellable' => true, 'aliases' => [],
                'position' => (is_numeric($top = Category::query()->max('position')) ? (int) $top : 0) + 1,
            ]);
            CategoryTemplate::query()->create([
                'category_id' => $category->id, 'facets' => [['key' => 'brand', 'label' => 'Brand', 'type' => 'brand']],
                'drill_order' => ['brand'], 'name_order' => ['brand', NameBuilder::WORD],
            ]);
            $counts = ['moved' => 0, 'merged' => 0];
            $waiting = Item::query()->where('status', 'pending_category')->whereRaw('lower(typed_category_word) = ?', [mb_strtolower(trim($typed))])->orderBy('id')->get();
            foreach ($waiting as $item) {
                $this->moveOrMerge($item, fn (Item $i) => $this->items->change($i, $category, [], $this->brand($i), $i->brand_text, $i->free_text, null, 'active', $actor->id),
                    $actor, $counts);
            }
            $this->audit->record(new AuditEntry(action: 'catalogue.category_created', actorId: $actor->id, subjectType: 'category', subjectId: $category->id,
                after: ['name' => $name, 'word' => $word, 'typed' => $typed, 'code' => $code, 'parent' => $parent?->id, ...$counts]));

            return ['category' => $category, ...$counts];
        });
    }

    /** "Looks right": a cashier's new item leaves the review queue as it is. */
    public function approveItem(Item $item, User $actor): void
    {
        if ($item->status !== 'pending_review') {
            throw new MergeRefused("{$item->display_name} is not waiting for review.");
        }
        DB::transaction(function () use ($item, $actor): void {
            $item->update(['status' => 'active']);
            $this->audit->record(new AuditEntry(action: 'catalogue.item_approved', actorId: $actor->id, subjectType: 'item', subjectId: $item->id, after: ['name' => $item->display_name]));
        });
    }

    /**
     * Applies a change to an item; if the change would make it the same as another item, merges it into
     * that one instead.
     *
     * @param  callable(Item): ?Item  $change
     * @param  array{moved: int, merged: int}  $counts
     */
    private function moveOrMerge(Item $item, callable $change, User $actor, array &$counts): void
    {
        $same = $change($item);
        if ($same === null) {
            $counts['moved']++;

            return;
        }
        $this->merges->merge($item->id, $same->id, $actor);
        $counts['merged']++;
    }

    private function category(Item $item): Category
    {
        return Category::query()->findOrFail($item->category_id);
    }

    private function brand(Item $item): ?Brand
    {
        return $item->brand_id === null ? null : Brand::query()->find($item->brand_id);
    }
}
