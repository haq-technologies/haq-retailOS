<?php

declare(strict_types=1);

use App\Catalogue\NoShopProfile;
use App\Catalogue\StarterCatalogue;
use Illuminate\Support\Facades\DB;

/*
 * The starter catalogue loaded before the shop profile: the command says what to do first, in plain
 * words, and nothing is loaded. install.sh applies the profile first, so a shop never sees this.
 */

it('asks for the shop profile first and loads nothing', function () {
    $this->artisan('catalogue:seed')
        ->expectsOutputToContain('Apply the shop profile first')
        ->assertFailed();

    expect(fn () => app(StarterCatalogue::class)->load(config()->string('catalogue.seed')))->toThrow(NoShopProfile::class);
    expect(DB::table('brands')->count())->toBe(0);
    expect(DB::table('categories')->count())->toBe(0);
});
