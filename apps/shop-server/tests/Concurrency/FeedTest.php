<?php

declare(strict_types=1);

use App\Counter\Feed;
use Tests\Concurrency\Harness;
use Tests\Concurrency\Scenarios\FeedLateCommit;
use Tests\Support\Catalogue;
use Tests\Support\Shop;

/*
 * The counter's catalogue feed with real commits (spec section 4) [R5, F25]: a change that commits late
 * is never skipped by a counter that polled in between.
 */

beforeEach(function () {
    Harness::freshDatabase();
    Shop::create();
});

it('never skips a change that commits after a later one', function () {
    $a = Catalogue::anyItem('late')->id;
    $b = Catalogue::anyItem('early')->id;
    $args = ['after' => Feed::seqNow(), 'a' => $a, 'b' => $b];

    $results = Harness::run([[FeedLateCommit::class, $args], [FeedLateCommit::class, $args], [FeedLateCommit::class, $args]]);
    $counter = collect($results)->firstWhere('role', 'counter');

    // Between the commits the counter sees only B; after them it sees A, from the cursor it kept.
    expect($counter['first'])->toBe([$b]);
    expect($counter['second'])->toBe([$a]);
});
