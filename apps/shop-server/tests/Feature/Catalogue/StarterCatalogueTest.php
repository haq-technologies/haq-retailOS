<?php

declare(strict_types=1);

use App\Catalogue\Models\CategoryTemplate;
use App\Catalogue\Shapes;
use App\Catalogue\StarterCatalogue;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

/*
 * Check E in spec section 14 [v1.3, S20]: loading starter catalogue v1.1 on a clean install creates the 34
 * categories, the 15 pick-lists with 466 phone models, the 73 brands plus No brand, the 29 models and the
 * 5 generator rules with no duplicates; the generators offer exactly 127 shapes; and running it a second
 * time changes nothing.
 */

beforeEach(function () {
    Shop::create();
    $this->created = app(StarterCatalogue::class)->load(config()->string('catalogue.seed'));
});

it('creates exactly what the seed holds', function () {
    expect(DB::table('categories')->count())->toBe(34);
    expect(DB::table('categories')->where('sellable', true)->count())->toBe(28);
    expect(DB::table('categories')->where('sellable', false)->count())->toBe(6);
    expect(DB::table('category_templates')->count())->toBe(28);

    expect(DB::table('picklist_values')->distinct()->count('list'))->toBe(15);
    expect(DB::table('picklist_values')->where('list', 'phone_models')->count())->toBe(466);
    expect(DB::table('picklist_values')->where('list', 'phone_families')->count())->toBe(19);
    expect(DB::table('picklist_values')->where('list', 'phone_models')->whereNull('parent_id')->count())->toBe(0);

    expect(DB::table('brands')->count())->toBe(74);
    expect(DB::table('brands')->where('seed_id', 'brand.no-brand')->value('name'))->toBe('No brand');
    expect(DB::table('product_models')->count())->toBe(29);
    expect(DB::table('category_templates')->whereNotNull('generator')->count())->toBe(5);
});

it('has no duplicates', function () {
    expect(DB::table('brands')->selectRaw('lower(name)')->groupByRaw('lower(name)')->havingRaw('count(*) > 1')->count())->toBe(0);
    expect(DB::table('picklist_values')->selectRaw('list, lower(name)')->groupByRaw('list, lower(name)')->havingRaw('count(*) > 1')->count())->toBe(0);
});

it('gives every sellable category a word and no parent a template', function () {
    expect(DB::table('categories')->where('sellable', true)->whereNull('word')->count())->toBe(0);
    $parents = DB::table('categories')->whereNotNull('parent_id')->distinct()->pluck('parent_id');
    expect(DB::table('categories')->whereIn('id', $parents)->where('sellable', true)->count())->toBe(0);
    expect(DB::table('category_templates')->whereIn('category_id', $parents)->count())->toBe(0);
});

it('offers exactly 127 generated shapes, as the seed says', function () {
    $counts = CategoryTemplate::query()->whereNotNull('generator')->get()
        ->mapWithKeys(fn (CategoryTemplate $t) => [DB::table('categories')->where('id', $t->getAttribute('category_id'))->value('code') => count(Shapes::for($t))])
        ->all();

    expect($counts)->toEqual(['cables' => 42, 'charger_head' => 10, 'complete_charger' => 50, 'car_chargers' => 10, 'power_banks' => 15]);
    expect(array_sum($counts))->toBe(127);
    // Shapes are offered, not stored: no item exists until a first sale.
    expect(DB::table('items')->count())->toBe(0);
});

it('changes nothing when loaded a second time (gate G2)', function () {
    $snapshot = fn () => collect(['categories', 'category_templates', 'picklist_values', 'brands', 'product_models', 'audit_log'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all()
        + ['updated' => DB::table('brands')->max('updated_at')];
    $before = $snapshot();

    $again = app(StarterCatalogue::class)->load(config()->string('catalogue.seed'));

    expect(array_sum($again))->toBe(0);
    expect($snapshot())->toBe($before);
});

it('never overwrites a local rename on a later load', function () {
    DB::table('brands')->where('seed_id', 'brand.oraimo')->update(['name' => 'Oraimo Nigeria']);

    app(StarterCatalogue::class)->load(config()->string('catalogue.seed'));

    expect(DB::table('brands')->where('seed_id', 'brand.oraimo')->value('name'))->toBe('Oraimo Nigeria');
});

it('loads from the command line, twice', function () {
    $this->artisan('catalogue:seed')->expectsOutputToContain('Nothing to change')->assertSuccessful();
});
