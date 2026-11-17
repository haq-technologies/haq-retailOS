<?php

declare(strict_types=1);

use App\Organisation\BranchKey;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness;
use Tests\Concurrency\Scenarios\SignWithBranchKey;
use Tests\Support\Shop;

/*
 * The branch key is made once, even when several processes need it at the same moment (spec section 4)
 * [S19, D84]: two counters finishing their first sales at once must not each make a key of their own.
 */

beforeEach(function () {
    Harness::freshDatabase();
    Shop::create(terminals: 2);
});

it('makes one branch key when four processes sign at once, so every signature verifies (D84)', function () {
    // As at a fresh install whose first sales have not signed yet: no key, and not even its folder.
    $key = app(BranchKey::class);
    @unlink($key->path());
    @rmdir(dirname($key->path()));
    expect(is_dir(dirname($key->path())))->toBeFalse();

    $results = Harness::run(array_map(fn (int $i) => [SignWithBranchKey::class], range(0, 3)));

    $public = $key->publicKey();
    expect(collect($results)->pluck('public')->unique()->values()->all())->toBe([$public]);
    foreach ($results as $i => $result) {
        expect($key->verify("epoch {$i}", $result['signature'], $public))->toBeTrue();
    }
    expect(DB::table('branches')->where('is_local', true)->value('public_key'))->toBe($public);
    expect(glob(dirname($key->path()).'/*.tmp'))->toBe([]);
});
