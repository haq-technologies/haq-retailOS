<?php

declare(strict_types=1);

use App\Platform\Database\ForwardOnly;
use Illuminate\Support\Facades\Schema;

/*
 * Migrations only go forward (D107): rolling back, resetting and refreshing refuse before they touch the
 * schema, and the sales tables are still there afterwards.
 */

it('refuses to roll migrations back', function (string $command) {
    expect(fn () => $this->artisan($command, ['--database' => 'pgsql_owner', '--force' => true])->run())
        ->toThrow(ForwardOnly::class, 'Migrations only go forward');

    expect(Schema::hasTable('sales'))->toBeTrue();
    expect(Schema::hasTable('item_prices'))->toBeTrue();
})->with(['migrate:rollback', 'migrate:reset', 'migrate:refresh']);
