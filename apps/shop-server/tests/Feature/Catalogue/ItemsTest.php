<?php

declare(strict_types=1);

use App\Catalogue\Items;
use App\Catalogue\Models\Brand;
use App\Catalogue\Models\Category;
use App\Catalogue\Models\PicklistValue;
use App\Catalogue\Renames;
use App\Catalogue\StarterCatalogue;
use App\Platform\Ulid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

/*
 * Flat items with a uniqueness key (spec sections 5 and 6) [R1, C9].
 */

beforeEach(function () {
    Shop::create();
    app(StarterCatalogue::class)->load(config()->string('catalogue.seed'));
    $this->items = app(Items::class);
    $this->cables = Category::query()->where('code', 'cables')->firstOrFail();
    $this->anker = Brand::query()->where('seed_id', 'brand.anker')->firstOrFail();
    $this->c2l = PicklistValue::query()->where('seed_id', 'connector_pairs.type-c-to-lightning')->firstOrFail();
    $this->oneMetre = PicklistValue::query()->where('seed_id', 'cable_lengths.1m')->firstOrFail();
});

it('makes a second identical item impossible: asking again returns the first', function () {
    $first = $this->items->findOrCreate($this->cables, ['connector_pair' => $this->c2l->id, 'length' => $this->oneMetre->id], $this->anker);
    $again = $this->items->findOrCreate($this->cables, ['length' => $this->oneMetre->id, 'connector_pair' => $this->c2l->id], $this->anker);

    expect($again->id)->toBe($first->id);
    expect(DB::table('items')->count())->toBe(1);
    expect(DB::table('items')->where('id', $first->id)->value('item_code'))->toMatch('/^[2-9A-HJ-NP-Z]{6}$/');
});

it('treats typed text the same however it is spaced or cased', function () {
    $ringLights = Category::query()->where('code', 'ring_light')->firstOrFail();
    $a = $this->items->findOrCreate($ringLights, ['size' => '10 inch']);
    $b = $this->items->findOrCreate($ringLights, ['size' => ' 10  INCH ']);
    expect($b->id)->toBe($a->id);
});

it('refuses a value from the wrong pick-list, and a facet the category does not have', function () {
    expect(fn () => $this->items->findOrCreate($this->cables, ['connector_pair' => $this->oneMetre->id]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->items->findOrCreate($this->cables, ['colour' => 'Black']))->toThrow(InvalidArgumentException::class, 'This category has no colour');
});

it('refuses a parent category, which is never sellable', function () {
    $wallChargers = Category::query()->where('code', 'wall_chargers')->firstOrFail();
    $this->items->findOrCreate($wallChargers);
})->throws(InvalidArgumentException::class, 'is not a sellable category');

it('rebuilds every name that uses a renamed value, with one audit entry', function () {
    $item = $this->items->findOrCreate($this->cables, ['connector_pair' => $this->c2l->id, 'length' => $this->oneMetre->id], $this->anker);
    $audit = DB::table('audit_log')->count();

    $rebuilt = app(Renames::class)->picklistValue($this->oneMetre, '1 m');

    expect($rebuilt)->toBe(1);
    expect(DB::table('items')->where('id', $item->id)->value('display_name'))->toBe('Anker Type-C to Lightning Cable 1 m');
    expect(DB::table('audit_log')->count())->toBe($audit + 1);
    expect(DB::table('audit_log')->latest('recorded_at')->value('action'))->toBe('catalogue.picklist_value_renamed');
});

it('rebuilds names when a category word is renamed', function () {
    $item = $this->items->findOrCreate($this->cables, ['connector_pair' => $this->c2l->id], $this->anker);
    app(Renames::class)->categoryWord($this->cables, 'Data Cable');
    expect(DB::table('items')->where('id', $item->id)->value('display_name'))->toBe('Anker Type-C to Lightning Data Cable');
});

it('keeps a brand name unique ignoring case', function () {
    DB::table('brands')->insert(['id' => Ulid::generate(), 'name' => 'anker', 'created_at' => now(), 'updated_at' => now()]);
})->throws(QueryException::class, 'brands_one_name');

it('never changes an item code, a category code or a seed ID', function () {
    $item = $this->items->findOrCreate($this->cables, ['connector_pair' => $this->c2l->id], $this->anker);

    expect(fn () => DB::transaction(fn () => DB::table('items')->where('id', $item->id)->update(['item_code' => 'ZZZZZZ'])))
        ->toThrow(QueryException::class, 'items.item_code never changes');
    expect(fn () => DB::transaction(fn () => DB::table('categories')->where('code', 'cables')->update(['code' => 'leads'])))
        ->toThrow(QueryException::class, 'categories.code never changes');
    expect(fn () => DB::transaction(fn () => DB::table('brands')->where('seed_id', 'brand.anker')->update(['seed_id' => 'brand.other'])))
        ->toThrow(QueryException::class, 'brands.seed_id never changes');
});
