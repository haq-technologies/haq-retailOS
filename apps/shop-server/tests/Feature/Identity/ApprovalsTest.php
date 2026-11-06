<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\ApprovalAction;
use App\Identity\ApprovalRefused;
use App\Identity\Approvals;
use App\Identity\Enrolment;
use App\Identity\Role;
use App\Identity\SignIn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Catalogue;
use Tests\Support\People;
use Tests\Support\Shop;

/*
 * Bound manager approvals (spec section 3; check D in section 14) [F9, S3].
 */

beforeEach(function () {
    $this->shop = Shop::create();
    [$this->t1, $this->t2] = $this->shop['terminals'];
    $this->cashier = People::add('Amina', Role::Cashier, '1357');
    $this->manager = People::add('Bola', Role::Manager, '135790');
    $this->approvals = app(Approvals::class);
    $this->line = (string) Str::uuid();
    $this->item = Catalogue::anyItem()->id;
});

function grant(array $overrides = []): array
{
    return test()->approvals->grant(
        $overrides['terminal'] ?? test()->t1,
        test()->cashier->id,
        $overrides['approver'] ?? test()->manager->id,
        $overrides['pin'] ?? '135790',
        ApprovalAction::PriceBelowFloor,
        test()->line,
        test()->item,
        120_000,
    );
}

it('binds once to the line, item and price it was granted for', function () {
    $approval = grant();

    $id = $this->approvals->bind($this->t1, $approval['token'], ApprovalAction::PriceBelowFloor, $this->line, $this->item, 120_000);
    expect(DB::table('approvals')->where('id', $id)->value('bound_at'))->not->toBeNull();

    expect(fn () => $this->approvals->bind($this->t1, $approval['token'], ApprovalAction::PriceBelowFloor, $this->line, $this->item, 120_000))
        ->toThrow(ApprovalRefused::class, 'already been used');
});

it('refuses to bind when the line changed after approval', function () {
    $approval = grant();

    expect(fn () => $this->approvals->bind($this->t1, $approval['token'], ApprovalAction::PriceBelowFloor, $this->line, $this->item, 100_000))
        ->toThrow(ApprovalRefused::class, 'The line changed after it was approved');
});

it('must be bound within 60 seconds of being granted', function () {
    $approval = grant();
    $this->travel(61)->seconds();

    expect(fn () => $this->approvals->bind($this->t1, $approval['token'], ApprovalAction::PriceBelowFloor, $this->line, $this->item, 120_000))
        ->toThrow(ApprovalRefused::class, 'took too long');
});

it('works only on the counter it was granted at', function () {
    $approval = grant();

    expect(fn () => $this->approvals->bind($this->t2, $approval['token'], ApprovalAction::PriceBelowFloor, $this->line, $this->item, 120_000))
        ->toThrow(ApprovalRefused::class, 'not for this counter');
});

it('cannot be granted by a cashier, or with a wrong PIN', function () {
    expect(fn () => grant(['approver' => $this->cashier->id, 'pin' => '1357']))->toThrow(ApprovalRefused::class, 'Only a manager or the owner');
    expect(fn () => grant(['pin' => '000000']))->toThrow(ApprovalRefused::class, 'That PIN is not right');
    expect(DB::table('approvals')->count())->toBe(0);
});

it('flags an approval by a manager who has not signed in at this branch today', function () {
    $first = grant();
    expect((bool) DB::table('approvals')->where('id', $first['id'])->value('flagged'))->toBeTrue();

    app(SignIn::class)->attempt($this->t2, $this->manager->id, '135790');
    $second = grant();
    expect((bool) DB::table('approvals')->where('id', $second['id'])->value('flagged'))->toBeFalse();
});

it('is granted over HTTP without changing the cashier\'s session', function () {
    $device = People::enrol($this->t1);
    $token = (string) $this->withCookie(Enrolment::COOKIE, $device)
        ->postJson('/api/identity/pin', ['user_id' => $this->cashier->id, 'pin' => '1357'])->json('token');

    $response = $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token)
        ->postJson('/api/identity/approvals', [
            'approver_id' => $this->manager->id, 'pin' => '135790', 'action' => 'price_below_floor',
            'subject_id' => $this->line, 'item_id' => $this->item, 'price_kobo' => 120_000,
        ])->assertOk();

    expect($response->json('approval_token'))->toBeString();
    expect(DB::table('approvals')->value('requested_by'))->toBe($this->cashier->id);

    // The cashier is still the one signed in.
    $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token)
        ->getJson('/api/identity/me')->assertJsonPath('user.name', 'Amina');
});
