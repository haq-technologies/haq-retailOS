<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * Held sales and Awaiting transfer (spec sections 3, 7 and 9; checks in section 14) [F12, S2].
 */

beforeEach(function () {
    $this->counter = Counter::ready(items: 1);
    [$this->t1, $this->t2] = $this->counter['terminals'];
    [$this->a] = $this->counter['items'];
    $this->manager = People::add('Kemi', Role::Manager, '246810');
    $this->bola = People::add('Bola', Role::Cashier, '2468');
    $this->devices = [$this->t1->id => People::enrol($this->t1), $this->t2->id => People::enrol($this->t2)];
    $this->at = function ($terminal, string $name = 'Amina', string $pin = '1357') {
        $user = User::query()->where('name', $name)->firstOrFail();
        $device = $this->devices[$terminal->id];
        $token = (string) $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token);
    };
    $this->cart = fn (int $kobo = 5000) => Counter::cashSale([$this->a->id => [1, $kobo]]);
});

it('holds a sale on one counter and resumes it on another, once', function () {
    $id = ($this->at)($this->t1)->postJson('/api/counter/parked', ['kind' => 'hold', 'label' => 'Man in blue', 'payload' => ($this->cart)(), 'amount_kobo' => 5000])
        ->assertStatus(201)->json('id');

    ($this->at)($this->t2)->getJson('/api/counter/parked')->assertOk()->assertJsonPath('parked.0.label', 'Man in blue')->assertJsonPath('parked.0.terminal', 'T01');
    $resumed = ($this->at)($this->t2)->postJson("/api/counter/parked/{$id}/resume")->assertOk()->json();
    expect($resumed['payload']['lines'][0]['item_id'])->toBe($this->a->id);
    ($this->at)($this->t1)->postJson("/api/counter/parked/{$id}/resume")->assertStatus(422);
    ($this->at)($this->t2)->getJson('/api/counter/parked')->assertJsonCount(0, 'parked');

    // Finished on the counter that resumed it, the sale is linked to the held cart.
    $sale = [...$resumed['payload'], 'parked_sale_id' => $id];
    ($this->at)($this->t2)->postJson('/api/counter/sales', $sale)->assertSuccessful();
    expect(DB::table('parked_sale_events')->where('parked_sale_id', $id)->pluck('type')->all())->toBe(['parked', 'resumed', 'completed']);

    // A storekeeper never holds.
    People::add('Sola', Role::Storekeeper, '1122');
    ($this->at)($this->t1, 'Sola', '1122')->postJson('/api/counter/parked', ['kind' => 'hold', 'payload' => ($this->cart)(), 'amount_kobo' => 5000])->assertStatus(422);
});

it('parks a cart as Awaiting transfer on every counter, flags it after 30 minutes, and finishes it from any counter', function () {
    $cart = ($this->cart)(1_200_000);
    $cart['payments'] = [['method' => 'transfer', 'amount_kobo' => 1_200_000, 'reference' => 'Chidi', 'alert_seen' => true]];
    $id = ($this->at)($this->t1)->postJson('/api/counter/parked', ['kind' => 'awaiting_transfer', 'label' => 'Chidi', 'payload' => $cart, 'amount_kobo' => 1_200_000])
        ->assertStatus(201)->json('id');
    ($this->at)($this->t1)->postJson('/api/counter/parked', ['kind' => 'awaiting_transfer', 'payload' => $cart, 'amount_kobo' => 0])->assertStatus(422);

    ($this->at)($this->t2)->getJson('/api/counter/parked')->assertJsonPath('parked.0.kind', 'awaiting_transfer')->assertJsonPath('parked.0.flagged', false);
    $this->travel(31)->minutes();
    ($this->at)($this->t2)->getJson('/api/counter/parked')->assertJsonPath('parked.0.flagged', true);

    // Whoever sees the alert finishes it; a second counter trying the same cart is refused.
    ($this->at)($this->t2)->postJson('/api/counter/sales', [...$cart, 'parked_sale_id' => $id])->assertSuccessful();
    ($this->at)($this->t1)->postJson('/api/counter/sales', [...($this->cart)(1_200_000), 'parked_sale_id' => $id])->assertStatus(422);
    expect(DB::table('parked_sale_events')->where('parked_sale_id', $id)->pluck('type')->all())->toBe(['parked', 'transfer_confirmed', 'completed']);
    expect(DB::table('sales')->count())->toBe(1);
});

it('lets a cashier discard their own parked sale at or below the limit, with a reason', function () {
    app(Settings::class)->set('parked.discard_limit_kobo', 10_000, 'console');
    $own = ($this->at)($this->t1)->postJson('/api/counter/parked', ['kind' => 'hold', 'payload' => ($this->cart)(), 'amount_kobo' => 5000])->json('id');

    ($this->at)($this->t1)->postJson("/api/counter/parked/{$own}/discard", ['reason' => ''])->assertStatus(422);
    ($this->at)($this->t1)->postJson("/api/counter/parked/{$own}/discard", ['reason' => 'Customer left'])->assertOk();
    expect(DB::table('parked_sale_events')->where('parked_sale_id', $own)->where('type', 'discarded')->first())
        ->reason->toBe('Customer left')->approval_id->toBeNull();
    expect(DB::table('audit_log')->where('action', 'parked.discarded')->count())->toBe(1);
});

it('needs a manager for a discard above the limit or of someone else\'s sale', function () {
    app(Settings::class)->set('parked.discard_limit_kobo', 10_000, 'console');
    $big = ($this->at)($this->t1)->postJson('/api/counter/parked', ['kind' => 'hold', 'payload' => ($this->cart)(50_000), 'amount_kobo' => 50_000])->json('id');
    $bolas = ($this->at)($this->t1, 'Bola', '2468')->postJson('/api/counter/parked', ['kind' => 'hold', 'payload' => ($this->cart)(), 'amount_kobo' => 5000])->json('id');

    ($this->at)($this->t1)->postJson("/api/counter/parked/{$big}/discard", ['reason' => 'Customer left'])->assertStatus(403)
        ->assertJsonPath('approval.action', 'discard_parked_sale')->assertJsonPath('approval.subject_id', $big);
    ($this->at)($this->t1)->postJson("/api/counter/parked/{$bolas}/discard", ['reason' => 'Customer left'])->assertStatus(403);

    $approval = ($this->at)($this->t1)->postJson('/api/identity/approvals', ['approver_id' => $this->manager->id, 'pin' => '246810', 'action' => 'discard_parked_sale', 'subject_id' => $big])
        ->assertOk()->json('approval_token');
    // An approval is bound to its one sale.
    ($this->at)($this->t1)->postJson("/api/counter/parked/{$bolas}/discard", ['reason' => 'Customer left', 'approval_token' => $approval])->assertStatus(422);
    ($this->at)($this->t1)->postJson("/api/counter/parked/{$big}/discard", ['reason' => 'Customer left', 'approval_token' => $approval])->assertOk();
    expect(DB::table('parked_sale_events')->where('parked_sale_id', $big)->where('type', 'discarded')->value('approval_id'))->not->toBeNull();

    // A manager discards any, with a reason and no approval.
    ($this->at)($this->t1, 'Kemi', '246810')->postJson("/api/counter/parked/{$bolas}/discard", ['reason' => 'Duplicate cart'])->assertOk();
});

it('needs a manager for every cashier discard until the owner sets a limit (D33)', function () {
    $own = ($this->at)($this->t1)->postJson('/api/counter/parked', ['kind' => 'hold', 'payload' => ($this->cart)(100), 'amount_kobo' => 100])->json('id');
    ($this->at)($this->t1)->postJson("/api/counter/parked/{$own}/discard", ['reason' => 'Customer left'])->assertStatus(403);
});
