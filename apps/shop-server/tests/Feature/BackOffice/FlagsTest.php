<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\ApprovalAction;
use App\Identity\Approvals;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Identity\SignIn;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Printing\Printing;
use App\Reports\Csv;
use App\Sales\Finish;
use App\Sales\Parked;
use App\Sales\Pricing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * The flag list, Needs your attention and the cashiers' day (spec sections 8 and 12; checks C4 and C5)
 * [F10, F11, F12, F14, S2, S21]. A flag is never deleted; marking it as seen keeps it listed.
 */

beforeEach(function () {
    $this->counter = Counter::ready(items: 2);
    [$this->t1] = $this->counter['terminals'];
    [$this->a, $this->b] = $this->counter['items'];
    $this->cashier = $this->counter['cashier'];
    $this->owner = People::add('Halima', Role::Owner, '135790');
    $this->manager = People::add('Kemi', Role::Manager, '246810');
    People::add('Sola', Role::Storekeeper, '2468');
    $this->device = People::enrol(Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'D01', 'name' => 'Owner laptop', 'kind' => 'back_office']));
    $this->as = function (string $name, string $pin) {
        $user = User::query()->where('name', $name)->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $this->device)->withHeader(ActingToken::HEADER, $token);
    };
    $this->owns = fn () => ($this->as)('Halima', '135790');
    $this->manages = fn () => ($this->as)('Kemi', '246810');
});

/** Sells one line at a price, with any line options (confirmed_outside_range, retyped, approval_token). */
function flagSale(object $item, int $price, array $line = [], array $sale = []): array
{
    $body = Counter::cashSale([$item->id => [1, $price]], $sale);
    $body['lines'][0] = [...$body['lines'][0], ...$line];

    return app(Finish::class)->finish(test()->t1, test()->cashier, Counter::request($body));
}

function flagKinds(array $flags): array
{
    return array_values(array_unique(array_column($flags, 'kind')));
}

it('flags a sale below cost when it is saved, with the cost only for the owner', function () {
    ($this->owns)()->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 120_000, 'basis' => 'first_null'])->assertOk();
    flagSale($this->a, 100_000);

    $owner = ($this->owns)()->getJson('/api/back-office/flags?period=today')->assertOk()->json();
    expect(flagKinds($owner['flags']))->toBe(['below_cost'])
        ->and($owner['flags'][0])->toMatchArray(['title' => 'Sold below cost', 'who' => 'Amina', 'cost_kobo' => 120_000, 'amount_kobo' => 100_000])
        ->and($owner['flags'][0]['detail'])->toBe('Thing item 1 at ₦1,000; cost ₦1,200')
        ->and($owner['counts']['below_cost'])->toBe(1);

    // A manager sees the same flag with no cost: not in the detail, not in any field (check C4) [F10].
    $manager = ($this->manages)()->getJson('/api/back-office/flags?period=today')->assertOk();
    expect($manager->json('flags.0.detail'))->toBe('Thing item 1 at ₦1,000');
    expect($manager->getContent())->not->toContain('cost_kobo')->not->toContain('1,200')->not->toContain('120000');
});

it('flags earlier sales again when a later cost entry turns them into losses (check C5)', function () {
    flagSale($this->a, 100_000);
    flagSale($this->a, 150_000);
    expect(($this->owns)()->getJson('/api/back-office/flags?period=today')->json('flags'))->toBe([]);

    ($this->owns)()->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 120_000, 'basis' => 'first_null'])->assertOk();
    $flags = ($this->owns)()->getJson('/api/back-office/flags?period=today')->json('flags');
    expect(flagKinds($flags))->toBe(['below_cost_later'])
        ->and($flags[0]['title'])->toBe('An earlier sale is now below cost')
        ->and($flags[0]['detail'])->toStartWith('1 earlier sale of Thing item 1 at ₦1,000; the cost entered is ₦1,200')
        ->and($flags[0]['sale_ids'])->toHaveCount(1);

    // Both show on the owner's dashboard until someone marks them as seen.
    $dash = ($this->owns)()->getJson('/api/back-office/dashboard')->json();
    expect($dash['attention']['total'])->toBe(1)->and($dash['attention']['flags'][0]['kind'])->toBe('below_cost_later');
});

it('flags prices below the floor and outside the usual range, and approvals by a manager not signed in', function () {
    app(Pricing::class)->set($this->a, 'retail', 300_000, $this->owner);
    flagSale($this->a, 250_000, ['confirmed_outside_range' => true]);
    flagSale($this->a, 1_000_000, ['retyped' => true]);
    $line = (string) Str::uuid();
    $approval = app(Approvals::class)->grant($this->t1, $this->cashier->id, $this->manager->id, '246810', ApprovalAction::PriceBelowFloor, $line, $this->a->id, 100_000);
    flagSale($this->a, 100_000, ['client_line_id' => $line, 'approval_token' => $approval['token']]);

    $flags = ($this->manages)()->getJson('/api/back-office/flags?period=today')->assertOk()->json('flags');
    $byKind = collect($flags)->groupBy('kind');
    expect($byKind['below_floor'][0])->toMatchArray(['title' => 'Below the floor, approved', 'approver' => 'Kemi', 'detail' => 'Thing item 1 at ₦1,000; usual ₦3,000']);
    expect($byKind['unusual_price']->pluck('title')->sort()->values()->all())->toBe(['Unusual price, confirmed by the cashier', 'Very high price, retyped by the cashier']);
    // Kemi had not signed in at the branch that day, so the approval is flagged too [F8].
    expect($byKind['approval_away'][0]['detail'])->toBe('Kemi approved a price below the floor for Thing item 1 at ₦1,000, without signing in at this branch that day');
});

it('flags transfers with no reference, discards, and drawer openings with no sale or with the key', function () {
    $sale = Counter::cashSale([$this->a->id => [1, 850_000]], ['payments' => [['method' => 'transfer', 'amount_kobo' => 850_000, 'reference' => null, 'alert_seen' => true]]]);
    app(Finish::class)->finish($this->t1, $this->cashier, Counter::request($sale));
    $parked = app(Parked::class);
    $id = $parked->park($this->t1, $this->cashier, 'hold', 'Blue cap', ['lines' => []], 400_000);
    $parked->discard($id, $this->t1, $this->manager, '=HYPERLINK("x") customer left', null);
    DB::table('drawers')->where('terminal_id', $this->t1->id)->update(['fitted' => true]);
    app(Printing::class)->openedWithKey($this->t1, $this->cashier, null);

    $flags = collect(($this->owns)()->getJson('/api/back-office/flags?period=today')->json('flags'))->keyBy('kind');
    expect($flags['no_reference'])->toMatchArray(['detail' => '₦8,500; the bank-alert tick was given', 'who' => 'Amina']);
    expect($flags['discarded'])->toMatchArray(['who' => 'Kemi', 'detail' => '₦4,000, “Blue cap”; reason: =HYPERLINK("x") customer left']);
    expect($flags['key_open'])->toMatchArray(['title' => 'Drawer opened with the key', 'who' => 'Amina']);

    // The cashiers' day on the dashboard counts them per person.
    $amina = collect(($this->owns)()->getJson('/api/back-office/dashboard')->json('cashiers'))->firstWhere('name', 'Amina');
    expect($amina)->toMatchArray(['sales' => 1, 'no_reference' => 1, 'key_open' => 1, 'discarded' => 0]);
});

it('flags a transfer still waiting after 30 minutes, then the wait once it is finished', function () {
    $id = app(Parked::class)->park($this->t1, $this->cashier, 'awaiting_transfer', 'Blue cap', ['lines' => []], 1_500_000);
    $this->travel(20)->minutes();
    expect(($this->owns)()->getJson('/api/back-office/flags?period=today&kind=transfer_waited')->json('flags'))->toBe([]);

    $this->travel(14)->minutes();
    $live = ($this->owns)()->getJson('/api/back-office/flags?period=today&kind=transfer_waited')->json('flags.0');
    expect($live)->toMatchArray(['title' => 'Transfer waiting 34 minutes', 'live' => true]);
    expect(($this->owns)()->getJson('/api/back-office/dashboard')->json('attention.flags.0.kind'))->toBe('transfer_waited');

    $sale = Counter::cashSale([$this->a->id => [1, 1_500_000]], ['parked_sale_id' => $id, 'payments' => [['method' => 'transfer', 'amount_kobo' => 1_500_000, 'reference' => 'GTB 1234', 'alert_seen' => true]]]);
    app(Finish::class)->finish($this->t1, $this->cashier, Counter::request($sale));
    $done = ($this->owns)()->getJson('/api/back-office/flags?period=today&kind=transfer_waited')->json('flags.0');
    expect($done['live'])->toBeFalse()->and($done['detail'])->toContain('waited 34 minutes, finished at');
});

it('flags a lockout after five wrong PINs', function () {
    $amina = $this->cashier;
    foreach (range(1, 5) as $i) {
        app(SignIn::class)->attempt($this->t1, $amina->id, '9999');
    }
    $flag = ($this->owns)()->getJson('/api/back-office/flags?period=today&kind=locked_out')->json('flags.0');
    expect($flag['title'])->toBe('Amina was locked out after wrong PINs')->and($flag['detail'])->toStartWith('Unlocks by itself at');
});

it('marks a flag as seen without deleting it, and the mark itself can never be removed', function () {
    flagSale($this->a, 100_000);
    ($this->owns)()->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 120_000, 'basis' => 'first_null'])->assertOk();
    $key = ($this->owns)()->getJson('/api/back-office/flags?period=today')->json('flags.0.key');

    ($this->manages)()->postJson('/api/back-office/flags/seen', ['keys' => [$key]])->assertOk()->assertJson(['marked' => 1]);
    ($this->owns)()->postJson('/api/back-office/flags/seen', ['keys' => [$key]])->assertOk()->assertJson(['marked' => 0]);
    $flag = ($this->owns)()->getJson('/api/back-office/flags?period=today')->json('flags.0');
    expect($flag)->toMatchArray(['key' => $key, 'seen_by' => 'Kemi']);
    expect(($this->owns)()->getJson('/api/back-office/dashboard')->json('attention.total'))->toBe(0);
    expect(DB::table('audit_log')->where('action', 'flag.seen')->count())->toBe(1);

    ($this->owns)()->postJson('/api/back-office/flags/seen', ['keys' => ['below_cost:'.Ulid::generate()]])->assertStatus(422);
    expect(fn () => DB::table('flag_marks')->delete())->toThrow(QueryException::class);
});

it('exports flags as a CSV that opens in Excel, with cost only for the owner and no formula ever run', function () {
    ($this->owns)()->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 120_000, 'basis' => 'first_null'])->assertOk();
    flagSale($this->a, 100_000);
    $parked = app(Parked::class);
    $parked->discard($parked->park($this->t1, $this->cashier, 'hold', null, ['lines' => []], 400_000), $this->t1, $this->manager, '=HYPERLINK("x") left', null);

    $owner = ($this->owns)()->get('/api/back-office/flags.csv?period=today')->assertOk();
    expect($owner->headers->get('content-type'))->toContain('text/csv');
    $csv = $owner->getContent();
    expect($csv)->toStartWith("\u{FEFF}\"When\",\"Flag\"")->toContain('"Cost (NGN)"')->toContain('"1200.00"')->toContain("\r\n");
    expect($csv)->toContain('"₦4,000; reason: =HYPERLINK(""x"") left"');
    // A cell that starts like a formula is never run as one; negative amounts stay numbers.
    expect(Csv::make(['A'], [['=1+1'], ['@SUM(A1)'], ['-5.00'], ['+234 800']]))
        ->toBe("\u{FEFF}\"A\"\r\n\"'=1+1\"\r\n\"'@SUM(A1)\"\r\n\"-5.00\"\r\n\"'+234 800\"\r\n");

    $manager = ($this->manages)()->get('/api/back-office/flags.csv?period=today')->assertOk()->getContent();
    expect($manager)->not->toContain('Cost (NGN)')->not->toContain('1200.00')->not->toContain('1,200');
});

it('shows one sale behind View sale, with cost and its bucket only for the owner', function () {
    ($this->owns)()->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 120_000, 'basis' => 'first_null'])->assertOk();
    $sale = flagSale($this->a, 100_000);
    $id = DB::table('sales')->where('receipt_number', $sale['receipt_number'])->value('id');

    $owner = ($this->owns)()->getJson("/api/back-office/sales/{$id}")->assertOk()->json();
    expect($owner)->toMatchArray(['receipt_number' => $sale['receipt_number'], 'cashier' => 'Amina'])
        ->and($owner['lines'][0])->toMatchArray(['name' => 'Thing item 1', 'cost_kobo' => 120_000, 'cost_bucket' => 'confirmed']);
    $manager = ($this->manages)()->getJson("/api/back-office/sales/{$id}")->assertOk();
    expect($manager->json('lines.0'))->not->toHaveKey('cost_kobo')->and($manager->getContent())->not->toContain('120000');
    ($this->owns)()->getJson('/api/back-office/sales/not-a-sale')->assertNotFound();
});

it('keeps flags from cashiers and storekeepers', function () {
    ($this->as)('Amina', '1357')->getJson('/api/back-office/flags')->assertForbidden();
    ($this->as)('Sola', '2468')->getJson('/api/back-office/flags')->assertForbidden();
    ($this->as)('Sola', '2468')->get('/api/back-office/flags.csv')->assertForbidden();
    ($this->as)('Amina', '1357')->postJson('/api/back-office/flags/seen', ['keys' => ['x']])->assertForbidden();
});
