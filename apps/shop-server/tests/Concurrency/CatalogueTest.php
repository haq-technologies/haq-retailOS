<?php

declare(strict_types=1);

use App\Audit\Verifier;
use App\Catalogue\Items;
use App\Catalogue\Models\Category;
use App\Catalogue\StarterCatalogue;
use App\Identity\Role;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness;
use Tests\Concurrency\Scenarios\CreateSameItem;
use Tests\Concurrency\Scenarios\MergeItems;
use Tests\Support\People;
use Tests\Support\Shop;

/*
 * Counter B never creates a duplicate of the item counter A is creating (spec sections 4 and 14 A) [R1, F25].
 */

beforeEach(function () {
    Harness::freshDatabase();
    Shop::create();
    app(StarterCatalogue::class)->load(config()->string('catalogue.seed'));
});

it('ends with one item when six counters create the same new item at once', function () {
    $results = Harness::run(array_fill(0, 6, [CreateSameItem::class]));

    expect(collect($results)->pluck('item')->unique())->toHaveCount(1);
    expect(DB::table('items')->count())->toBe(1);
    expect(DB::table('items')->value('display_name'))->toBe('Oraimo FreePods Pro X Earbuds');
    expect(DB::table('audit_log')->where('action', 'catalogue.item_created')->count())->toBe(1);
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('leaves no loop when two people merge two items into each other at the same moment (check C6) [F18]', function () {
    $manager = People::add('Kemi', Role::Manager, '246810');
    $category = Category::query()->where('code', 'earbuds')->firstOrFail();
    foreach (range(1, 3) as $round) {
        $x = app(Items::class)->findOrCreate($category, ['model' => "X{$round}"]);
        $y = app(Items::class)->findOrCreate($category, ['model' => "Y{$round}"]);

        $results = Harness::run([
            [MergeItems::class, ['from' => $x->id, 'into' => $y->id, 'actor' => $manager->id]],
            [MergeItems::class, ['from' => $y->id, 'into' => $x->id, 'actor' => $manager->id]],
        ]);

        expect(collect($results)->whereNotNull('merged'))->toHaveCount(1);
        expect(collect($results)->whereNotNull('refused'))->toHaveCount(1);
        $statuses = DB::table('items')->whereIn('id', [$x->id, $y->id])->pluck('status')->sort()->values()->all();
        expect($statuses)->toBe(['active', 'merged']);
        $merged = DB::table('items')->whereIn('id', [$x->id, $y->id])->where('status', 'merged')->first();
        expect(DB::table('items')->where('id', $merged->merged_into_id)->value('merged_into_id'))->toBeNull();
    }
    expect(DB::table('merge_events')->where('kind', 'merge')->count())->toBe(3);
    expect(app(Verifier::class)->problems())->toBe([]);
});
