<?php

declare(strict_types=1);

use App\Catalogue\Items;
use App\Catalogue\Models\Brand;
use App\Catalogue\Models\Category;
use App\Catalogue\Models\PicklistValue;
use App\Catalogue\NameBuilder;
use App\Catalogue\StarterCatalogue;
use Tests\Support\Shop;

/*
 * The one name routine (spec section 6) [F20, C1, FU4]: every printed name comes from it.
 */

it('prints the spec\'s examples', function (array $order, array $parts, string $word, string $printed) {
    expect(NameBuilder::build($order, $word, $parts))->toBe($printed);
})->with([
    'earbuds' => [['brand', 'model', '{word}'], ['brand' => 'Oraimo', 'model' => 'FreePods 3C'], 'Earbuds', 'Oraimo FreePods 3C Earbuds'],
    'cable, no brand' => [['brand', 'connector_pair', '{word}', 'length', 'rated_power'], ['connector_pair' => 'Type-C to Lightning', 'rated_power' => '35W'], 'Cable', 'Type-C to Lightning Cable 35W'],
    'cable with brand' => [['brand', 'connector_pair', '{word}', 'length', 'rated_power'], ['brand' => 'Anker', 'connector_pair' => 'Type-C to Type-C', 'rated_power' => '60W'], 'Cable', 'Anker Type-C to Type-C Cable 60W'],
    'power bank' => [['brand', 'model', '{word}', 'capacity'], ['brand' => 'Anker', 'capacity' => '10000mAh'], 'Power Bank', 'Anker Power Bank 10000mAh'],
    'pouch, spec before word' => [['brand', 'phone_model', 'case_type', '{word}'], ['phone_model' => 'iPhone 11', 'case_type' => 'Silicone'], 'Pouch', 'iPhone 11 Silicone Pouch'],
]);

it('skips blanks and tidies spaces, and puts free text last', function () {
    expect(NameBuilder::build(['brand', '{word}', 'size'], 'Ring Light', ['brand' => '  ', 'size' => ' 10   inch '], 'with remote'))
        ->toBe('Ring Light 10 inch with remote');
});

it('never prints free text alone: the category word comes first', function () {
    expect(NameBuilder::build(['brand', '{word}'], 'Solar Light', [], 'garden lamp'))->toBe('Solar Light garden lamp');
});

it('builds every seed example exactly as the seed prints it', function () {
    Shop::create();
    app(StarterCatalogue::class)->load(config()->string('catalogue.seed'));
    $seed = json_decode((string) file_get_contents(config()->string('catalogue.seed')), true);
    $items = app(Items::class);
    $checked = 0;

    foreach ($seed['categories'] as $category) {
        if (! isset($category['example'])) {
            continue;
        }
        $model = Category::query()->where('code', $category['code'])->firstOrFail();
        $facets = [];
        $brand = null;
        foreach ($category['example']['values'] as $key => $value) {
            if ($key === 'brand') {
                $brand = Brand::query()->where('seed_id', $value)->firstOrFail();
            } elseif (str_contains((string) $value, '.')) {
                $facets[$key] = PicklistValue::query()->where('seed_id', $value)->firstOrFail()->id;
            } else {
                $facets[$key] = (string) $value;
            }
        }

        $item = $items->findOrCreate($model, $facets, $brand);
        expect($item->getAttribute('display_name'))->toBe($category['example']['prints'], "{$category['code']} prints wrongly");
        $checked++;
    }

    expect($checked)->toBe(28);
});

it('prints No brand as nothing (C3) and a typed brand as typed', function () {
    Shop::create();
    app(StarterCatalogue::class)->load(config()->string('catalogue.seed'));
    $cases = Category::query()->where('code', 'cases')->firstOrFail();
    $iphone11 = PicklistValue::query()->where('seed_id', 'phone_models.iphone-11')->firstOrFail();
    $items = app(Items::class);

    $noBrand = Brand::query()->where('seed_id', 'brand.no-brand')->firstOrFail();
    expect($items->findOrCreate($cases, ['phone_model' => $iphone11->id], $noBrand)->getAttribute('display_name'))->toBe('iPhone 11 Pouch');
    expect($items->findOrCreate($cases, ['phone_model' => $iphone11->id], brandText: 'Poga')->getAttribute('display_name'))->toBe('Poga iPhone 11 Pouch');
});
