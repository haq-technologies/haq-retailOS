<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Sales\Finish;
use App\Settings\Settings;
use App\Shifts\Shifts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * The measured pilot (Phase 5; spec section 14, Pilot success thresholds) [F14, S8, D56]: the owner starts
 * the 14 days, the notebook's totals go in beside the app's, each difference is traced to the notebook or
 * the app, the five measures are read live, and the owner's decision closes the record.
 */

beforeEach(function () {
    $this->counter = Counter::ready(items: 3);
    [$this->t1] = $this->counter['terminals'];
    [$this->a, $this->b, $this->c] = $this->counter['items'];
    $this->cashier = $this->counter['cashier'];
    People::add('Halima', Role::Owner, '135790');
    People::add('Kemi', Role::Manager, '246810');
    People::add('Sola', Role::Storekeeper, '2468');
    // The forgotten-shift rule closes each day's shift overnight, so every day is its own business day.
    app(Settings::class)->set('shifts.closing_time', '21:00', 'profile');
    $this->device = People::enrol(Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'D01', 'name' => 'Owner laptop', 'kind' => 'back_office']));
    $this->as = function (string $name, string $pin) {
        $user = User::query()->where('name', $name)->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $this->device)->withHeader(ActingToken::HEADER, $token);
    };
    $this->owns = fn () => ($this->as)('Halima', '135790');
    $this->manages = fn () => ($this->as)('Kemi', '246810');
    $this->first = '2027-01-04';
    pilotAt($this, $this->first, '08:00');
});

/** Moves to a time on a day, closing any shift the night has ended, as the scheduler would. */
function pilotAt(object $test, string $day, string $time = '10:00'): void
{
    $test->travelTo(CarbonImmutable::parse("{$day} {$time}", 'Africa/Lagos'));
    app(Shifts::class)->autoClose();
}

/** A day after the first, as a date. */
function pilotDay(object $test, int $offset): string
{
    return CarbonImmutable::parse($test->first)->addDays($offset)->toDateString();
}

/**
 * A sale of one line per item at ₦1,000 each, paid one way, rung in the given number of seconds.
 *
 * @param  list<object>  $items
 * @return array<string, mixed>
 */
function pilotSell(object $test, array $items, string $method = 'cash', int $seconds = 20, int $qty = 1): array
{
    $lines = [];
    foreach ($items as $item) {
        $lines[$item->id] = [$qty, 100_000];
    }
    $total = count($items) * $qty * 100_000;
    $payment = match ($method) {
        'transfer' => ['method' => 'transfer', 'amount_kobo' => $total, 'reference' => 'TRF'.random_int(10_000, 99_999), 'alert_seen' => true],
        'pos' => ['method' => 'pos', 'amount_kobo' => $total],
        default => ['method' => 'cash', 'amount_kobo' => $total, 'tendered_kobo' => $total],
    };
    $sale = Counter::cashSale($lines, ['payments' => [$payment], 'started_at' => CarbonImmutable::now()->subSeconds($seconds)->toIso8601String()]);
    foreach (array_keys($sale['lines']) as $i) {
        $sale['lines'][$i]['confirmed_outside_range'] = true;
    }

    return app(Finish::class)->finish($test->t1, $test->cashier, Counter::request($sale));
}

/** @return array<string, mixed> */
function pilotCard(object $test): array
{
    /** @var array<string, mixed> */
    return ($test->owns)()->getJson('/api/back-office/pilot')->assertOk()->json();
}

it('starts the 14 days: only the owner, never on an earlier day, and again only with a reason', function () {
    expect(pilotCard($this))->toMatchArray(['started' => false, 'can_decide' => true, 'can_export' => true]);
    ($this->manages)()->postJson('/api/back-office/pilot/start', ['first_day' => $this->first])->assertForbidden();
    ($this->as)('Sola', '2468')->getJson('/api/back-office/pilot')->assertForbidden();
    ($this->as)('Amina', '1357')->getJson('/api/back-office/pilot')->assertForbidden();
    expect(($this->manages)()->getJson('/api/back-office/pilot')->assertOk()->json('can_decide'))->toBeFalse();

    ($this->owns)()->postJson('/api/back-office/pilot/start', ['first_day' => pilotDay($this, -1)])->assertStatus(422)
        ->assertJson(['message' => 'The 14 days start today or on a later day, never an earlier one.']);
    ($this->owns)()->postJson('/api/back-office/pilot/start', ['first_day' => pilotDay($this, 61)])->assertStatus(422);
    // Set for tomorrow, it may move freely until it begins.
    ($this->owns)()->postJson('/api/back-office/pilot/start', ['first_day' => pilotDay($this, 1)])->assertOk();
    ($this->owns)()->postJson('/api/back-office/pilot/start', ['first_day' => $this->first])->assertOk();
    expect(pilotCard($this))->toMatchArray(['started' => true, 'measured' => 0, 'complete' => false])
        ->and(pilotCard($this)['start'])->toMatchArray(['first_day' => $this->first, 'by' => 'Halima', 'restarts' => 1]);

    // Once begun, starting again needs a reason, and never reaches back.
    ($this->owns)()->postJson('/api/back-office/pilot/start', ['first_day' => $this->first])->assertStatus(422);
    ($this->owns)()->postJson('/api/back-office/pilot/start', ['first_day' => $this->first, 'note' => 'The printer was not set up'])->assertOk();
    expect(DB::table('audit_log')->where('action', 'like', 'pilot.%')->orderBy('seq')->pluck('action')->all())->toBe(['pilot.started', 'pilot.started', 'pilot.restarted']);
});

it('measures trading days: a day with a sale or with the notebook\'s total, so a day the app missed still counts', function () {
    ($this->owns)()->postJson('/api/back-office/pilot/start', ['first_day' => $this->first])->assertOk();
    pilotAt($this, $this->first);
    pilotSell($this, [$this->a]);
    // The shop is shut on the second day; on the third it sells.
    pilotAt($this, pilotDay($this, 2));
    pilotSell($this, [$this->a]);
    expect(pilotCard($this)['days'])->toHaveCount(2);

    // The notebook shows sales on the second day that the app never had: that day counts, and fails.
    ($this->manages)()->postJson('/api/back-office/pilot/notebook', ['day' => pilotDay($this, 1), 'cash_kobo' => 500_000, 'transfer_kobo' => 0, 'pos_kobo' => 0])->assertOk();
    $card = pilotCard($this);
    expect(array_column($card['days'], 'day'))->toBe([$this->first, pilotDay($this, 1), pilotDay($this, 2)])
        ->and($card['days'][1])->toMatchArray(['n' => 2, 'app_kobo' => 0, 'notebook_kobo' => 500_000, 'left_kobo' => 500_000, 'verdict' => 'to_trace']);

    // Typed by mistake: put right with a new entry, and the shut day leaves the list again.
    ($this->manages)()->postJson('/api/back-office/pilot/notebook', ['day' => pilotDay($this, 1), 'cash_kobo' => 0, 'transfer_kobo' => 0, 'pos_kobo' => 0])->assertOk();
    expect(array_column(pilotCard($this)['days'], 'day'))->toBe([$this->first, pilotDay($this, 2)]);
    expect(DB::table('notebook_totals')->count())->toBe(6);

    // Before the first day, after today, or before the pilot starts, nothing is written.
    ($this->manages)()->postJson('/api/back-office/pilot/notebook', ['day' => pilotDay($this, -1), 'cash_kobo' => 0, 'transfer_kobo' => 0, 'pos_kobo' => 0])->assertStatus(422);
    ($this->manages)()->postJson('/api/back-office/pilot/notebook', ['day' => pilotDay($this, 3), 'cash_kobo' => 0, 'transfer_kobo' => 0, 'pos_kobo' => 0])->assertStatus(422);
    ($this->manages)()->postJson('/api/back-office/pilot/notebook', ['day' => pilotDay($this, 2), 'cash_kobo' => -1, 'transfer_kobo' => 0, 'pos_kobo' => 0])->assertStatus(422);
});

it('compares a day with the notebook, and whose record was wrong decides whether it matches', function () {
    ($this->owns)()->postJson('/api/back-office/pilot/start', ['first_day' => $this->first])->assertOk();
    pilotAt($this, $this->first);
    $first = pilotSell($this, [$this->a, $this->b]);
    pilotSell($this, [$this->c], 'transfer');
    pilotSell($this, [$this->a], 'pos');

    $day = ($this->manages)()->getJson("/api/back-office/pilot/days/{$this->first}")->assertOk()->json();
    expect($day)->toMatchArray(['n' => 1, 'today' => true, 'open' => true, 'app_kobo' => 400_000, 'notebook_kobo' => null, 'verdict' => 'no_notebook'])
        ->and($day['sales'])->toHaveCount(3)
        ->and($day['sales'][0])->toMatchArray(['receipt_number' => $first['receipt_number'], 'cash_kobo' => 200_000, 'transfer_kobo' => 0, 'cashier' => 'Amina', 'counter' => 'T01']);
    ($this->manages)()->postJson('/api/back-office/pilot/traces', ['day' => $this->first, 'method' => 'cash', 'amount_kobo' => 1, 'side' => 'notebook', 'what' => 'Too soon'])->assertStatus(422)
        ->assertJson(['message' => 'Enter the notebook\'s totals for the day first.']);

    // The notebook missed ₦1,000 of cash.
    ($this->manages)()->postJson('/api/back-office/pilot/notebook', ['day' => $this->first, 'cash_kobo' => 100_000, 'transfer_kobo' => 100_000, 'pos_kobo' => 100_000])->assertOk();
    $day = ($this->manages)()->getJson("/api/back-office/pilot/days/{$this->first}")->json();
    expect($day['verdict'])->toBe('to_trace')->and($day['methods'][0])->toMatchArray(['method' => 'cash', 'app_kobo' => 200_000, 'notebook_kobo' => 100_000, 'difference_kobo' => 100_000, 'left_kobo' => 100_000])
        ->and($day['methods'][1]['left_kobo'])->toBe(0)->and($day['methods'][2]['left_kobo'])->toBe(0);

    $trace = fn (array $body) => ($this->manages)()->postJson('/api/back-office/pilot/traces', ['day' => $this->first, 'method' => 'cash', 'side' => 'notebook', 'what' => 'A sale not written in the notebook', ...$body]);
    $trace(['amount_kobo' => 150_000])->assertStatus(422);
    $trace(['amount_kobo' => 100_000, 'receipt_number' => 'T09-E1-000001'])->assertStatus(422)->assertJsonPath('message', 'No sale has the receipt number T09-E1-000001. Check it on the receipt, or leave it out.');
    $trace(['amount_kobo' => 100_000, 'method' => 'transfer'])->assertStatus(422)->assertJson(['message' => 'Nothing is left to trace there.']);
    $id = $trace(['amount_kobo' => 100_000, 'receipt_number' => strtolower((string) $first['receipt_number'])])->assertOk()->json('id');
    $day = ($this->manages)()->getJson("/api/back-office/pilot/days/{$this->first}")->json();
    expect($day['verdict'])->toBe('matches_traced')
        ->and($day['traces'][0])->toMatchArray(['amount_kobo' => 100_000, 'side' => 'notebook', 'receipt_number' => $first['receipt_number'], 'by' => 'Kemi']);

    // Withdrawn, the difference is back to trace; traced to the app instead, the day does not match.
    ($this->manages)()->postJson("/api/back-office/pilot/traces/{$id}/withdraw")->assertOk();
    ($this->manages)()->postJson("/api/back-office/pilot/traces/{$id}/withdraw")->assertStatus(422);
    expect(($this->manages)()->getJson("/api/back-office/pilot/days/{$this->first}")->json('verdict'))->toBe('to_trace');
    $trace(['amount_kobo' => 40_000, 'side' => 'app', 'what' => 'Rung at ₦1,000; the customer paid ₦600'])->assertOk();
    $day = ($this->manages)()->getJson("/api/back-office/pilot/days/{$this->first}")->json();
    expect($day['verdict'])->toBe('app_wrong')->and($day['methods'][0]['left_kobo'])->toBe(60_000);

    // Totals that match from the start: matches, with nothing to trace.
    ($this->manages)()->postJson('/api/back-office/pilot/notebook', ['day' => $this->first, 'cash_kobo' => 200_000, 'transfer_kobo' => 100_000, 'pos_kobo' => 100_000])->assertOk();
    $app = DB::table('pilot_traces')->where('side', 'app')->value('id');
    ($this->owns)()->postJson("/api/back-office/pilot/traces/{$app}/withdraw")->assertOk();
    expect(($this->manages)()->getJson("/api/back-office/pilot/days/{$this->first}")->json('verdict'))->toBe('matches');

    ($this->as)('Sola', '2468')->postJson('/api/back-office/pilot/notebook', ['day' => $this->first, 'cash_kobo' => 0, 'transfer_kobo' => 0, 'pos_kobo' => 0])->assertForbidden();
    expect(DB::table('audit_log')->where('action', 'like', 'pilot.%')->orderBy('seq')->pluck('action')->all())
        ->toBe(['pilot.started', 'pilot.notebook_entered', 'pilot.traced', 'pilot.trace_withdrawn', 'pilot.traced', 'pilot.notebook_entered', 'pilot.trace_withdrawn']);
});

it('reads all five measures over 14 trading days, and counts cost coverage as it stood at the end of day 14', function () {
    ($this->owns)()->postJson('/api/back-office/pilot/start', ['first_day' => $this->first])->assertOk();
    // Item a has its cost before the pilot, so its sales carry it; b and c wait for one.
    ($this->owns)()->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 60_000, 'basis' => 'first_null'])->assertOk();
    for ($i = 0; $i < 14; $i++) {
        pilotAt($this, pilotDay($this, $i));
        // Two 3-item sales a day: fast on 11 days, slow on 3.
        pilotSell($this, [$this->a, $this->b, $this->c], 'cash', $i < 11 ? 22 : 41);
        pilotSell($this, [$this->a, $this->b, $this->c], 'transfer', $i < 11 ? 25 : 45);
        pilotSell($this, [$this->a], 'pos', qty: 5);
    }
    pilotAt($this, pilotDay($this, 13), '18:00');
    $card = pilotCard($this);
    expect($card)->toMatchArray(['measured' => 14, 'complete' => false])
        ->and($card['totals'])->toMatchArray(['status' => 'open', 'matching' => 0, 'waiting' => 14])
        ->and($card['speed']['status'])->toBe('open')->and($card['receipts']['status'])->toBe('open');
    expect(($this->manages)()->getJson('/api/back-office/badges')->json('pilot'))->toBe(13); // today is still trading

    // b's cost goes in on day 14, so its earlier sales are estimated in time; c's after the day ends.
    ($this->owns)()->postJson('/api/back-office/costs', ['item_ids' => [$this->b->id], 'unit_cost_kobo' => 50_000, 'basis' => 'first_null'])->assertOk();
    pilotAt($this, pilotDay($this, 14), '09:00');
    ($this->owns)()->postJson('/api/back-office/costs', ['item_ids' => [$this->c->id], 'unit_cost_kobo' => 40_000, 'basis' => 'first_null'])->assertOk();

    // The notebook matches on 9 days and is wrong on none; 5 days go to the app.
    for ($i = 0; $i < 14; $i++) {
        $cash = $i < 9 ? 300_000 : 200_000;
        ($this->manages)()->postJson('/api/back-office/pilot/notebook', ['day' => pilotDay($this, $i), 'cash_kobo' => $cash, 'transfer_kobo' => 300_000, 'pos_kobo' => 500_000])->assertOk();
    }
    expect(pilotCard($this)['totals'])->toMatchArray(['status' => 'open', 'matching' => 9, 'not_matching' => 0, 'waiting' => 5]);
    for ($i = 9; $i < 14; $i++) {
        ($this->manages)()->postJson('/api/back-office/pilot/traces', ['day' => pilotDay($this, $i), 'method' => 'cash', 'amount_kobo' => 100_000, 'side' => 'app', 'what' => 'A sale rung twice'])->assertOk();
    }
    $card = pilotCard($this);
    expect($card['complete'])->toBeTrue()->and($card['totals'])->toMatchArray(['status' => 'failed', 'matching' => 9, 'not_matching' => 5]);
    expect(($this->manages)()->getJson('/api/back-office/badges')->json('pilot'))->toBe(0);

    // The tenth day was the notebook's mistake after all: 10 of 14 match.
    $wrong = DB::table('pilot_traces')->where('business_day', pilotDay($this, 9))->value('id');
    ($this->manages)()->postJson("/api/back-office/pilot/traces/{$wrong}/withdraw")->assertOk();
    ($this->manages)()->postJson('/api/back-office/pilot/traces', ['day' => pilotDay($this, 9), 'method' => 'cash', 'amount_kobo' => 100_000, 'side' => 'notebook', 'what' => 'Not written down'])->assertOk();
    $card = pilotCard($this);
    expect($card['totals'])->toMatchArray(['status' => 'passed', 'matching' => 10, 'not_matching' => 4]);

    // Receipts: 42 sales, every number once and gapless, each with its receipt.
    expect($card['receipts'])->toMatchArray(['status' => 'passed', 'sales' => 42, 'duplicates' => 0, 'gaps' => [], 'no_receipt' => 0, 'lost_waiting' => 0]);
    // Speed: 28 timed 3-item sales; the typical one under 30 seconds on 11 of the 14 days.
    expect($card['speed'])->toMatchArray(['status' => 'passed', 'timed' => 28, 'days_with' => 14, 'days_under' => 11])
        ->and($card['speed']['cashiers'][0])->toMatchArray(['cashier' => 'Amina', 'count' => 28, 'under_target' => 22]);
    // Coverage at the end of day 14 (₦11,000 a day): a at sale (₦7,000), b estimated in time (₦2,000); c's
    // cost came the morning after, so its ₦2,000 still counts as waiting.
    expect($card['coverage'])->toMatchArray(['status' => 'passed', 'percent' => 81, 'confirmed_percent' => 63, 'estimated_percent' => 18, 'pending_percent' => 18, 'now_percent' => 100]);
    expect($card['passed'])->toBe(4);
});

it('fails speed with too few timed sales, and receipts when a number goes missing', function () {
    ($this->owns)()->postJson('/api/back-office/pilot/start', ['first_day' => $this->first])->assertOk();
    for ($i = 0; $i < 14; $i++) {
        pilotAt($this, pilotDay($this, $i));
        pilotSell($this, [$this->a, $this->b, $this->c], 'cash', 10);
    }
    pilotAt($this, pilotDay($this, 14));
    $card = pilotCard($this);
    expect($card['speed'])->toMatchArray(['status' => 'failed', 'timed' => 14, 'days_under' => 14]);
    expect($card['receipts']['status'])->toBe('passed');

    // A receipt number skipped (a sale lost and not re-entered) fails the receipts.
    DB::table('receipt_counters')->where('terminal_id', $this->t1->id)->increment('last_no');
    pilotAt($this, pilotDay($this, 13), '19:00');
    pilotSell($this, [$this->a]);
    expect(pilotCard($this)['receipts'])->toMatchArray(['status' => 'failed'])
        ->and(pilotCard($this)['receipts']['gaps'][0])->toMatchArray(['counter' => 'T01', 'epoch' => 1, 'missing' => 1]);
});

it('records the owner\'s decision with the scorecard, closes the record, and on continue the notebook stops a week later', function () {
    ($this->owns)()->postJson('/api/back-office/pilot/decision', ['decision' => 'continue'])->assertStatus(422);
    ($this->owns)()->postJson('/api/back-office/pilot/start', ['first_day' => $this->first])->assertOk();
    pilotAt($this, $this->first);
    pilotSell($this, [$this->a]);
    pilotAt($this, pilotDay($this, 1));

    ($this->manages)()->postJson('/api/back-office/pilot/decision', ['decision' => 'continue'])->assertForbidden();
    ($this->owns)()->postJson('/api/back-office/pilot/decision', ['decision' => 'maybe'])->assertStatus(422);
    ($this->owns)()->postJson('/api/back-office/pilot/decision', ['decision' => 'continue', 'note' => 'The app is ready'])->assertOk();
    $decision = pilotCard($this)['decision'];
    expect($decision)->toMatchArray(['decision' => 'continue', 'note' => 'The app is ready', 'by' => 'Halima', 'notebook_stops' => pilotDay($this, 8), 'changes' => 0])
        ->and($decision['scorecard'])->toMatchArray(['measured' => 1, 'complete' => false])->and($decision['scorecard'])->not->toHaveKey('decision');

    // The record is closed: no more totals or traces, and the 14 days cannot start again.
    ($this->manages)()->postJson('/api/back-office/pilot/notebook', ['day' => $this->first, 'cash_kobo' => 100_000, 'transfer_kobo' => 0, 'pos_kobo' => 0])->assertStatus(422)
        ->assertJson(['message' => 'The owner has decided, so the pilot\'s record is closed.']);
    ($this->owns)()->postJson('/api/back-office/pilot/start', ['first_day' => pilotDay($this, 2), 'note' => 'Again'])->assertStatus(422);
    expect(($this->manages)()->getJson("/api/back-office/pilot/days/{$this->first}")->json('open'))->toBeFalse();

    // Changing the decision needs a reason; both stay on record.
    ($this->owns)()->postJson('/api/back-office/pilot/decision', ['decision' => 'stop'])->assertStatus(422);
    ($this->owns)()->postJson('/api/back-office/pilot/decision', ['decision' => 'stop', 'note' => 'The owner changed his mind'])->assertOk();
    expect(pilotCard($this)['decision'])->toMatchArray(['decision' => 'stop', 'notebook_stops' => null, 'changes' => 1]);
    expect(DB::table('pilot_events')->where('kind', 'decided')->count())->toBe(2);
    expect(DB::table('audit_log')->where('action', 'pilot.decided')->count())->toBe(2);
});
