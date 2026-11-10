<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\ApprovalAction;
use App\Identity\Approvals;
use App\Identity\Enrolment;
use App\Identity\Role;
use App\Printing\PrinterConnection;
use App\Reports\DailySummary;
use App\Reports\Flags;
use App\Reports\Period;
use App\Reports\Profit;
use App\Sales\ApprovalNeeded;
use App\Sales\Finish;
use App\Sales\Reversals;
use App\Sales\SaleRefused;
use App\Shifts\Drawer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Counter;
use Tests\Support\People;
use Tests\Support\RecordingPrinter;

/*
 * Voids and returns (spec section 9; checks F1, F2 and F4 in section 14) [F7, F19; D68].
 */

beforeEach(function () {
    $this->printer = new RecordingPrinter;
    $this->app->instance(PrinterConnection::class, $this->printer);
    $this->counter = Counter::ready(items: 2);
    [$this->t1, $this->t2] = $this->counter['terminals'];
    $this->t1->forceFill(['printer_host' => '192.168.1.50'])->save();
    $this->cashier = $this->counter['cashier'];
    [$this->a, $this->b] = $this->counter['items'];
    $this->manager = People::add('Kemi', Role::Manager, '246810');
    $this->owner = People::add('Halima', Role::Owner, '135790');
    $this->reversals = app(Reversals::class);
    // ₦3,000 of A (two at ₦1,500) and ₦5,000 of B, paid in cash.
    $this->sale = app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(Counter::cashSale([$this->a->id => [2, 150_000], $this->b->id => [1, 500_000]])));
});

function lineOf(array $sale, string $itemId): string
{
    return (string) DB::table('sale_lines')->where('sale_id', $sale['id'])->where('item_id', $itemId)->value('id');
}

function returnOf(array $overrides = []): array
{
    return [
        'request_id' => (string) Str::uuid(), 'receipt_number' => test()->sale['receipt_number'],
        'lines' => [['line_id' => lineOf(test()->sale, test()->a->id), 'qty' => 1]],
        'reason' => 'defective', 'disposition' => 'supplier_claim', 'refund_method' => 'cash', 'refund_reference' => null, 'approval_token' => null,
        ...$overrides,
    ];
}

it('voids a sale in its shift with a manager\'s approval, and the void nets every total (check F1)', function () {
    $request = (string) Str::uuid();
    expect(fn () => $this->reversals->void($this->t1, $this->cashier, $this->sale['id'], 'Rang the wrong item', $request, null))
        ->toThrow(ApprovalNeeded::class, 'A manager must approve voiding');

    $token = app(Approvals::class)->grant($this->t1, $this->cashier->id, $this->manager->id, '246810', ApprovalAction::Void, $this->sale['id'])['token'];
    $void = $this->reversals->void($this->t1, $this->cashier, $this->sale['id'], 'Rang the wrong item', $request, $token);

    expect($void)->kind->toBe('void')->reverses_receipt_number->toBe('T01-E1-000001')->receipt_number->toBe('T01-E1-000002')->total_kobo->toBe(-800_000);
    expect($this->printer->lastText())->toContain(' VOID ')->toContain('Voids')->toContain('T01-E1-000001')->toContain('REFUND')->toContain('Cash back');

    // Every total nets it: the day, each method, profit.
    $day = DailySummary::sales($this->sale['business_day']);
    expect($day)->count->toBe(1)->total_kobo->toBe(0)->voids->toBe(['count' => 1, 'total_kobo' => -800_000]);
    expect(collect($day['by_method'])->firstWhere('method', 'cash')['amount_kobo'])->toBe(0);
    expect(Profit::buckets()['revenue_kobo'])->toBe(0);

    // The same request again gives the same void; a second void is refused.
    expect($this->reversals->void($this->t1, $this->cashier, $this->sale['id'], 'Rang the wrong item', $request, $token)['id'])->toBe($void['id']);
    expect(fn () => $this->reversals->void($this->t1, $this->manager, $this->sale['id'], 'Again', (string) Str::uuid(), null))
        ->toThrow(SaleRefused::class, 'already voided');
});

it('refuses a void after the shift closed, on another drawer, or once part of the sale was returned (check F1)', function () {
    expect(fn () => $this->reversals->void($this->t2, $this->manager, $this->sale['id'], 'Wrong', (string) Str::uuid(), null))
        ->toThrow(SaleRefused::class, 'another counter\'s drawer');

    $this->reversals->return($this->t1, $this->manager, returnOf());
    expect(fn () => $this->reversals->void($this->t1, $this->manager, $this->sale['id'], 'Wrong', (string) Str::uuid(), null))
        ->toThrow(SaleRefused::class, 'Part of this sale has been returned');

    $other = app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(Counter::cashSale([$this->b->id => [1, 500_000]])));
    app(Drawer::class)->close($this->t1, $this->cashier, 0);
    expect(fn () => $this->reversals->void($this->t1, $this->manager, $other['id'], 'Wrong', (string) Str::uuid(), null))
        ->toThrow(SaleRefused::class, 'shift is closed');
});

it('returns a line with a manager, a reason and a disposition, refunds it, and reverses its cost (check F2)', function () {
    $data = returnOf();
    expect(fn () => $this->reversals->return($this->t1, $this->cashier, $data))->toThrow(ApprovalNeeded::class, 'A manager must approve the return');

    $data['approval_token'] = app(Approvals::class)->grant($this->t1, $this->cashier->id, $this->manager->id, '246810', ApprovalAction::Return, $data['request_id'], null, 150_000)['token'];
    $return = $this->reversals->return($this->t1, $this->cashier, $data);

    expect($return)->kind->toBe('return')->total_kobo->toBe(-150_000)->reverses_receipt_number->toBe('T01-E1-000001');
    expect($this->printer->lastText())->toContain(' RETURN ')->toContain('Returns from')->toContain('Cash back')->toContain('1,500');
    expect(DB::table('returns')->first())->qty->toBe(1)->reason_code->toBe('defective')->disposition->toBe('supplier_claim')->approved_by->toBe($this->manager->id);

    // Its cost reverses the original's, so profit stays right: a cost entered later reaches both.
    expect(DailySummary::sales($this->sale['business_day']))->total_kobo->toBe(650_000)->returns->toBe(['count' => 1, 'total_kobo' => -150_000]);
    expect(Profit::buckets()['revenue_kobo'])->toBe(650_000);
    $line = DB::table('sale_lines')->where('sale_id', $return['id'])->first();
    expect($line)->qty->toBe(-1)->reverses_line_id->toBe(lineOf($this->sale, $this->a->id));

    // The screen shows what is left to return.
    $found = $this->reversals->find($this->t1, $this->sale['receipt_number']);
    expect(collect($found['lines'])->firstWhere('item_id', $this->a->id))->returned->toBe(1)->returnable->toBe(1);
    expect($found['can_void'])->toBeFalse();
});

it('never returns more than was sold (check F3, one counter)', function () {
    $this->reversals->return($this->t1, $this->manager, returnOf(['lines' => [['line_id' => lineOf($this->sale, $this->a->id), 'qty' => 2]]]));

    expect(fn () => $this->reversals->return($this->t1, $this->manager, returnOf()))->toThrow(SaleRefused::class, 'has already been returned');
    expect(fn () => $this->reversals->return($this->t1, $this->manager, returnOf(['lines' => [['line_id' => lineOf($this->sale, $this->b->id), 'qty' => 2]]])))
        ->toThrow(SaleRefused::class, 'Only 1 of');
});

it('takes goods back with no receipt only for the owner, and flags it (check F4)', function () {
    $data = returnOf(['receipt_number' => null, 'lines' => [['item_id' => $this->b->id, 'qty' => 1, 'unit_price_kobo' => 450_000]], 'refund_method' => 'pos']);
    expect(fn () => $this->reversals->return($this->t1, $this->manager, $data))->toThrow(ApprovalNeeded::class, 'Only the owner');

    // A manager's approval is not enough; the owner's is.
    $data['approval_token'] = app(Approvals::class)->grant($this->t1, $this->manager->id, $this->manager->id, '246810', ApprovalAction::Return, $data['request_id'], null, 450_000)['token'];
    expect(fn () => $this->reversals->return($this->t1, $this->manager, $data))->toThrow(ApprovalNeeded::class, 'Only the owner');
    $return = $this->reversals->return($this->t1, $this->owner, [...$data, 'approval_token' => null, 'request_id' => (string) Str::uuid()]);

    expect($return)->total_kobo->toBe(-450_000)->reverses_receipt_number->toBeNull();
    expect(DB::table('returns')->value('original_line_id'))->toBeNull();
    $flags = app(Flags::class)->list(Period::named('all', 'Africa/Lagos'), false, ['return_no_receipt']);
    expect($flags)->toHaveCount(1);
    expect($flags[0])->amount_kobo->toBe(450_000)->who->toBe('Halima');
});

it('works over HTTP: find the receipt, ask for the manager, then return', function () {
    $device = People::enrol($this->t1);
    $token = (string) $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $this->cashier->id, 'pin' => '1357'])->json('token');
    $as = fn () => $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token);

    $as()->getJson('/api/counter/receipts/T01-E1-000001')->assertOk()->assertJsonPath('sale.lines.0.returnable', 2)->assertJsonPath('sale.can_void', true);
    $as()->getJson('/api/counter/receipts/T01-E1-009999')->assertStatus(422);

    $body = returnOf();
    $as()->postJson('/api/counter/returns', $body)->assertStatus(422)->assertJsonPath('approval.action', 'return')->assertJsonPath('approval.price_kobo', 150_000);
    $approved = $as()->postJson('/api/identity/approvals', ['approver_id' => $this->manager->id, 'pin' => '246810', 'action' => 'return',
        'subject_id' => $body['request_id'], 'price_kobo' => 150_000])->assertOk()->json('approval_token');
    $as()->postJson('/api/counter/returns', [...$body, 'approval_token' => $approved])->assertOk()->assertJsonPath('sale.kind', 'return');
    // A retry after a lost answer gives the same return.
    $as()->postJson('/api/counter/returns', [...$body, 'approval_token' => $approved])->assertOk()->assertJsonPath('sale.retried', true);
    expect(DB::table('sales')->where('kind', 'return')->count())->toBe(1);
});
