<?php

declare(strict_types=1);

use App\Identity\Enrolment;
use App\Identity\Role;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness;
use Tests\Concurrency\Scenarios\GuessPin;
use Tests\Concurrency\Scenarios\RedeemCode;
use Tests\Support\People;
use Tests\Support\Shop;

/*
 * Identity under real concurrency (spec section 3) [F8, F9]: separate processes, committed transactions and
 * the PostgreSQL cache a shop uses.
 */

beforeEach(function () {
    Harness::freshDatabase();
    $this->shop = Shop::create();
    [$this->t1] = $this->shop['terminals'];
});

it('enrols one browser when six type the same one-time code at once (P6)', function () {
    config(['cache.default' => 'database']);
    $code = app(Enrolment::class)->issueCode($this->t1);

    $results = Harness::run(array_fill(0, 6, [RedeemCode::class, ['code' => $code]]));

    expect(collect($results)->where('enrolled', true))->toHaveCount(1);
    expect(DB::table('audit_log')->where('action', 'identity.terminal_enrolled')->count())->toBe(1);
});

it('locks a person after five wrong PINs even when eight arrive at once (P7)', function () {
    $amina = People::add('Amina', Role::Cashier, '1357');

    $results = Harness::run(array_fill(0, 8, [GuessPin::class, ['terminal' => $this->t1->id, 'user' => $amina->id, 'pin' => '2468']]));

    expect(DB::table('sign_in_attempts')->where('user_id', $amina->id)->where('succeeded', false)->count())->toBe(5);
    expect(collect($results)->where('outcome', 'locked'))->toHaveCount(4);
    expect(collect($results)->where('outcome', 'wrong_pin'))->toHaveCount(4);
});
