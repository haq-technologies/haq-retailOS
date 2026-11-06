<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Catalogue\Models\Brand;
use App\Catalogue\Models\Category;
use App\Catalogue\Models\CategoryTemplate;
use App\Catalogue\Models\Item;
use App\Catalogue\Models\ItemFacet;
use App\Catalogue\Models\PicklistValue;
use App\Platform\Ulid;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Items: flat rows with an identity key that makes a duplicate impossible (spec sections 5 and 6) [R1].
 * An item is created on demand (a generated shape on its first sale, or a new item at the counter); asking
 * for one that exists returns it. Its printed name always comes from the name routine.
 */
final readonly class Items
{
    /** Item codes avoid 0, O, 1 and I, which are easy to misread. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __construct(private AuditLog $audit) {}

    /**
     * @param  array<string, string>  $facets  Facet key => pick-list value ID (for a pick-list facet) or typed text.
     */
    public function findOrCreate(
        Category $category,
        array $facets = [],
        ?Brand $brand = null,
        ?string $brandText = null,
        ?string $freeText = null,
        string $source = 'owner',
        ?string $createdBy = null,
        string $status = 'active',
        ?string $typedCategoryWord = null,
    ): Item {
        $template = CategoryTemplate::query()->where('category_id', $category->id)->first()
            ?? throw new InvalidArgumentException("{$category->name} is not a sellable category.");

        $resolved = $this->resolveFacets($template, $facets);
        $key = $this->identityKey($category->id, $brand?->id, $brandText, $resolved, $freeText)
            .($typedCategoryWord === null ? '' : '|word:'.NameBuilder::normalise($typedCategoryWord));

        return DB::transaction(function () use ($category, $template, $resolved, $key, $brand, $brandText, $freeText, $source, $createdBy, $status, $typedCategoryWord): Item {
            // Concurrent creates of the same item meet at this lock, so the second finds the first.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['item:'.$key]);
            $existing = Item::query()->where('identity_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }

            $name = $this->name($category, $template, $brand, $brandText, $resolved, $freeText, $typedCategoryWord);
            $item = Item::query()->create([
                'category_id' => $category->id,
                'brand_id' => $brand?->id,
                'brand_text' => $brandText,
                'free_text' => $freeText,
                'typed_category_word' => $typedCategoryWord,
                'item_code' => $this->newCode(),
                'display_name' => $name,
                'search_text' => NameBuilder::normalise($name),
                'identity_key' => $key,
                'status' => $status,
                'source' => $source,
                'created_by' => $createdBy,
            ]);
            foreach ($resolved as $facetKey => $value) {
                ItemFacet::query()->create([
                    'id' => Ulid::generate(),
                    'item_id' => $item->id,
                    'facet_key' => $facetKey,
                    'picklist_value_id' => $value['value_id'],
                    'text_value' => $value['text'],
                ]);
            }
            $this->audit->record(new AuditEntry(action: 'catalogue.item_created', actorId: $createdBy, subjectType: 'item', subjectId: $item->id,
                after: ['name' => $name, 'code' => $item->item_code, 'source' => $source]));

            return $item;
        });
    }

    /**
     * The name an item would print with, before it exists: for the similar-item check.
     *
     * @param  array<string, string>  $facets
     */
    public function previewName(Category $category, array $facets, ?Brand $brand, ?string $brandText, ?string $freeText, ?string $typedCategoryWord = null): string
    {
        $template = CategoryTemplate::query()->where('category_id', $category->id)->firstOrFail();

        return $this->name($category, $template, $brand, $brandText, $this->resolveFacets($template, $facets), $freeText, $typedCategoryWord);
    }

    /** Rebuilds an item's cached name and search text from its parts. Returns true if they changed. */
    public function rebuildName(Item $item): bool
    {
        $category = Category::query()->whereKey($item->category_id)->firstOrFail();
        $template = CategoryTemplate::query()->where('category_id', $category->id)->firstOrFail();
        $brand = $item->brand_id === null ? null : Brand::query()->find($item->brand_id);

        $resolved = [];
        foreach (ItemFacet::query()->where('item_id', $item->id)->get() as $facet) {
            $resolved[$facet->facet_key] = ['value_id' => $facet->picklist_value_id, 'text' => $facet->text_value];
        }
        ksort($resolved);

        $name = $this->name($category, $template, $brand, $item->brand_text, $resolved, $item->free_text, $item->typed_category_word);
        if ($name === $item->display_name) {
            return false;
        }
        $item->update(['display_name' => $name, 'search_text' => NameBuilder::normalise($name)]);

        return true;
    }

    /**
     * @param  array<string, array{value_id: ?string, text: ?string}>  $resolved
     */
    private function name(Category $category, CategoryTemplate $template, ?Brand $brand, ?string $brandText, array $resolved, ?string $freeText, ?string $typedCategoryWord = null): string
    {
        $parts = ['brand' => $brand === null
            ? $brandText
            : ($brand->seed_id === config('catalogue.no_brand_seed_id') ? null : $brand->name)];

        $valueIds = array_filter(array_column($resolved, 'value_id'));
        /** @var array<string, string> $names */
        $names = $valueIds === [] ? [] : PicklistValue::query()->whereIn('id', $valueIds)->pluck('name', 'id')->all();
        foreach ($resolved as $key => $value) {
            $parts[$key] = $value['value_id'] !== null ? ($names[$value['value_id']] ?? null) : $value['text'];
        }

        // "Not in the list": the cashier's product word prints as the category word, never "Others" [v1.3].
        return NameBuilder::build($template->name_order, $typedCategoryWord ?? $category->word ?? $category->name, $parts, $freeText);
    }

    /**
     * Checks each facet against the template: a pick-list facet takes a value from its own list, a text
     * facet takes text, and the brand is passed separately.
     *
     * @param  array<string, string>  $facets
     * @return array<string, array{value_id: ?string, text: ?string}>
     */
    private function resolveFacets(CategoryTemplate $template, array $facets): array
    {
        $byKey = array_column($template->facets, null, 'key');

        $resolved = [];
        foreach ($facets as $key => $value) {
            $definition = $byKey[$key] ?? throw new InvalidArgumentException("This category has no {$key}.");
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            if ($definition['type'] === 'picklist') {
                $known = PicklistValue::query()->whereKey($value)->where('list', $definition['list'] ?? '')->exists();
                if (! $known) {
                    throw new InvalidArgumentException("That is not one of the {$key} values.");
                }
                $resolved[$key] = ['value_id' => $value, 'text' => null];
            } elseif ($definition['type'] === 'text') {
                $resolved[$key] = ['value_id' => null, 'text' => $value];
            } else {
                throw new InvalidArgumentException("Pass the {$key} as the item's brand.");
            }
        }
        ksort($resolved);

        return $resolved;
    }

    /**
     * @param  array<string, array{value_id: ?string, text: ?string}>  $resolved
     */
    private function identityKey(string $categoryId, ?string $brandId, ?string $brandText, array $resolved, ?string $freeText): string
    {
        $brand = $brandId !== null ? "b:{$brandId}" : ($brandText !== null && trim($brandText) !== '' ? 't:'.NameBuilder::normalise($brandText) : '');
        $facets = [];
        foreach ($resolved as $key => $value) {
            $facets[] = $key.'='.($value['value_id'] !== null ? 'v:'.$value['value_id'] : 't:'.NameBuilder::normalise((string) $value['text']));
        }

        return implode('|', ["c:{$categoryId}", "brand:{$brand}", ...$facets, 'free:'.NameBuilder::normalise($freeText ?? '')]);
    }

    private function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (Item::query()->where('item_code', $code)->exists());

        return $code;
    }
}
