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

it('binds a price approval to its line, item and price when it is granted (S3)', function () {
    $approval = grant();
    expect(DB::table('approvals')->where('id', $approval['id'])->value('bound_at'))->not->toBeNull();

    // Still good long after the bind limit: once bound, it holds while the cart is open or parked.
    $this->travel(10)->minutes();
    expect($this->approvals->useForLine($this->t1, $approval['token'], ApprovalAction::PriceBelowFloor, $this->line, $this->item, 120_000, null))
        ->toBe($approval['id']);
});

it('refuses a price approval when the line changed after approval', function () {
    $approval = grant();

    expect(fn () => $this->approvals->useForLine($this->t1, $approval['token'], ApprovalAction::PriceBelowFloor, $this->line, $this->item, 100_000, null))
        ->toThrow(ApprovalRefused::class, 'The line changed after it was approved');
});

it('works on another counter of the branch only for a held or Awaiting-transfer cart', function () {
    $approval = grant();

    expect(fn () => $this->approvals->useForLine($this->t2, $approval['token'], ApprovalAction::PriceBelowFloor, $this->line, $this->item, 120_000, null))
        ->toThrow(ApprovalRefused::class, 'not for this counter');
    expect($this->approvals->useForLine($this->t2, $approval['token'], ApprovalAction::PriceBelowFloor, $this->line, $this->item, 120_000, (string) Str::ulid()))
        ->toBe($approval['id']);
});

it('binds any other approval once, within 60 seconds of being granted', function () {
    $parked = (string) Str::ulid();
    $grant = fn () => $this->approvals->grant($this->t1, $this->cashier->id, $this->manager->id, '135790', ApprovalAction::DiscardParkedSale, $parked);

    $first = $grant();
    expect(DB::table('approvals')->where('id', $first['id'])->value('bound_at'))->toBeNull();
    $this->approvals->bind($this->t1, $first['token'], ApprovalAction::DiscardParkedSale, $parked);
    expect(fn () => $this->approvals->bind($this->t1, $first['token'], ApprovalAction::DiscardParkedSale, $parked))
        ->toThrow(ApprovalRefused::class, 'already been used');
    expect(fn () => $this->approvals->bind($this->t2, $grant()['token'], ApprovalAction::DiscardParkedSale, $parked))
        ->toThrow(ApprovalRefused::class, 'not for this counter');

    $late = $grant();
    $this->travel(61)->seconds();
    expect(fn () => $this->approvals->bind($this->t1, $late['token'], ApprovalAction::DiscardParkedSale, $parked))
        ->toThrow(ApprovalRefused::class, 'took too long');
});

it('cannot be granted by a cashier, or with a wrong PIN', function () {
    expect(fn () => grant(['approver' => $this->cashier->id, 'pin' => '1357']))->toThrow(ApprovalRefused::class, 'Only a manager or the owner');
    expect(fn () => grant(['pin' => '000000']))->toThrow(ApprovalRefused::class, 'That PIN is not right');
    expect(DB::table('approvals')->count())->toBe(0);
});

it('counts and audits every wrong approval PIN, so five lock the manager (P10)', function () {
    foreach (range(1, 5) as $n) {
        expect(fn () => grant(['pin' => '000000']))->toThrow(ApprovalRefused::class, 'That PIN is not right');
    }
    expect(DB::table('sign_in_attempts')->where('user_id', $this->manager->id)->where('kind', 'approval')->where('succeeded', false)->count())->toBe(5);
    expect(DB::table('audit_log')->where('action', 'identity.approval_failed')->count())->toBe(5);

    // Locked now, even with the right PIN.
    expect(fn () => grant())->toThrow(ApprovalRefused::class, 'This manager is locked for now');
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
