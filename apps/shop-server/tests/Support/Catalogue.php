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
}
