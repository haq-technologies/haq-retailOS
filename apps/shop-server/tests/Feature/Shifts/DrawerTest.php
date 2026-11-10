<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Role;
use App\Platform\Ulid;
use App\Printing\PrinterConnection;
use App\Reports\Flags;
use App\Reports\Period;
use App\Sales\Finish;
use App\Sales\Reversals;
use App\Settings\Settings;
use App\Shifts\Drawer;
use App\Shifts\DrawerRefused;
use App\Shifts\NeedsApproval;
use App\Shifts\Shifts;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Counter;
use Tests\Support\People;
use Tests\Support\RecordingPrinter;

/*
 * The drawer beside sales and closing the day (spec section 9; checks F5 and F6 in section 14) [F19; D68].
 */

beforeEach(function () {
    $this->printer = new RecordingPrinter;
    $this->app->instance(PrinterConnection::class, $this->printer);
    $this->counter = Counter::ready(items: 1);
    [$this->t1] = $this->counter['terminals'];
    $this->t1->forceFill(['printer_host' => '192.168.1.50'])->save();
    DB::table('drawers')->insert(['id' => Ulid::generate(), 'branch_id' => $this->t1->branch_id, 'terminal_id' => $this->t1->id,
        'label' => 'T01 drawer', 'fitted' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->cashier = $this->counter['cashier'];
    [$this->item] = $this->counter['items'];
    $this->manager = People::add('Kemi', Role::Manager, '246810');
    $this->drawer = app(Drawer::class);
    app(Shifts::class)->openFor($this->t1, $this->cashier->id, 1_000_000); // a ₦10,000 float
});

function cashOut(string $type, int $kobo, ?object $who = null, ?string $token = null, ?string $request = null): array
{
    return test()->drawer->record(test()->t1, $who ?? test()->cashier, $type, $kobo, 'Transport for stock', $request ?? (string) Str::uuid(), $token);
}

it('needs a reason, and a manager above the owner\'s limit, for a paid-out, a drop or a top-up (check F6)', function () {
    expect(fn () => test()->drawer->record($this->t1, $this->cashier, 'paid_out', 50_000, ' ', (string) Str::uuid(), null))
        ->toThrow(DrawerRefused::class, 'Say what the cash is for');
    // At the default limit of ₦0 every one needs a manager.
    expect(fn () => cashOut('paid_out', 50_000))->toThrow(NeedsApproval::class, 'a manager must approve');

    app(Settings::class)->set('cash.approval_limit_kobo', 100_000, 'owner');
    expect(cashOut('paid_out', 50_000))->already->toBeFalse();
    expect(fn () => cashOut('drop', 500_000))->toThrow(NeedsApproval::class);
    expect(cashOut('drop', 500_000, $this->manager))->type->toBe('drop');
    expect(cashOut('top_up', 200_000, $this->manager))->type->toBe('top_up');

    // A retry with the same request records it once.
    $request = (string) Str::uuid();
    cashOut('paid_out', 20_000, request: $request);
    expect(cashOut('paid_out', 20_000, request: $request))->already->toBeTrue();
    expect(DB::table('cash_events')->where('type', 'paid_out')->count())->toBe(2);

    // Expected cash: ₦10,000 float - ₦500 - ₦200 paid out - ₦5,000 dropped + ₦2,000 topped up.
    $shift = (string) app(Shifts::class)->current($this->t1)?->id;
    expect($this->drawer->figures($shift))->expected_cash_kobo->toBe(630_000)->paid_out_kobo->toBe(70_000)->drop_kobo->toBe(500_000)->top_up_kobo->toBe(200_000);
});

it('closes the day with a blind count, the variance, a flag above the threshold and the Z-report (check F5)', function () {
    $sale = app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(Counter::cashSale([$this->item->id => [2, 300_000]])));
    app(Reversals::class)->return($this->t1, $this->manager, ['request_id' => (string) Str::uuid(), 'receipt_number' => $sale['receipt_number'],
        'lines' => [['line_id' => (string) DB::table('sale_lines')->where('sale_id', $sale['id'])->value('id'), 'qty' => 1]],
        'reason' => 'changed_mind', 'disposition' => 'restock', 'refund_method' => 'cash', 'refund_reference' => null, 'approval_token' => null]);
    cashOut('paid_out', 40_000, $this->manager);

    // Expected: ₦10,000 + ₦6,000 sold - ₦3,000 refunded - ₦400 paid out = ₦12,600. Counted ₦12,000: short ₦600.
    $device = People::enrol($this->t1);
    $token = (string) $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $this->cashier->id, 'pin' => '1357'])->json('token');
    $as = fn () => $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token);
    // Blind: before the count, the counter learns only that a shift is open.
    $as()->getJson('/api/counter/close')->assertOk()->assertJsonMissingPath('open.expected_cash_kobo')->assertJsonPath('open.business_day', $sale['business_day']);

    $closed = $as()->postJson('/api/counter/close', ['counted_kobo' => 1_200_000])->assertOk()
        ->assertJsonPath('shift.expected_cash_kobo', 1_260_000)->assertJsonPath('shift.variance_kobo', -60_000)
        ->assertJsonPath('shift.flagged', false)->assertJsonPath('printed', true)->json();
    expect($closed['audit_code'])->toMatch('/^[0-9A-F]{4}( [0-9A-F]{4}){7}$/');
    expect($this->printer->lastText())->toContain('Z-REPORT')->toContain('Returns 1')->toContain('Expected')->toContain('12,600')
        ->toContain('Short by')->toContain('600')->toContain(substr($closed['audit_code'], 0, 9));

    // The ₦600 is under the ₦1,000 threshold; a lower threshold flags it.
    expect(app(Flags::class)->list(Period::named('all', 'Africa/Lagos'), false, ['cash_variance']))->toBe([]);
    app(Settings::class)->set('cash.variance_flag_kobo', 50_000, 'owner');
    expect(app(Flags::class)->list(Period::named('all', 'Africa/Lagos'), false, ['cash_variance'])[0]['title'])->toBe('T01 drawer short by ₦600');

    // A closed shift never changes, a second close is refused, and the next sale opens a new shift.
    // (In a savepoint, so the refusal does not end the test's own transaction.)
    expect(fn () => DB::transaction(fn () => DB::table('shifts')->where('id', $closed['shift']['shift_id'])->update(['opening_float_kobo' => 0])))
        ->toThrow(QueryException::class, 'closed and cannot change');
    $as()->postJson('/api/counter/close', ['counted_kobo' => 0])->assertStatus(422)->assertJsonPath('message', 'There is no open shift on this counter to close.');
    $next = app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(Counter::cashSale([$this->item->id => [1, 300_000]])));
    expect(DB::table('sales')->where('id', $next['id'])->value('shift_id'))->not->toBe($closed['shift']['shift_id']);

    // The Z-report prints again on request.
    $as()->postJson('/api/counter/close/print')->assertOk()->assertJsonPath('shift.shift_id', $closed['shift']['shift_id'])->assertJsonPath('printed', true);
});

it('records cash over HTTP, asking for a manager, and opens a fitted drawer', function () {
    $device = People::enrol($this->t1);
    $token = (string) $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $this->cashier->id, 'pin' => '1357'])->json('token');
    $as = fn () => $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token);
    $body = ['type' => 'paid_out', 'amount_kobo' => 30_000, 'reason' => 'Bread for the staff', 'request_id' => (string) Str::uuid()];

    $as()->postJson('/api/counter/drawer/cash', [...$body, 'reason' => ''])->assertStatus(422)->assertJsonPath('message', 'Say what the cash is for.');
    $as()->postJson('/api/counter/drawer/cash', $body)->assertStatus(422)->assertJsonPath('approval.action', 'paid_out')->assertJsonPath('approval.price_kobo', 30_000);
    $approved = $as()->postJson('/api/identity/approvals', ['approver_id' => $this->manager->id, 'pin' => '246810', 'action' => 'paid_out',
        'subject_id' => $body['request_id'], 'price_kobo' => 30_000])->assertOk()->json('approval_token');
    $as()->postJson('/api/counter/drawer/cash', [...$body, 'approval_token' => $approved])->assertOk()->assertJsonPath('drawer', 'opened');
    expect($this->printer->lastText())->toContain('[drawer]');
    expect(DB::table('cash_events')->where('type', 'paid_out')->value('approved_by'))->toBe($this->manager->id);
});
