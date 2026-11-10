<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Platform\Ulid;
use App\Printing\PrinterConnection;
use App\Printing\PrinterUnavailable;
use App\Printing\Printing;
use App\Sales\Finish;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Tests\Support\Counter;
use Tests\Support\People;
use Tests\Support\RecordingPrinter;

/*
 * Server printing (spec sections 4 and 7; checks in section 14 A, B and D) [F5, F12, R3, S9, S21].
 */

beforeEach(function () {
    $this->printer = new RecordingPrinter;
    $this->app->instance(PrinterConnection::class, $this->printer);
    $this->counter = Counter::ready(items: 2);
    [$this->t1, $this->t2] = $this->counter['terminals'];
    $this->cashier = $this->counter['cashier'];
    [$this->a, $this->b] = $this->counter['items'];
    $this->t1->forceFill(['printer_host' => '192.168.1.50', 'printer_port' => 9100])->save();
    DB::table('drawers')->insert(['id' => Ulid::generate(), 'branch_id' => $this->t1->branch_id, 'terminal_id' => $this->t1->id,
        'label' => 'T01 drawer', 'fitted' => true, 'created_at' => now(), 'updated_at' => now()]);
    $settings = app(Settings::class);
    $settings->set('receipt.shop_name', 'Example Accessories', 'profile');
    $settings->set('receipt.address', '1 Example Street', 'profile');
    $settings->set('receipt.phone', '0800 000 0000', 'profile');
    $settings->set('receipt.footer', 'Goods sold in good condition.', 'profile');
});

function sale(array $lines, array $overrides = []): array
{
    return app(Finish::class)->finish(test()->t1, test()->cashier, Counter::request(Counter::cashSale($lines, $overrides)));
}

it('prints the receipt as the wireframe draws it, after the sale commits', function () {
    $data = Counter::cashSale([$this->a->id => [1, 1_400_000], $this->b->id => [2, 300_000]]);
    $data['lines'][0]['colour'] = 'Black';
    $data['payments'][0]['tendered_kobo'] = 2_500_000;
    $sale = app(Finish::class)->finish($this->t1, $this->cashier, Counter::request($data));

    $text = $this->printer->lastText();
    expect($text)
        ->toContain('Example Accessories')->toContain('1 Example Street')->toContain('0800 000 0000')
        ->toContain('Receipt')->toContain('T01-E1-000001')->toContain('Counter 1')->toContain('Amina')
        ->toContain('Thing item 1')->toContain('  Colour: Black')->toContain('  1 x 14,000')
        ->toContain('  2 x 3,000')->toContain('6,000')
        ->toContain('TOTAL')->toContain('NGN 20,000')
        ->toContain('Cash')->toContain('25,000')->toContain('Change')->toContain('5,000')
        ->toContain('[barcode T01-E1-000001]')
        ->toContain('Goods sold in good condition.')
        ->toContain('[cut]');
    expect($this->printer->sent[0]['host'])->toBe('192.168.1.50');
    expect(DB::table('print_jobs')->where('sale_id', $sale['id'])->value('status'))->toBe('printed');
});

it('opens a fitted drawer with a cash sale and logs the opening, but not with a transfer', function () {
    sale([$this->a->id => [1, 5000]]);
    expect($this->printer->lastText())->toContain('[drawer]');
    expect(DB::table('cash_events')->where('type', 'sale_open')->count())->toBe(1);

    $transfer = Counter::cashSale([$this->a->id => [1, 5000]]);
    $transfer['payments'] = [['method' => 'transfer', 'amount_kobo' => 5000, 'reference' => 'Ade', 'alert_seen' => true]];
    app(Finish::class)->finish($this->t1, $this->cashier, Counter::request($transfer));
    expect($this->printer->lastText())->not->toContain('[drawer]')->toContain('Transfer')->toContain('Ref: Ade');
    expect(DB::table('cash_events')->where('type', 'sale_open')->count())->toBe(1);
});

it('keeps a receipt pending when the printer is off, and prints it when asked again', function () {
    $this->printer->online = false;
    $sale = sale([$this->a->id => [1, 5000]]);
    $job = DB::table('print_jobs')->where('sale_id', $sale['id'])->first();
    expect($job->status)->toBe('pending');

    // The queue tries three times in all; then the job is failed and stays on the counter.
    app(Printing::class)->print($job->id);
    app(Printing::class)->print($job->id);
    expect(DB::table('print_jobs')->where('id', $job->id)->first())->status->toBe('failed')->attempts->toBe(3);
    expect(DB::table('sales')->count())->toBe(1);

    $device = People::enrol($this->t1);
    $token = (string) $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $this->cashier->id, 'pin' => '1357'])->json('token');
    $as = fn () => $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token);

    $this->travel(10)->seconds();
    $as()->getJson('/api/counter/print-jobs')->assertOk()->assertJsonPath('print_jobs.0.receipt_number', 'T01-E1-000001');

    $this->printer->online = true;
    $as()->postJson("/api/counter/print-jobs/{$job->id}/retry")->assertOk();
    expect(DB::table('print_jobs')->where('id', $job->id)->value('status'))->toBe('printed');
    expect($this->printer->lastText())->not->toContain('DUPLICATE');
    $as()->getJson('/api/counter/print-jobs')->assertJsonCount(0, 'print_jobs');
});

it('prints a stamped copy, with the drawer shut, when an earlier send never reported back (D63)', function () {
    $sale = sale([$this->a->id => [1, 5000]]);
    $job = (string) DB::table('print_jobs')->where('sale_id', $sale['id'])->value('id');
    expect($this->printer->lastText())->toContain('[drawer]');
    $this->printer->sent = [];

    // The server lost power after the printer was sent the receipt but before it was marked printed.
    DB::table('print_jobs')->where('id', $job)->update(['status' => 'sending']);
    $device = People::enrol($this->t1);
    $token = (string) $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $this->cashier->id, 'pin' => '1357'])->json('token');
    $as = fn () => $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token);
    $this->travel(10)->seconds();
    $as()->getJson('/api/counter/print-jobs')->assertJsonPath('print_jobs.0.status', 'sending');

    $as()->postJson("/api/counter/print-jobs/{$job}/retry")->assertOk();
    expect($this->printer->lastText())->toContain('DUPLICATE')->toContain('T01-E1-000001')->toContain('by the server')->not->toContain('[drawer]');
    expect(DB::table('print_jobs')->where('id', $job)->value('status'))->toBe('printed');
    expect(DB::table('cash_events')->where('type', 'sale_open')->count())->toBe(1);
    expect(DB::table('audit_log')->where('action', 'print.outcome_unknown')->where('subject_id', $job)->count())->toBe(1);

    // The queue finding a job still marked sending does the same.
    $second = (string) DB::table('print_jobs')->where('sale_id', sale([$this->a->id => [1, 5000]])['id'])->value('id');
    DB::table('print_jobs')->where('id', $second)->update(['status' => 'sending']);
    expect(app(Printing::class)->print($second))->toBeTrue();
    expect($this->printer->lastText())->toContain('DUPLICATE')->toContain('T01-E1-000002')->not->toContain('[drawer]');
});

it('reprints with the original number, stamped DUPLICATE, and logs it', function () {
    $sale = sale([$this->a->id => [1, 5000]]);
    $device = People::enrol($this->t1);
    $token = (string) $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $this->cashier->id, 'pin' => '1357'])->json('token');

    $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token)
        ->postJson("/api/counter/sales/{$sale['id']}/reprint")->assertOk();

    expect($this->printer->lastText())->toContain('DUPLICATE')->toContain('T01-E1-000001')->toContain('Reprinted')->toContain('by Amina')
        ->not->toContain('Goods sold in good condition.')->not->toContain('[drawer]');
    expect(DB::table('audit_log')->where('action', 'print.reprint_requested')->count())->toBe(1);
    expect(DB::table('sales')->count())->toBe(1);
});

it('opens the drawer for No sale with a reason, logs a key opening, and refuses both without a drawer', function () {
    app(Printing::class)->noSale($this->t1, $this->cashier, 'Change for a customer');
    expect($this->printer->lastText())->toContain('[drawer]');
    expect(DB::table('cash_events')->where('type', 'no_sale_open')->value('reason'))->toBe('Change for a customer');

    app(Printing::class)->openedWithKey($this->t1, $this->cashier, null);
    expect(DB::table('cash_events')->where('type', 'key_open')->count())->toBe(1);

    expect(fn () => app(Printing::class)->noSale($this->t2, $this->cashier, 'Change'))->toThrow(PrinterUnavailable::class, 'no cash drawer');
});

it('prints the sign the shop chooses before the total (D31)', function () {
    app(Settings::class)->set('receipt.currency', 'N', 'console');
    sale([$this->a->id => [1, 250_000]]);
    expect($this->printer->lastText())->toContain('N2,500')->not->toContain('NGN');
});
