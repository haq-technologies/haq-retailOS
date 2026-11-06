<?php

declare(strict_types=1);

use App\Counter\Console\RehearsalCheckCommand;
use App\Sales\Finish;
use Illuminate\Support\Facades\DB;
use Tests\Support\Counter;

/*
 * Gate G3's scripted sales and the check after them (PLAN.md, Phase 3; spec section 14, checks B and D).
 */

beforeEach(function () {
    $this->counter = Counter::ready(items: 3);
    [$this->t1, $this->t2] = $this->counter['terminals'];
});

it('refuses to make real sales without --confirm', function () {
    $this->artisan('shop:rehearse')->assertExitCode(2);
    expect(DB::table('sales')->count())->toBe(0);
});

it('sells one counter\'s share, paid in cash, transfer or POS, and the check passes', function () {
    $this->artisan('shop:rehearse-counter', ['--counter' => 'T01', '--count' => 12, '--pace' => 0, '--cashier' => $this->counter['cashier']->id])
        ->expectsOutputToContain('T01: 12/12 sales, last T01-E1-000012')->assertSuccessful();
    $this->artisan('shop:rehearse-counter', ['--counter' => 'T02', '--count' => 5, '--pace' => 0, '--cashier' => $this->counter['cashier']->id, '--seed' => 2])->assertSuccessful();

    expect(DB::table('sales')->count())->toBe(17);
    expect(DB::table('payments')->distinct()->pluck('method')->all())->not->toBeEmpty();
    $this->artisan('shop:rehearsal-check')
        ->expectsOutputToContain('PASS  T01 epoch 1: 12 receipts, numbers 1 to 12 with no gap')
        ->expectsOutputToContain('PASS  every sale is whole')
        ->expectsOutputToContain('The rehearsal check passed.')
        ->assertSuccessful();
});

it('counts a gap or a wrong start as a failure', function () {
    expect(RehearsalCheckCommand::gapless(12, 1, 12, 1))->toBeTrue();
    expect(RehearsalCheckCommand::gapless(11, 1, 12, 1))->toBeFalse();
    expect(RehearsalCheckCommand::gapless(3, 1001, 1003, 1001))->toBeTrue();
    expect(RehearsalCheckCommand::gapless(3, 1, 3, 1001))->toBeFalse();
});

it('fails the check when there is nothing to check', function () {
    $this->artisan('shop:rehearsal-check')->expectsOutputToContain('FAIL  there are sales to check')->assertFailed();
    expect(app(Finish::class))->toBeInstanceOf(Finish::class);
});
