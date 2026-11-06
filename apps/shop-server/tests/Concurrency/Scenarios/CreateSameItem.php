<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Catalogue\Items;
use App\Catalogue\Models\Brand;
use App\Catalogue\Models\Category;
use Tests\Concurrency\Scenario;

/** Creates the same new item that another counter is creating at the same moment. */
final class CreateSameItem implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $category = Category::query()->where('code', 'earbuds')->firstOrFail();
        $brand = Brand::query()->where('seed_id', 'brand.oraimo')->firstOrFail();
        $item = app(Items::class)->findOrCreate($category, ['model' => 'FreePods Pro X'], $brand, source: 'cashier');

        return ['item' => $item->id];
    }
}
