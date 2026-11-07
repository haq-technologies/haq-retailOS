<?php

declare(strict_types=1);

use App\Audit\Verifier;
use App\Counter\Feed;
use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Reports\DailySummary;
use App\Reports\Flags;
use App\Reports\Period;
use App\Sales\Finish;
use App\Sales\Journal;
use App\Sales\JournalNeeded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Catalogue;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * Restore epochs end to end and the lost window (spec sections 4 and 9; check B9 in section 14) [F1, S14,
 * D44]: after a restore each counter reports its journal before it sells again; its epoch is raised past
 * anything the journal shows; the sales the journals hold and the restored data lacks are listed, and a
 * manager re-enters each under its original receipt number; the counters take a fresh catalogue.
 */

beforeEach(function () {
    $this->dir = storage_path('framework/testing/recovery-'.Str::random(8));
    mkdir($this->dir, 0o777, true);
    config(['backups.status_dir' => $this->dir]);
    $this->counter = Counter::ready(items: 2);
    [$this->t1, $this->t2] = $this->counter['terminals'];
    [$this->a, $this->b] = $this->counter['items'];
    $this->cashier = $this->counter['cashier'];
    $this->manager = People::add('Kemi', Role::Manager, '246810');
    People::add('Halima', Role::Owner, '135790');
    $this->counterDevice = People::enrol($this->t1);
    $this->office = People::enrol(Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'D01', 'name' => 'Owner laptop', 'kind' => 'back_office']));
    $this->sell = fn (Terminal $t, int $price = 5000) => app(Finish::class)->finish($t, $this->cashier, Counter::request(Counter::cashSale([$this->a->id => [1, $price]])));
    $this->back = function (string $name = 'Kemi', string $pin = '246810') {
        $user = User::query()->where('name', $name)->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->office)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $this->office)->withHeader(ActingToken::HEADER, $token);
    };
});

afterEach(function () {
    foreach (glob($this->dir.'/{,*/}*', GLOB_BRACE) ?: [] as $f) {
        is_file($f) && unlink($f);
    }
    @rmdir($this->dir.'/keys');
    @rmdir($this->dir);
});

/** A journal entry as the counter keeps it (resources/js/counter/recovery.ts), for a sale the server may lack. */
function lostEntry(string $receipt, string $itemId, int $price, string $cashierId, int $qty = 1, string $method = 'cash', ?string $at = null): array
{
    return [
        'receipt_number' => $receipt, 'at' => $at ?? now()->subMinutes(5)->toIso8601String(), 'business_day' => now('Africa/Lagos')->toDateString(),
        'cashier_id' => $cashierId, 'cashier' => 'Amina', 'customer_type' => 'retail', 'total_kobo' => $qty * $price,
        'lines' => [['item_id' => $itemId, 'name' => 'Lost item as printed', 'colour' => 'Black', 'qty' => $qty, 'unit_price_kobo' => $price]],
        'payments' => [$method === 'cash' ? ['method' => 'cash', 'amount_kobo' => $qty * $price, 'tendered_kobo' => $qty * $price + 500] : ['method' => $method, 'amount_kobo' => $qty * $price, 'reference' => 'GTB 4411']],
    ];
}

it('makes a restored counter report its journal before it sells, then numbers from its new epoch', function () {
    ($this->sell)($this->t1);
    $this->artisan('shop:after-restore')->assertSuccessful();
    expect(DB::table('restores')->count())->toBe(1);
    expect(app(Journal::class)->needed($this->t1))->toBeTrue();

    // Finish says so in a way the counter acts on: it reports and finishes the same sale again.
    $token = (string) $this->withCookie(Enrolment::COOKIE, $this->counterDevice)->postJson('/api/identity/pin', ['user_id' => $this->cashier->id, 'pin' => '1357'])->json('token');
    $as = $this->withCookie(Enrolment::COOKIE, $this->counterDevice)->withHeader(ActingToken::HEADER, $token);
    $sale = Counter::cashSale([$this->a->id => [1, 5000]]);
    $as->postJson('/api/counter/sales', $sale)->assertStatus(409)->assertJsonPath('journal_needed', true);
    expect(DB::table('sales')->count())->toBe(1);

    // The journal holds only the sale the restored data has: nothing is missing, and the report is kept.
    $this->withCookie(Enrolment::COOKIE, $this->counterDevice)->postJson('/api/counter/journal', ['highest' => ['1' => 1]])
        ->assertOk()->assertJsonPath('missing', 0)->assertJsonPath('epoch', 2)->assertJsonPath('raised_to', null);
    $as->postJson('/api/counter/sales', $sale)->assertOk()->assertJsonPath('sale.receipt_number', 'T01-E2-001001');

    // The other counter still waits for its own report; one counter never holds up another.
    expect(app(Journal::class)->needed($this->t2))->toBeTrue();
    expect(fn () => ($this->sell)($this->t2))->toThrow(JournalNeeded::class);
});

it('takes in the sales a journal holds and the restored data lacks, in two steps', function () {
    ($this->sell)($this->t1);
    ($this->sell)($this->t1);
    $this->artisan('shop:after-restore')->assertSuccessful();
    $journal = [
        lostEntry('T01-E1-000001', $this->a->id, 5000, $this->cashier->id),
        lostEntry('T01-E1-000002', $this->a->id, 5000, $this->cashier->id),
        lostEntry('T01-E1-000003', $this->a->id, 7500, $this->cashier->id, qty: 2),
        lostEntry('T01-E1-000004', $this->b->id, 9000, $this->cashier->id, method: 'transfer'),
        // Another counter's receipt in this browser's journal is not this counter's to report.
        lostEntry('T02-E1-000009', $this->a->id, 5000, $this->cashier->id),
    ];

    // Step one: the highest number per epoch; the server says what it lacks.
    $this->withCookie(Enrolment::COOKIE, $this->counterDevice)->postJson('/api/counter/journal', ['highest' => ['1' => 4]])
        ->assertOk()->assertExactJson(['send' => [['epoch' => 1, 'after' => 2]]]);
    expect(DB::table('journal_reports')->count())->toBe(0);

    // Step two: the entries after that number.
    $this->withCookie(Enrolment::COOKIE, $this->counterDevice)->postJson('/api/counter/journal', ['highest' => ['1' => 4], 'entries' => $journal])
        ->assertOk()->assertJsonPath('missing', 2)->assertJsonPath('epoch', 2)->assertJsonPath('raised_to', null);
    expect(DB::table('lost_sales')->orderBy('receipt_no')->pluck('receipt_number')->all())->toBe(['T01-E1-000003', 'T01-E1-000004']);
    expect(DB::table('audit_log')->where('action', 'recovery.journal_reported')->count())->toBe(1);

    // Reporting the same journal again stores nothing twice.
    app(Journal::class)->report($this->t1, ['highest' => ['1' => 4], 'entries' => $journal]);
    expect(DB::table('lost_sales')->count())->toBe(2);
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('raises the epoch past a journal that knows a newer one than the backup disk did', function () {
    ($this->sell)($this->t1);
    $this->artisan('shop:after-restore')->assertSuccessful(); // E2: the epoch file on this disk was older
    // The counter sold in an E2 from an earlier restore this copy never saw.
    $journal = [lostEntry('T01-E2-001001', $this->a->id, 5000, $this->cashier->id), lostEntry('T01-E2-001002', $this->a->id, 5000, $this->cashier->id)];

    $answer = app(Journal::class)->report($this->t1, ['highest' => ['2' => 1002], 'entries' => $journal]);
    expect($answer)->toMatchArray(['missing' => 2, 'epoch' => 3, 'raised_to' => 3]);
    expect(DB::table('epochs')->where('terminal_id', $this->t1->id)->orderByDesc('epoch')->first())->epoch->toBe(3)->reason->toBe('restore');
    expect(app(Journal::class)->needed($this->t1))->toBeFalse();
    expect(($this->sell)($this->t1)['receipt_number'])->toBe('T01-E3-001001');
    expect(DB::table('audit_log')->where('action', 'recovery.epoch_raised')->count())->toBe(1);
});

it('starts a new epoch when the journal is ahead of a server nobody marked as restored', function () {
    ($this->sell)($this->t1);
    ($this->sell)($this->t1);
    // A copy put back by hand, without shop:after-restore: the counter's journal goes on to E1-000004.
    $answer = app(Journal::class)->report($this->t1, ['highest' => ['1' => 4], 'entries' => [
        lostEntry('T01-E1-000003', $this->a->id, 5000, $this->cashier->id), lostEntry('T01-E1-000004', $this->a->id, 5000, $this->cashier->id),
    ]]);
    expect($answer)->toMatchArray(['missing' => 2, 'epoch' => 2, 'raised_to' => 2]);
    expect(($this->sell)($this->t1)['receipt_number'])->toBe('T01-E2-001001');

    // No restore was recorded, yet Lost sales shows them, and stays in the menu once both are re-entered.
    ($this->back)()->getJson('/api/back-office/badges')->assertJsonPath('recovery', 2)->assertJsonPath('recovery_shown', true);
    foreach (DB::table('lost_sales')->pluck('id') as $lost) {
        ($this->back)()->postJson("/api/back-office/recovery/lost/{$lost}/re-enter")->assertCreated();
    }
    ($this->back)()->getJson('/api/back-office/badges')->assertJsonPath('recovery', 0)->assertJsonPath('recovery_shown', true);

    // A reinstalled server with no epochs at all starts above the journal too (its sales here were pruned).
    $t3 = Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'T03', 'name' => 'Counter 3']);
    expect(app(Journal::class)->report($t3, ['highest' => ['3' => 1010]]))->toBe(['send' => [['epoch' => 3, 'after' => 0]]]);
    expect(app(Journal::class)->report($t3, ['highest' => ['3' => 1010], 'entries' => []]))->toMatchArray(['epoch' => 4, 'raised_to' => 4]);
    expect(DB::table('epochs')->where('terminal_id', $t3->id)->value('reason'))->toBe('reinstall');
});

it('records nothing when the journal and the server agree and nothing was restored', function () {
    ($this->sell)($this->t1);
    expect(app(Journal::class)->report($this->t1, ['highest' => ['1' => 1]]))->toBe(['ok' => true, 'epoch' => 1, 'missing' => 0, 'raised_to' => null]);
    expect(app(Journal::class)->report($this->t2, ['highest' => []]))->toMatchArray(['ok' => true, 'missing' => 0]);
    expect(DB::table('journal_reports')->count())->toBe(0);
    expect(DB::table('epochs')->where('terminal_id', $this->t1->id)->max('epoch'))->toBe(1);

    // A malformed journal is refused in plain words.
    $this->withCookie(Enrolment::COOKIE, $this->counterDevice)->postJson('/api/counter/journal', ['highest' => ['1' => 1], 'entries' => [['receipt_number' => 'nonsense']]])
        ->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'The counter journal could not be read'));
});

it('lists the lost sales for a manager, who re-enters each under its original receipt number', function () {
    ($this->sell)($this->t1);
    ($this->sell)($this->t1);
    $this->artisan('shop:after-restore')->assertSuccessful();
    app(Journal::class)->report($this->t1, ['highest' => ['1' => 4], 'entries' => [
        lostEntry('T01-E1-000003', $this->a->id, 7500, $this->cashier->id, qty: 2),
        lostEntry('T01-E1-000004', $this->b->id, 9000, $this->cashier->id, method: 'transfer'),
    ]]);
    $before = DailySummary::sales(now('Africa/Lagos')->toDateString());

    $view = ($this->back)()->getJson('/api/back-office/recovery')->assertOk();
    $view->assertJsonPath('waiting', 2)->assertJsonPath('restore.reason', 'restore')->assertJsonPath('lost.0.receipt_number', 'T01-E1-000003');
    $view->assertJsonPath('counters.0.code', 'T01')->assertJsonPath('counters.0.missing', 2)->assertJsonPath('counters.1.reported_at', null);
    ($this->back)()->getJson('/api/back-office/badges')->assertJsonPath('recovery', 2)->assertJsonPath('recovery_shown', true);
    ($this->back)()->getJson('/api/back-office/dashboard')->assertJsonPath('alerts.0.kind', 'lost_sales')->assertJsonPath('alerts.0.title', '2 sales to re-enter after the restore');
    $lost = (string) $view->json('lost.0.id');

    $done = ($this->back)()->postJson("/api/back-office/recovery/lost/{$lost}/re-enter")->assertCreated()->assertJsonPath('receipt_number', 'T01-E1-000003');
    $sale = DB::table('sales')->where('id', $done->json('sale_id'))->first();
    expect($sale)->kind->toBe('back_entry')->epoch->toBe(1)->receipt_no->toBe(3)->receipt_number->toBe('T01-E1-000003')
        ->total_kobo->toBe(15000)->cashier_id->toBe($this->cashier->id)->terminal_id->toBe($this->t1->id);
    expect(DB::table('sale_lines')->where('sale_id', $sale->id)->first())->name_snapshot->toBe('Lost item as printed')->colour_note->toBe('Black')->qty->toBe(2);
    expect(DB::table('payments')->where('sale_id', $sale->id)->first())->method->toBe('cash')->change_kobo->toBe(500);
    // It prints nothing: the customer already holds the receipt.
    expect(DB::table('print_jobs')->where('sale_id', $sale->id)->exists())->toBeFalse();
    expect(DB::table('back_entries')->where('sale_id', $sale->id)->value('entered_by'))->toBe($this->manager->id);
    expect(DB::table('audit_log')->where('action', 'sale.back_entered')->count())->toBe(1);

    // Twice is once; and it now counts in the day's sales, flagged as back-dated.
    ($this->back)()->postJson("/api/back-office/recovery/lost/{$lost}/re-enter")->assertOk()->assertJsonPath('already', true);
    expect(DB::table('sales')->where('kind', 'back_entry')->count())->toBe(1);
    $after = DailySummary::sales(now('Africa/Lagos')->toDateString());
    expect($after['count'])->toBe($before['count'] + 1)->and($after['total_kobo'])->toBe($before['total_kobo'] + 15000);
    $flags = app(Flags::class)->list(Period::named('last7', 'Africa/Lagos'), false, ['back_entry']);
    expect($flags)->toHaveCount(1)->and($flags[0]['receipt_number'])->toBe('T01-E1-000003')->and($flags[0]['approver'])->toBe('Kemi');

    // The transfer sale too; the cashier and the storekeeper may not.
    $other = (string) collect($view->json('lost'))->firstWhere('receipt_number', 'T01-E1-000004')['id'];
    $token = (string) $this->withCookie(Enrolment::COOKIE, $this->office)->postJson('/api/identity/pin', ['user_id' => $this->cashier->id, 'pin' => '1357'])->json('token');
    $this->withCookie(Enrolment::COOKIE, $this->office)->withHeader(ActingToken::HEADER, $token)->postJson("/api/back-office/recovery/lost/{$other}/re-enter")->assertForbidden();
    ($this->back)('Halima', '135790')->postJson("/api/back-office/recovery/lost/{$other}/re-enter")->assertCreated();
    $transfer = DB::table('payments')->join('sales', 'sales.id', '=', 'payments.sale_id')->where('sales.receipt_number', 'T01-E1-000004')->first(['payments.*']);
    expect($transfer)->method->toBe('transfer')->reference->toBe('GTB 4411')->confirmed_by->toBe($this->cashier->id);
    ($this->back)()->getJson('/api/back-office/recovery')->assertJsonPath('waiting', 0)->assertJsonPath('lost.1.entered.by', 'Halima');
    expect(collect(($this->back)()->getJson('/api/back-office/dashboard')->json('alerts'))->where('kind', 'lost_sales'))->toBeEmpty();

    // The receipt numbers on epoch 1 now run on with no gap, and the rehearsal check agrees.
    $this->artisan('shop:rehearsal-check')->expectsOutputToContain('T01 epoch 1: 4 receipts, numbers 1 to 4 with no gap')->assertSuccessful();
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('asks for the item when the restored data lacks it, and keeps the printed name', function () {
    ($this->sell)($this->t1);
    $this->artisan('shop:after-restore')->assertSuccessful();
    $gone = strtolower((string) Str::ulid()); // an item made at the counter after the last backup
    app(Journal::class)->report($this->t1, ['highest' => ['1' => 2], 'entries' => [lostEntry('T01-E1-000002', $gone, 6000, $this->cashier->id)]]);
    $view = ($this->back)()->getJson('/api/back-office/recovery')->assertJsonPath('lost.0.lines.0.item_found', false);
    $lost = (string) $view->json('lost.0.id');

    ($this->back)()->postJson("/api/back-office/recovery/lost/{$lost}/re-enter")->assertStatus(422)->assertJsonPath('lines', [1]);
    ($this->back)()->postJson("/api/back-office/recovery/lost/{$lost}/re-enter", ['items' => ['1' => $this->b->id]])->assertCreated();
    $line = DB::table('sale_lines')->join('sales', 'sales.id', '=', 'sale_lines.sale_id')->where('sales.receipt_number', 'T01-E1-000002')->first(['sale_lines.*']);
    expect($line)->item_id->toBe($this->b->id)->name_snapshot->toBe('Lost item as printed');
});

it('refuses to re-enter a sale the records already hold', function () {
    $first = ($this->sell)($this->t1);
    $this->artisan('shop:after-restore')->assertSuccessful();
    app(Journal::class)->report($this->t1, ['highest' => ['1' => 2], 'entries' => [lostEntry('T01-E1-000002', $this->a->id, 5000, $this->cashier->id)]]);
    $lost = (string) DB::table('lost_sales')->value('id');
    // The sale turns up after all (a later copy held it): the copy of its row arrives as it was.
    $row = (array) DB::table('sales')->where('id', $first['id'])->first();
    DB::table('sales')->insert([...$row, 'id' => strtolower((string) Str::ulid()), 'receipt_no' => 2, 'receipt_number' => 'T01-E1-000002', 'idempotency_key' => 'turned-up-'.Str::random(8)]);

    ($this->back)()->postJson("/api/back-office/recovery/lost/{$lost}/re-enter")->assertStatus(422)
        ->assertJsonPath('message', 'T01-E1-000002 is already in the records, so there is nothing to re-enter.');
    expect(DB::table('back_entries')->count())->toBe(0);
});

it('tells every counter to start its catalogue again after a restore', function () {
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    Catalogue::anyItem('before the restore');
    $seq = Feed::seqNow();
    $before = $this->withCookie(Enrolment::COOKIE, $this->counterDevice)->getJson("/api/counter/catalogue/changes?after={$seq}")
        ->assertOk()->assertJsonPath('reset', false)->assertJsonPath('generation', 'install');

    // A counter past the end of the change log (it saw changes the restore took away) starts again.
    $this->withCookie(Enrolment::COOKIE, $this->counterDevice)->getJson('/api/counter/catalogue/changes?after='.($seq + 50))->assertJsonPath('reset', true);

    $this->artisan('shop:after-restore')->assertSuccessful();
    $generation = Feed::generation();
    expect($generation)->not->toBe('install');
    $this->withCookie(Enrolment::COOKIE, $this->counterDevice)->getJson("/api/counter/catalogue/changes?after={$seq}&generation=install")
        ->assertJsonPath('reset', true)->assertJsonPath('generation', $generation);
    $this->withCookie(Enrolment::COOKIE, $this->counterDevice)->getJson("/api/counter/catalogue/changes?after={$seq}&generation={$generation}")->assertJsonPath('reset', false);
    expect($this->withCookie(Enrolment::COOKIE, $this->counterDevice)->getJson('/api/counter/catalogue/snapshot')->json('generation'))->toBe($generation);
    expect($before->json('seq'))->toBe($seq);
});
