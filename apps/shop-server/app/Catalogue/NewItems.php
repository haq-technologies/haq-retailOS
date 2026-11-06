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
use App\Identity\Role;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Items created at the counter (spec section 6, guardrails) [F11, F20, C2, v1.3].
 *
 * - A category is required; facets come from pickers; only the last piece is free text.
 * - A cashier creates items when the shop's setting is On; in Request-only mode the cashier sells a typed
 *   line instead: the category is still required, the text needs at least two words and 6 characters,
 *   and a denylist (others, misc, item, test and similar) is refused. It prints as the category word
 *   followed by the typed text, never the text alone. Managers, storekeepers and the owner always create.
 * - "Not in the list": the cashier types the product word (at least 3 characters, not on the denylist);
 *   it prints as the category word, never "Others", and waits for the owner to create the category.
 * - A typed brand or phone model prints as typed and waits, pending, for a manager or the owner to approve
 *   it; names are unique ignoring case, so "poga" never makes a second "Poga".
 * - Similar items are offered before a new one is made; the cashier can still create it.
 * - A cashier's new item is sellable at once and waits in the owner's review queue.
 */
final readonly class NewItems
{
    public const NOT_IN_LIST = '_not_in_list';

    public function __construct(
        private Items $items,
        private Settings $settings,
        private AuditLog $audit,
    ) {}

    /**
     * @param  array{category_id?: ?string, not_in_list_word?: ?string, facets?: array<string, string>, brand_id?: ?string, brand_text?: ?string, phone_model_text?: ?string, free_text?: ?string, confirm_new?: bool}  $input
     */
    public function create(array $input, User $actor): Item
    {
        $freeText = $this->clean($input['free_text'] ?? null);
        $requestOnly = $actor->role === Role::Cashier && $this->settings->get('catalogue.cashier_item_creation') === 'request_only';
        $word = $this->clean($input['not_in_list_word'] ?? null);

        if ($word !== null) {
            if (mb_strlen($word) < 3 || $this->denied($word)) {
                throw new NewItemRefused('Type the product word, at least 3 letters, such as "Solar light". Words such as "others" or "item" are not allowed.');
            }
            $category = $this->notInListCategory();
            $status = 'pending_category';
            $facets = [];
            $brand = null;
        } else {
            $category = Category::query()->find($input['category_id'] ?? '');
            if ($category === null || ! $category->sellable) {
                throw new NewItemRefused('Choose the item\'s category first.');
            }
            $facets = $requestOnly ? [] : ($input['facets'] ?? []);
            $brand = $requestOnly ? null : $this->brand($input, $actor);
            if (! $requestOnly) {
                $facets = $this->typedPhoneModel($category, $facets, $this->clean($input['phone_model_text'] ?? null), $actor);
            }
            $status = $actor->role === Role::Cashier ? 'pending_review' : 'active';
        }

        if ($requestOnly) {
            if ($freeText === null || mb_strlen($freeText) < 6 || count(preg_split('/\s+/u', $freeText) ?: []) < 2 || $this->denied($freeText)) {
                throw new NewItemRefused('Describe the item in at least two words and 6 letters, such as "Glass screen guard". Words such as "others" or "item" are not allowed.');
            }
        }

        try {
            if (! ($input['confirm_new'] ?? false)) {
                $similar = $this->similar($category, $this->items->previewName($category, $facets, $brand, null, $freeText, $word));
                if ($similar !== []) {
                    throw new NewItemRefused('There are items like this already. Pick one, or create the new item anyway.', $similar);
                }
            }

            return $this->items->findOrCreate($category, $facets, $brand, null, $freeText,
                source: $actor->role === Role::Cashier ? 'cashier' : 'owner', createdBy: $actor->id, status: $status, typedCategoryWord: $word);
        } catch (InvalidArgumentException $e) {
            throw new NewItemRefused($e->getMessage());
        }
    }

    /**
     * @param  array{brand_id?: ?string, brand_text?: ?string}  $input
     */
    private function brand(array $input, User $actor): ?Brand
    {
        if (isset($input['brand_id']) && $input['brand_id'] !== '') {
            return Brand::query()->find($input['brand_id']);
        }
        $typed = $this->clean($input['brand_text'] ?? null);
        if ($typed === null) {
            return null;
        }
        $existing = Brand::query()->whereRaw('lower(name) = ?', [mb_strtolower($typed)])->where('status', '!=', 'merged')->first();
        if ($existing !== null) {
            return $existing;
        }
        $brand = Brand::query()->create(['name' => $typed, 'status' => 'pending', 'source' => 'cashier', 'created_by' => $actor->id]);
        $this->audit->record(new AuditEntry(action: 'catalogue.brand_typed', actorId: $actor->id, subjectType: 'brand', subjectId: $brand->id, after: ['name' => $typed]));

        return $brand;
    }

    /**
     * @param  array<string, string>  $facets
     * @return array<string, string>
     */
    private function typedPhoneModel(Category $category, array $facets, ?string $typed, User $actor): array
    {
        if ($typed === null) {
            return $facets;
        }
        $template = CategoryTemplate::query()->where('category_id', $category->id)->first();
        $definition = $template === null ? null : (array_column($template->facets, null, 'key')['phone_model'] ?? null);
        if (! is_array($definition) || ! ($definition['allow_typed'] ?? false) || ! is_string($definition['list'] ?? null)) {
            throw new NewItemRefused('Pick the phone model from the list.');
        }
        $list = $definition['list'];
        if (mb_strlen($typed) < 2 || mb_strlen($typed) > 60) {
            throw new NewItemRefused('Type the phone model as it is written on the box, such as "Tecno Spark 20".');
        }
        $value = PicklistValue::query()->where('list', $list)->whereRaw('lower(name) = ?', [mb_strtolower($typed)])->first();
        if ($value === null) {
            $value = PicklistValue::query()->create(['list' => $list, 'name' => $typed, 'status' => 'pending', 'source' => 'cashier']);
            $this->audit->record(new AuditEntry(action: 'catalogue.phone_model_typed', actorId: $actor->id, subjectType: 'picklist_value', subjectId: $value->id, after: ['name' => $typed]));
        }

        return [...$facets, 'phone_model' => $value->id];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function similar(Category $category, string $name): array
    {
        /** @var list<object{id: string, display_name: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT id, display_name FROM items
            WHERE category_id = ? AND status NOT IN ('merged', 'archived') AND similarity(search_text, ?) > 0.45
            ORDER BY similarity(search_text, ?) DESC LIMIT 5
            SQL, [$category->id, NameBuilder::normalise($name), NameBuilder::normalise($name)]);

        return array_map(fn (object $r) => ['id' => $r->id, 'name' => $r->display_name], $rows);
    }

    /** The holding category for "Not in the list" items; never shown as a tile, never printed. */
    private function notInListCategory(): Category
    {
        $category = Category::query()->firstOrCreate(['code' => self::NOT_IN_LIST], ['name' => 'Not in the list', 'word' => 'Item', 'sellable' => true, 'position' => 9999]);
        CategoryTemplate::query()->firstOrCreate(['category_id' => $category->id], [
            'facets' => [['key' => 'brand', 'label' => 'Brand', 'type' => 'brand']], 'drill_order' => [], 'name_order' => ['brand', '{word}'],
        ]);

        return $category;
    }

    private function denied(string $text): bool
    {
        static $denylist = null;
        if ($denylist === null) {
            $seed = json_decode((string) @file_get_contents(config()->string('catalogue.seed')), true);
            $rules = is_array($seed) && is_array($seed['rules'] ?? null) ? $seed['rules'] : [];
            $list = is_array($rules['product_word_denylist'] ?? null) ? $rules['product_word_denylist'] : ['others', 'misc', 'item', 'test', 'unlisted'];
            $denylist = array_map(fn (mixed $w) => mb_strtolower(is_string($w) ? $w : ''), $list);
        }
        foreach (preg_split('/\s+/u', mb_strtolower($text)) ?: [] as $word) {
            if (in_array($word, $denylist, true)) {
                return true;
            }
        }

        return false;
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $text === null || $text === '' ? null : $text;
    }
}
