<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Catalogue\Items;
use App\Catalogue\Models\Category;
use App\Catalogue\Models\CategoryTemplate;
use App\Catalogue\Models\Item;

/**
 * A minimal catalogue for tests that need an item but not the whole starter catalogue.
 */
final class Catalogue
{
    public static function anyItem(string $text = 'test item'): Item
    {
        $category = Category::query()->firstOrCreate(['code' => 'test_things'], ['name' => 'Test things', 'word' => 'Thing', 'sellable' => true]);
        CategoryTemplate::query()->firstOrCreate(['category_id' => $category->id], [
            'facets' => [['key' => 'brand', 'label' => 'Brand', 'type' => 'brand']], 'drill_order' => ['brand'], 'name_order' => ['brand', '{word}'],
        ]);

        return app(Items::class)->findOrCreate($category, freeText: $text);
    }

    /**
     * An item in a small test category with two text facets, for sibling and range tests.
     *
     * @param  array<string, string>  $facets  model and size
     */
    public static function gadget(array $facets, string $category = 'test_gadgets'): Item
    {
        $model = Category::query()->firstOrCreate(['code' => $category], ['name' => 'Test gadgets', 'word' => 'Gadget', 'sellable' => true]);
        CategoryTemplate::query()->firstOrCreate(['category_id' => $model->id], [
            'facets' => [['key' => 'brand', 'label' => 'Brand', 'type' => 'brand'], ['key' => 'model', 'label' => 'Model', 'type' => 'text'], ['key' => 'size', 'label' => 'Size', 'type' => 'text']],
            'drill_order' => ['model', 'size'], 'name_order' => ['brand', 'model', '{word}', 'size'],
        ]);

        return app(Items::class)->findOrCreate($model, $facets);
    }
}
