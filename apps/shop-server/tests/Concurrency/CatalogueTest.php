<?php

declare(strict_types=1);

use App\Audit\Verifier;
use App\Catalogue\StarterCatalogue;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness;
use Tests\Concurrency\Scenarios\CreateSameItem;
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
