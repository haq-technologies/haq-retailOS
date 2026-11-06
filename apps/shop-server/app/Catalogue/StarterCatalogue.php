<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Catalogue\Models\Brand;
use App\Catalogue\Models\Category;
use App\Catalogue\Models\CategoryTemplate;
use App\Catalogue\Models\PicklistValue;
use App\Catalogue\Models\ProductModel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads the starter catalogue (spec section 6) [S20, C1 to C13]. Every entry is matched by its permanent
 * seed ID [C9]: a missing entry is created, an existing one is left as it is, so a local rename is never
 * overwritten and loading the seed a second time changes nothing (gate G2). Taxonomy only: no prices,
 * costs or barcodes.
 */
final readonly class StarterCatalogue
{
    public function __construct(private AuditLog $audit) {}

    /**
     * @return array<string, int> How many of each kind were created; all zero on a second run.
     */
    public function load(string $path): array
    {
        $seed = $this->read($path);

        return DB::transaction(function () use ($seed): array {
            $created = ['brands' => 0, 'picklist_values' => 0, 'product_models' => 0, 'categories' => 0, 'category_templates' => 0];

            // Brands, with No brand first (C3).
            $brands = [$seed['no_brand'] + ['aliases' => [], 'shown_first_in' => []], ...$seed['brands']];
            $brandIds = Brand::query()->whereNotNull('seed_id')->pluck('id', 'seed_id')->all();
            foreach ($brands as $brand) {
                if (! isset($brandIds[$brand['id']])) {
                    $brandIds[$brand['id']] = Brand::query()->create([
                        'seed_id' => $brand['id'], 'name' => $brand['name'], 'aliases' => $brand['aliases'] ?? [],
                        'shown_first_in' => $brand['shown_first_in'] ?? [], 'status' => 'approved', 'source' => 'starter',
                    ])->id;
                    $created['brands']++;
                }
            }

            // Pick-lists: phone families before phone models, which point at them.
            $valueIds = PicklistValue::query()->whereNotNull('seed_id')->pluck('id', 'seed_id')->all();
            $lists = $seed['picklists'];
            uksort($lists, fn (string $a, string $b) => ($a === 'phone_models') <=> ($b === 'phone_models'));
            foreach ($lists as $list => $definition) {
                foreach ($definition['values'] as $position => $value) {
                    if (isset($valueIds[$value['id']])) {
                        continue;
                    }
                    $parent = isset($value['family']) ? ($valueIds[$value['family']] ?? throw new RuntimeException("Unknown family {$value['family']}")) : null;
                    $valueIds[$value['id']] = PicklistValue::query()->create([
                        'seed_id' => $value['id'], 'list' => $list, 'name' => $value['name'], 'aliases' => $value['aliases'] ?? [],
                        'parent_id' => $parent, 'status' => 'active', 'source' => 'starter', 'position' => $position,
                    ])->id;
                    $created['picklist_values']++;
                }
            }

            $modelIds = ProductModel::query()->whereNotNull('seed_id')->pluck('id', 'seed_id')->all();
            foreach ($seed['models'] as $model) {
                if (! isset($modelIds[$model['id']])) {
                    ProductModel::query()->create([
                        'seed_id' => $model['id'], 'name' => $model['name'], 'categories' => $model['categories'],
                        'brand_id' => $model['brand'] === null ? null : ($brandIds[$model['brand']] ?? throw new RuntimeException("Unknown brand {$model['brand']}")),
                        'status' => 'active', 'source' => 'starter',
                    ]);
                    $created['product_models']++;
                }
            }

            // Categories: parents first, then their children; a template for every sellable one.
            $categoryIds = Category::query()->pluck('id', 'code')->all();
            $generators = [];
            foreach ($seed['generators'] as $rule) {
                $generators[$rule['category']] = ['combine' => $rule['combine'], 'blank_allowed' => $rule['blank_allowed'], 'exclude' => (object) $rule['exclude']];
            }
            $ordered = $seed['categories'];
            usort($ordered, fn (array $a, array $b) => ($a['parent'] !== null) <=> ($b['parent'] !== null));
            foreach ($ordered as $position => $category) {
                if (! isset($categoryIds[$category['code']])) {
                    $categoryIds[$category['code']] = Category::query()->create([
                        'code' => $category['code'], 'name' => $category['name'], 'word' => $category['word'],
                        'sellable' => $category['sellable'], 'aliases' => $category['aliases'] ?? [], 'position' => $position,
                        'parent_id' => $category['parent'] === null ? null : ($categoryIds[$category['parent']] ?? throw new RuntimeException("Unknown parent {$category['parent']}")),
                    ])->id;
                    $created['categories']++;
                }
                if ($category['sellable'] && ! CategoryTemplate::query()->where('category_id', $categoryIds[$category['code']])->exists()) {
                    CategoryTemplate::query()->create([
                        'category_id' => $categoryIds[$category['code']],
                        'facets' => $category['facets'], 'drill_order' => $category['drill_order'], 'name_order' => $category['name_order'],
                        'generator' => $generators[$category['code']] ?? null,
                    ]);
                    $created['category_templates']++;
                }
            }

            if (array_sum($created) > 0) {
                $this->audit->record(new AuditEntry(action: 'catalogue.starter_loaded', device: 'server',
                    after: ['version' => $seed['version'], 'created' => $created]));
            }

            return $created;
        });
    }

    /**
     * @return array{version: string, no_brand: array{id: string, name: string}, brands: list<array{id: string, name: string, aliases?: list<string>, shown_first_in?: list<string>}>, picklists: array<string, array{values: list<array{id: string, name: string, aliases?: list<string>, family?: string}>}>, models: list<array{id: string, brand: ?string, name: string, categories: list<string>}>, categories: list<array{code: string, name: string, parent: ?string, sellable: bool, word: ?string, aliases?: list<string>, facets: list<array<string, string>>, drill_order: list<string>, name_order: list<string>}>, generators: list<array{category: string, combine: list<string>, blank_allowed: list<string>, exclude: array<string, list<string>>}>}
     */
    private function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("The starter catalogue is not at {$path}. Set CATALOGUE_SEED.");
        }
        $seed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($seed) || ($seed['schema_version'] ?? null) !== 2) {
            throw new RuntimeException('This shop server reads starter catalogue schema version 2 only.');
        }

        /** @phpstan-ignore return.type (the seed is verified in CI by tools/catalogue/verify_v11.py) */
        return $seed;
    }
}
