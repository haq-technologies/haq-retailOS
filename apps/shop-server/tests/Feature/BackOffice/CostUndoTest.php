<?php

declare(strict_types=1);

use App\Audit\Verifier;
use App\Cost\Costs;
use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Reports\Flags;
use App\Reports\Period;
use App\Reports\Profit;
use App\Sales\Finish;
use Illuminate\Support\Facades\DB;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * Undoing a cost (spec section 2: every cost backfill is reversible) [F14, D48]: a mistyped cost and its
 * estimates are put back as new rows; the sales it estimated wait for a cost again and are offered to the
 * next cost; sales made since keep the cost they were sold with; its below-cost warnings go.
 */

beforeEach(function () {
    $this->counter = Counter::ready(items: 2);
    [$this->t1] = $this->counter['terminals'];
    [$this->a, $this->b] = $this->counter['items'];
    People::add('Halima', Role::Owner, '135790');
    People::add('Kemi', Role::Manager, '246810');
    $this->device = People::enrol(Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'D01', 'name' => 'Owner laptop', 'kind' => 'back_office']));
    $this->as = function (string $name = 'Halima', string $pin = '135790') {
        $user = User::query()->where('name', $name)->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $this->device)->withHeader(ActingToken::HEADER, $token);
    };
    $this->sell = fn (string $itemId, int $price) => app(Finish::class)->finish($this->t1, $this->counter['cashier'], Counter::request(Counter::cashSale([$itemId => [1, $price]])));
    // Item a: three sales with no cost (₦2,500, ₦2,500 and ₦1,000).
    ($this->sell)($this->a->id, 250_000);
    ($this->sell)($this->a->id, 250_000);
    ($this->sell)($this->a->id, 100_000);
    $this->waiting = fn () => collect(($this->as)()->getJson('/api/back-office/costs')->json('items'))->firstWhere('item_id', $this->a->id);
});

it('puts a mistyped cost back: its sales wait for a cost again, and the right cost reaches them', function () {
    // ₦30,000 typed for ₦1,200: every earlier sale looks like a loss.
    ($this->as)()->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 3_000_000, 'basis' => 'first_null'])->assertOk()->assertJson(['resolved' => 3]);
    ($this->sell)($this->a->id, 250_000); // sold since, at the wrong cost
    expect(($this->waiting)())->toBeNull();
    expect(app(Flags::class)->list(Period::named('last7', 'Africa/Lagos'), true, ['below_cost_later']))->toHaveCount(1);

    $entry = collect(($this->as)()->getJson('/api/back-office/costs/recent')->json('entries'))->firstWhere('item_id', $this->a->id);
    expect($entry)->undo->toBeTrue()->cost_kobo->toBe(3_000_000);
    ($this->as)()->getJson("/api/back-office/costs/entries/{$entry['entry_id']}/undo")->assertOk()
        ->assertJson(['estimates' => 3, 'back_to_waiting' => 3, 'sold_since' => 1, 'cost_after_kobo' => null]);

    ($this->as)()->postJson("/api/back-office/costs/entries/{$entry['entry_id']}/undo")->assertOk()->assertJson(['estimates' => 3, 'back_to_waiting' => 3]);

    // The three sales wait for a cost again; later sales take no cost; nothing was changed or deleted.
    expect(($this->waiting)())->sales->toBe(3)->revenue_kobo->toBe(600_000);
    expect(Costs::current($this->a->id))->toBeNull();
    expect(DB::table('cost_entries')->count())->toBe(2)->and(DB::table('cost_resolutions')->count())->toBe(6);
    expect(Profit::buckets()['pending']['revenue_kobo'])->toBe(600_000);
    expect(app(Flags::class)->list(Period::named('last7', 'Africa/Lagos'), true, ['below_cost_later']))->toBeEmpty();
    // The sale made since keeps the cost it was sold with, as every finished sale does.
    expect(DB::table('sale_lines')->whereNotNull('cost_snapshot_kobo')->count())->toBe(1);

    // The right cost now reaches the same three sales.
    ($this->as)()->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 120_000, 'basis' => 'first_null'])->assertOk()->assertJson(['resolved' => 3]);
    expect(Profit::buckets()['estimated']['revenue_kobo'])->toBe(600_000);
    expect(DB::table('audit_log')->where('action', 'cost.undone')->count())->toBe(1);
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('puts back the cost before for later sales, and undoes the newest cost in force, once', function () {
    ($this->as)()->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 120_000, 'basis' => 'first_null'])->assertOk();
    $first = (string) DB::table('cost_entries')->value('id');
    $this->travel(1)->minutes();
    ($this->as)()->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 130_000, 'basis' => 'first_null'])->assertOk()->assertJson(['resolved' => 0]);
    $second = (string) DB::table('cost_entries')->where('id', '!=', $first)->value('id');

    ($this->as)()->postJson("/api/back-office/costs/entries/{$first}/undo")->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'Only the newest cost'));
    ($this->as)()->postJson("/api/back-office/costs/entries/{$second}/undo")->assertOk()->assertJson(['estimates' => 0]);
    expect(Costs::current($this->a->id))->toBe(120_000);
    ($this->as)()->postJson("/api/back-office/costs/entries/{$second}/undo")->assertStatus(422)->assertJsonPath('message', 'That cost was undone already.');

    // A manager without the console's "may enter cost" setting cannot undo either.
    ($this->as)('Kemi', '246810')->postJson("/api/back-office/costs/entries/{$first}/undo")->assertForbidden();
    ($this->as)()->getJson("/api/back-office/costs/items/{$this->a->id}")->assertOk()->assertJsonPath('history.0.undo', true)->assertJsonPath('history.0.unit_cost_kobo', 120_000);

    // Two mistakes are undone newest first: with the second undone, the first can be undone too.
    expect(collect(($this->as)()->getJson('/api/back-office/costs/recent')->json('entries'))->pluck('undo')->all())->toBe([false, false, true]);
    ($this->as)()->postJson("/api/back-office/costs/entries/{$first}/undo")->assertOk()->assertJson(['estimates' => 3, 'back_to_waiting' => 3]);
    expect(Costs::current($this->a->id))->toBeNull();
    expect(($this->waiting)())->sales->toBe(3);
});
