<?php

declare(strict_types=1);

use App\Audit\Verifier;
use App\Identity\Role;
use App\Sales\Finish;
use App\Sales\Journal;
use App\Sales\SaleRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness;
use Tests\Concurrency\Scenarios\FinishSales;
use Tests\Concurrency\Scenarios\ReEnterLost;
use Tests\Concurrency\Scenarios\ReportJournal;
use Tests\Support\Catalogue;
use Tests\Support\Counter;
use Tests\Support\People;
use Tests\Support\Shop;

/*
 * Restore recovery with real processes and committed transactions (spec section 14, check B9) [F1, F6,
 * S14, D44]: a counter's journal reported twice at once, managers re-entering the same lost sale at once,
 * and a sale racing its counter's journal report, never reuse a receipt number or store anything twice.
 */

beforeEach(function () {
    Harness::freshDatabase();
    @unlink(config()->string('audit.spool'));
    config(['backups.status_dir' => null]);
    $shop = Shop::create(terminals: 2);
    [$this->t1, $this->t2] = $shop['terminals'];
    $this->cashier = People::add('Amina', Role::Cashier, '1357');
    $this->manager = People::add('Kemi', Role::Manager, '246810');
    $this->item = Catalogue::anyItem('a')->id;
    foreach ([1, 2] as $n) {
        app(Finish::class)->finish($this->t1, $this->cashier, SaleRequest::fromArray(Counter::cashSale([$this->item => [1, 5000]])));
    }
    // The server is restored; the counter's journal holds two sales the copy lacks, and an earlier E2
    // the backup disk never heard of.
    Artisan::call('shop:after-restore');
    $entry = fn (string $receipt) => [
        'receipt_number' => $receipt, 'at' => now()->subMinutes(3)->toIso8601String(), 'cashier_id' => $this->cashier->id, 'customer_type' => 'retail',
        'total_kobo' => 5000, 'lines' => [['item_id' => $this->item, 'name' => 'Item a', 'qty' => 1, 'unit_price_kobo' => 5000]],
        'payments' => [['method' => 'cash', 'amount_kobo' => 5000]],
    ];
    $this->report = ['highest' => ['1' => 4, '2' => 1001], 'entries' => [$entry('T01-E1-000003'), $entry('T01-E1-000004'), $entry('T01-E2-001001')]];
});

it('stores each lost sale once and raises the epoch once when the journal is reported four times at once', function () {
    $results = Harness::run(array_fill(0, 4, [ReportJournal::class, ['terminal' => $this->t1->id, 'report' => $this->report]]));

    expect(DB::table('lost_sales')->orderBy('epoch')->orderBy('receipt_no')->pluck('receipt_number')->all())->toBe(['T01-E1-000003', 'T01-E1-000004', 'T01-E2-001001']);
    expect(DB::table('epochs')->where('terminal_id', $this->t1->id)->where('epoch', 3)->count())->toBe(1);
    expect(DB::table('epochs')->where('terminal_id', $this->t1->id)->max('epoch'))->toBe(3);
    expect(collect($results)->where('raised_to', 3))->toHaveCount(1);
    expect(collect($results)->pluck('epoch')->unique()->all())->toBe([3]);
    expect(DB::table('journal_reports')->count())->toBe(1);
    expect(app(Journal::class)->needed($this->t1))->toBeFalse();
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('makes one back entry when three managers re-enter the same lost sale at once', function () {
    app(Journal::class)->report($this->t1, $this->report);
    $lost = (string) DB::table('lost_sales')->where('receipt_number', 'T01-E1-000003')->value('id');

    $results = Harness::run(array_fill(0, 3, [ReEnterLost::class, ['lost' => $lost, 'actor' => $this->manager->id]]));

    expect(DB::table('sales')->where('receipt_number', 'T01-E1-000003')->count())->toBe(1);
    expect(DB::table('back_entries')->count())->toBe(1);
    expect(collect($results)->where('already', false))->toHaveCount(1);
    expect(collect($results)->pluck('sale_id')->unique())->toHaveCount(1);
    expect(DB::table('audit_log')->where('action', 'sale.back_entered')->count())->toBe(1);
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('never sells under a number the journal holds when a sale races its counter\'s report', function () {
    foreach (range(1, 3) as $round) {
        $results = Harness::run([
            [FinishSales::class, ['terminal' => $this->t1->id, 'cashier' => $this->cashier->id, 'items' => [$this->item], 'count' => 3]],
            [ReportJournal::class, ['terminal' => $this->t1->id, 'report' => $this->report]],
        ]);
        // Before the report a sale is refused; after it, it numbers from the raised epoch.
        foreach ($results[0]['done'] as $sale) {
            expect($sale['receipt'])->toStartWith('T01-E3-');
        }
    }
    $numbers = DB::table('sales')->where('terminal_id', $this->t1->id)->where('epoch', 3)->orderBy('receipt_no')->pluck('receipt_no')->all();
    expect($numbers)->toBe($numbers === [] ? [] : range(1001, 1000 + count($numbers)));
    expect(DB::table('sales')->where('terminal_id', $this->t1->id)->where('epoch', 2)->count())->toBe(0);
    expect(app(Verifier::class)->problems())->toBe([]);
});
