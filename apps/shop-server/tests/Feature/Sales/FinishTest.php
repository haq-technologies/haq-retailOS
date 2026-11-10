<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Organisation\BranchKey;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Sales\Epochs;
use App\Sales\Finish;
use App\Sales\SaleChanged;
use App\Sales\SaleRefused;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * Finish (spec section 7; checks in section 14 A and B) [F1, F5, F12, F14].
 */

beforeEach(function () {
    $this->counter = Counter::ready();
    [$this->t1, $this->t2] = $this->counter['terminals'];
    $this->cashier = $this->counter['cashier'];
    [$this->a, $this->b] = $this->counter['items'];
    $this->finish = app(Finish::class);
});

function sell(Terminal $terminal, array $data): array
{
    return test()->finish->finish($terminal, test()->cashier, Counter::request($data));
}

it('finishes a cash sale with a receipt number, change and a pending print job', function () {
    $data = Counter::cashSale([$this->a->id => [2, 150_000], $this->b->id => [1, 99_950]]);
    $data['payments'][0]['tendered_kobo'] = 500_000;

    $sale = sell($this->t1, $data);

    expect($sale['receipt_number'])->toBe('T01-E1-000001');
    expect($sale['total_kobo'])->toBe(399_950);
    expect($sale['change_kobo'])->toBe(100_050);
    expect($sale['lines'][0]['name'])->toBe('Thing item 1');
    // The print job is committed with the sale; this counter has no printer set, so it waits at the counter.
    expect(DB::table('print_jobs')->where('sale_id', $sale['id'])->where('kind', 'receipt')->count())->toBe(1);
    expect($sale['print_status'])->toBeIn(['pending', 'failed']);
    expect(DB::table('audit_log')->where('action', 'sale.finished')->where('terminal_id', $this->t1->id)->count())->toBe(1);
    expect(DB::table('shifts')->where('unattributed', true)->count())->toBe(1);
});

it('numbers receipts gaplessly per terminal', function () {
    expect(sell($this->t1, Counter::cashSale([$this->a->id => [1, 1000]]))['receipt_number'])->toBe('T01-E1-000001');
    expect(sell($this->t1, Counter::cashSale([$this->a->id => [1, 1000]]))['receipt_number'])->toBe('T01-E1-000002');
    expect(sell($this->t2, Counter::cashSale([$this->a->id => [1, 1000]]))['receipt_number'])->toBe('T02-E1-000001');
});

it('signs each epoch with the branch key', function () {
    sell($this->t1, Counter::cashSale([$this->a->id => [1, 1000]]));
    $epoch = DB::table('epochs')->where('terminal_id', $this->t1->id)->first();
    $publicKey = (string) DB::table('branches')->where('is_local', true)->value('public_key');

    $message = Epochs::message($this->t1->id, 1, (string) $epoch->started_at, (string) $epoch->reason);
    $stored = DB::table('epochs')->where('terminal_id', $this->t1->id)->value('started_at');
    expect($epoch->reason)->toBe('install');
    // The signature covers the time exactly as written.
    $written = CarbonImmutable::parse((string) $stored)->format('Y-m-d\TH:i:s.u\Z');
    expect(app(BranchKey::class)->verify(Epochs::message($this->t1->id, 1, $written, 'install'), (string) $epoch->signature, $publicKey))->toBeTrue();
    expect(app(BranchKey::class)->verify(Epochs::message($this->t1->id, 2, $written, 'install'), (string) $epoch->signature, $publicKey))->toBeFalse();
});

it('returns the original sale for a retry with the same key and cart', function () {
    $data = Counter::cashSale([$this->a->id => [1, 5000]]);
    $first = sell($this->t1, $data);
    $again = sell($this->t1, $data);

    expect($again['id'])->toBe($first['id']);
    expect($again['retried'])->toBeTrue();
    expect(DB::table('sales')->count())->toBe(1);
});

it('refuses a changed cart under the same key, and creates nothing', function () {
    $data = Counter::cashSale([$this->a->id => [1, 5000]]);
    sell($this->t1, $data);
    $data['lines'][0]['qty'] = 2;
    $data['payments'][0]['amount_kobo'] = 10_000;
    $data['payments'][0]['tendered_kobo'] = 10_000;

    expect(fn () => sell($this->t1, $data))->toThrow(SaleChanged::class, 'This sale changed. Review it and finish again.');
    expect(DB::table('sales')->count())->toBe(1);
});

it('recalculates the total and refuses payments that do not match it (a tampered browser total)', function () {
    $data = Counter::cashSale([$this->a->id => [3, 1000]]);
    $data['payments'][0]['amount_kobo'] = 2000; // the browser claims a lower total

    expect(fn () => sell($this->t1, $data))->toThrow(SaleRefused::class, 'The payments do not add up to the total');
    expect(DB::table('sales')->count())->toBe(0);
    expect(DB::table('receipt_counters')->where('terminal_id', $this->t1->id)->value('last_no'))->toBeNull();
});

it('keeps money exact in kobo for a wholesale quantity', function () {
    $data = Counter::cashSale([$this->a->id => [120, 125_050]], ['customer_type' => 'wholesale']);
    $sale = sell($this->t1, $data);
    expect($sale['total_kobo'])->toBe(15_006_000);
    expect(DB::table('sale_lines')->value('line_total_kobo'))->toBe(15_006_000);
});

it('needs the alert-seen tick for a transfer, and keeps the reference and who confirmed it', function () {
    $data = Counter::cashSale([$this->a->id => [1, 20_000]]);
    $data['payments'] = [['method' => 'transfer', 'amount_kobo' => 20_000, 'reference' => 'Ade 4421', 'alert_seen' => false]];
    expect(fn () => sell($this->t1, $data))->toThrow(SaleRefused::class, 'Tick that you saw the transfer alert');

    $data['payments'][0]['alert_seen'] = true;
    sell($this->t1, $data);
    $payment = DB::table('payments')->first();
    expect($payment->reference)->toBe('Ade 4421');
    expect($payment->confirmed_by)->toBe($this->cashier->id);
});

it('takes a POS payment with nothing typed (D11), and a split', function () {
    $data = Counter::cashSale([$this->a->id => [1, 30_000]]);
    $data['payments'] = [['method' => 'pos', 'amount_kobo' => 20_000], ['method' => 'cash', 'amount_kobo' => 10_000, 'tendered_kobo' => 10_000]];
    $sale = sell($this->t1, $data);
    expect(array_column($sale['payments'], 'method'))->toBe(['pos', 'cash']);
});

it('refuses cash given below the cash due', function () {
    $data = Counter::cashSale([$this->a->id => [1, 30_000]]);
    $data['payments'][0]['tendered_kobo'] = 20_000;
    expect(fn () => sell($this->t1, $data))->toThrow(SaleRefused::class, 'The cash given is less than the cash due');
});

it('snapshots the current cost, or NULL when none is known, and never shows it', function () {
    DB::table('cost_entries')->insert(['id' => Ulid::generate(), 'item_id' => $this->a->id, 'unit_cost_kobo' => 80_000, 'effective_from' => now()->subDay()]);
    $sale = sell($this->t1, Counter::cashSale([$this->a->id => [1, 150_000], $this->b->id => [1, 50_000]]));

    expect(DB::table('sale_lines')->where('item_id', $this->a->id)->value('cost_snapshot_kobo'))->toBe(80_000);
    expect(DB::table('sale_lines')->where('item_id', $this->b->id)->value('cost_snapshot_kobo'))->toBeNull();
    expect(json_encode($sale))->not->toContain('cost')->not->toContain('80000');
});

it('refuses to sell from a back-office device (D29)', function () {
    $device = Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'D01', 'name' => 'Owner phone', 'kind' => 'back_office']);
    expect(fn () => sell($device, Counter::cashSale([$this->a->id => [1, 1000]])))->toThrow(SaleRefused::class, 'It cannot sell');
});

it('refuses a merged or archived item, and a line with no price', function () {
    DB::table('items')->where('id', $this->a->id)->update(['status' => 'archived']);
    expect(fn () => sell($this->t1, Counter::cashSale([$this->a->id => [1, 1000]])))->toThrow(SaleRefused::class, 'was merged or archived');

    $data = Counter::cashSale([$this->b->id => [1, 1000]]);
    $data['lines'][0]['unit_price_kobo'] = 0;
    expect(fn () => sell($this->t1, $data))->toThrow(SaleRefused::class, 'Every item needs a price');
});

it('logs a refused sale after the fact, never blocking the counter', function () {
    $data = Counter::cashSale([$this->a->id => [1, 1000]]);
    $data['payments'][0]['amount_kobo'] = 1;
    try {
        sell($this->t1, $data);
    } catch (SaleRefused) {
    }

    // Inside the test's transaction the separate logging connection cannot see the terminal, so the entry spools.
    $spool = (string) file_get_contents($this->spoolPath);
    expect($spool)->toContain('sale.refused')->toContain('The payments do not add up');
});

it('closes a forgotten shift four hours after closing time, and the next sale lands on the new day (S12)', function () {
    app(Settings::class)->set('shifts.closing_time', '21:00', 'profile');
    $this->travelTo(CarbonImmutable::parse('2026-11-09 18:00', 'Africa/Lagos'));
    $first = sell($this->t1, Counter::cashSale([$this->a->id => [1, 1000]]));

    $this->travelTo(CarbonImmutable::parse('2026-11-10 00:30', 'Africa/Lagos'));
    $this->artisan('shifts:auto-close')->expectsOutputToContain('No forgotten shifts')->assertSuccessful();

    $this->travelTo(CarbonImmutable::parse('2026-11-10 01:05', 'Africa/Lagos'));
    $this->artisan('shifts:auto-close')->expectsOutputToContain('Closed 1')->assertSuccessful();
    expect(DB::table('shifts')->where('status', 'auto_closed')->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', 'Africa/Lagos'));
    $next = sell($this->t1, Counter::cashSale([$this->a->id => [1, 1000]]));
    expect($first['business_day'])->toBe('2026-11-09');
    expect($next['business_day'])->toBe('2026-11-10');
});

it('finishes over HTTP for the signed-in cashier', function () {
    $device = People::enrol($this->t1);
    $token = (string) $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $this->cashier->id, 'pin' => '1357'])->json('token');

    $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token)
        ->postJson('/api/counter/sales', Counter::cashSale([$this->a->id => [1, 2500]]))
        ->assertOk()->assertJsonPath('sale.receipt_number', 'T01-E1-000001')->assertJsonPath('sale.cashier', 'Amina');

    $bad = Counter::cashSale([$this->a->id => [1, 2500]]);
    $bad['payments'][0]['amount_kobo'] = 100;
    $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token)
        ->postJson('/api/counter/sales', $bad)->assertStatus(422)->assertJsonPath('message', 'The payments do not add up to the total. Check the amounts.');
});

it('tells a counter whether it finished a sale under a key, so an unanswered Finish is never sold twice (D61)', function () {
    $data = Counter::cashSale([$this->a->id => [1, 150_000]]);
    $sale = sell($this->t1, $data);
    $at = function (Terminal $terminal) {
        $device = People::enrol($terminal);
        $token = (string) $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $this->cashier->id, 'pin' => '1357'])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token);
    };

    $at($this->t1)->getJson("/api/counter/sales/by-key/{$data['idempotency_key']}")
        ->assertOk()->assertJsonPath('sale.receipt_number', $sale['receipt_number'])->assertJsonPath('sale.retried', true);
    $at($this->t1)->getJson('/api/counter/sales/by-key/no-sale-here-123')->assertNotFound();
    // Keys belong to their counter: another counter's lookup finds nothing.
    $at($this->t2)->getJson("/api/counter/sales/by-key/{$data['idempotency_key']}")->assertNotFound();
});
