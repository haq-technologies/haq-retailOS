<?php

declare(strict_types=1);

use App\Audit\Verifier;
use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Reports\Profit;
use App\Sales\Finish;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * Cost, the backfill and the three profit buckets (spec sections 3 and 8; checks in section 14 C) [F10,
 * F14, FU6]; the back office's dashboard and who sees what.
 */

beforeEach(function () {
    $this->counter = Counter::ready(items: 2);
    [$this->t1] = $this->counter['terminals'];
    [$this->a, $this->b] = $this->counter['items'];
    People::add('Halima', Role::Owner, '135790');
    People::add('Kemi', Role::Manager, '246810');
    $this->device = People::enrol(Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'D01', 'name' => 'Owner laptop', 'kind' => 'back_office']));
    $this->as = function (string $name, string $pin) {
        $user = User::query()->where('name', $name)->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $this->device)->withHeader(ActingToken::HEADER, $token);
    };
    $this->sell = fn (string $itemId, int $price, int $qty = 1) => app(Finish::class)->finish($this->t1, $this->counter['cashier'], Counter::request(Counter::cashSale([$itemId => [$qty, $price]])));
    // Item a: three sales with no cost (₦2,500, ₦2,500 and ₦1,000); item b: one (₦8,000).
    ($this->sell)($this->a->id, 250_000);
    ($this->sell)($this->a->id, 250_000);
    ($this->sell)($this->a->id, 100_000);
    ($this->sell)($this->b->id, 800_000);
});

it('lists the items with no cost, highest revenue first', function () {
    $queue = ($this->as)('Halima', '135790')->getJson('/api/back-office/costs')->assertOk()->json();
    expect(array_column($queue['items'], 'item_id'))->toBe([$this->b->id, $this->a->id]);
    expect($queue['items'][1])->toMatchArray(['sales' => 3, 'revenue_kobo' => 600_000]);
    expect($queue['total_revenue_kobo'])->toBe(1_400_000);
});

it('previews a cost before saving: sales, revenue, estimated profit, sales below it, and coverage', function () {
    $preview = ($this->as)('Halima', '135790')->postJson('/api/back-office/costs/preview', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 120_000, 'basis' => 'first_null'])
        ->assertOk()->json();
    expect($preview)->toMatchArray(['sales' => 3, 'revenue_kobo' => 600_000, 'profit_kobo' => 240_000, 'coverage_now' => 0, 'coverage_after' => 42]);
    expect($preview['below_cost'])->toHaveCount(1)->and($preview['below_cost'][0])->toMatchArray(['unit_price_kobo' => 100_000, 'receipt_number' => 'T01-E1-000003', 'cashier' => 'Amina']);
    expect(DB::table('cost_entries')->count())->toBe(0);
});

it('saves a cost: an estimate beside each earlier sale, no sale changed, later sales take it, all audited with the cost kept apart', function () {
    ($this->as)('Halima', '135790')->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 120_000, 'basis' => 'first_null'])
        ->assertOk()->assertJson(['entries' => 1, 'resolved' => 3]);

    expect(DB::table('sale_lines')->whereNotNull('cost_snapshot_kobo')->count())->toBe(0);
    expect(DB::table('cost_resolutions')->where('item_id', $this->a->id)->count())->toBe(3);
    $buckets = Profit::buckets();
    expect($buckets['estimated'])->toMatchArray(['revenue_kobo' => 600_000, 'profit_kobo' => 240_000]);
    expect($buckets['pending']['revenue_kobo'])->toBe(800_000);

    // The next sale takes the cost at the moment of sale: Confirmed.
    ($this->sell)($this->a->id, 250_000);
    expect(DB::table('sale_lines')->whereNotNull('cost_snapshot_kobo')->value('cost_snapshot_kobo'))->toBe(120_000);
    expect(Profit::buckets()['confirmed'])->toMatchArray(['revenue_kobo' => 250_000, 'profit_kobo' => 130_000]);

    $audit = DB::table('audit_log')->where('action', 'cost.entered')->first();
    expect($audit->redacted_after)->not->toContain('120000');
    expect(DB::table('audit_sensitive')->where('audit_id', $audit->id)->value('after'))->toContain('120000');
    expect(app(Verifier::class)->problems())->toBe([]);

    // Saved once, the sales are no longer waiting: a second save estimates nothing more.
    ($this->as)('Halima', '135790')->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 130_000, 'basis' => 'first_null'])
        ->assertOk()->assertJson(['resolved' => 0]);
});

it('gives one cost to several items, each from its own first sale with no cost, or only from a date', function () {
    ($this->as)('Halima', '135790')->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id, $this->b->id], 'unit_cost_kobo' => 50_000, 'basis' => 'from_date', 'from' => now()->addDay()->toDateString()])
        ->assertOk()->assertJson(['entries' => 2, 'resolved' => 0]);
    ($this->as)('Halima', '135790')->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id, $this->b->id], 'unit_cost_kobo' => 50_000, 'basis' => 'first_null'])
        ->assertOk()->assertJson(['entries' => 2, 'resolved' => 4]);
    expect(Profit::buckets()['pending']['revenue_kobo'])->toBe(0);
});

it('keeps cost from a manager unless HAQ allows it, and from a cashier always', function () {
    ($this->as)('Kemi', '246810')->getJson('/api/back-office/costs')->assertForbidden();
    ($this->as)('Kemi', '246810')->getJson('/api/back-office/me')->assertJsonPath('can.profit', false)->assertJsonPath('can.costs', false)->assertJsonPath('can.dashboard', true);
    $dash = ($this->as)('Kemi', '246810')->getJson('/api/back-office/dashboard')->assertOk()->json();
    expect($dash['profit']['confirmed'])->not->toHaveKey('profit_kobo')->and($dash['profit']['pending'])->toHaveKey('percent');
    expect(json_encode($dash))->not->toContain('profit_kobo');

    app(Settings::class)->set('cost.manager_enters_cost', true, 'console');
    ($this->as)('Kemi', '246810')->getJson('/api/back-office/costs')->assertOk();

    ($this->as)('Amina', '1357')->getJson('/api/back-office/dashboard')->assertForbidden();
});

it('shows the owner the day: sales by payment method, profit in three parts, and what waits', function () {
    $dash = ($this->as)('Halima', '135790')->getJson('/api/back-office/dashboard')->assertOk()->json();
    expect($dash['sales'])->toMatchArray(['count' => 4, 'total_kobo' => 1_400_000]);
    expect($dash['sales']['by_method'][0])->toMatchArray(['method' => 'cash', 'sales' => 4, 'amount_kobo' => 1_400_000]);
    expect($dash['profit']['pending'])->toMatchArray(['revenue_kobo' => 1_400_000, 'percent' => 100]);
    expect($dash['profit']['confirmed'])->toHaveKey('profit_kobo');
    expect($dash['todo']['needs_cost'])->toBe(2);
    expect($dash['counters_selling'])->toBe(1);
});

it('keeps showing the business day of a shift that runs past midnight', function () {
    $day = (string) DB::table('shifts')->where('status', 'open')->value('business_day');
    $this->travelTo(now('Africa/Lagos')->addDay()->setTime(0, 30));

    $dash = ($this->as)('Halima', '135790')->getJson('/api/back-office/dashboard')->assertOk()->json();
    expect($dash['day'])->toBe($day)->and($dash['sales']['count'])->toBe(4);
});

it('opens the back office on a back-office device, with only the owner, managers and storekeepers on the tiles', function () {
    $this->withCookie(Enrolment::COOKIE, $this->device)->get('/counter')->assertRedirect('/back-office');
    $this->withCookie(Enrolment::COOKIE, $this->device)->get('/back-office')->assertOk()
        ->assertInertia(fn ($p) => $p->component('back-office/index')->has('tiles', 2)->where('terminal.code', 'D01'));
});
