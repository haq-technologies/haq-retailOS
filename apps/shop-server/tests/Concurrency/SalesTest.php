<?php

declare(strict_types=1);

use App\Audit\Verifier;
use App\Identity\Role;
use App\Sales\Finish;
use App\Sales\SaleRequest;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness;
use Tests\Concurrency\Scenarios\CrashDuringSale;
use Tests\Concurrency\Scenarios\FinishSales;
use Tests\Support\Catalogue;
use Tests\Support\Counter;
use Tests\Support\People;
use Tests\Support\Shop;

/*
 * The integrity checks of spec section 14 B, with real processes, committed transactions and a real
 * PostgreSQL [F1, F4, F5, F6].
 */

beforeEach(function () {
    Harness::freshDatabase();
    @unlink(config()->string('audit.spool'));
    $shop = Shop::create(terminals: 2);
    [$this->t1, $this->t2] = $shop['terminals'];
    $this->cashier = People::add('Amina', Role::Cashier, '1357');
    $this->items = [Catalogue::anyItem('a')->id, Catalogue::anyItem('b')->id, Catalogue::anyItem('c')->id];
    $this->args = fn ($terminal, array $more = []) => ['terminal' => $terminal->id, 'cashier' => $this->cashier->id, 'items' => $this->items, ...$more];
});

it('creates exactly one sale when six requests with the same key race, and answers each with it', function () {
    $sale = Counter::cashSale([$this->items[0] => [2, 4500]]);

    $results = Harness::run(array_fill(0, 6, [FinishSales::class, ($this->args)($this->t1, ['sale' => $sale])]));

    expect(DB::table('sales')->count())->toBe(1);
    $ids = collect($results)->flatMap(fn ($r) => array_column($r['done'], 'id'))->unique();
    expect($ids)->toHaveCount(1);
    expect(collect($results)->flatMap(fn ($r) => array_column($r['done'], 'retried'))->filter()->count())->toBe(5);
    expect(DB::table('receipt_counters')->where('terminal_id', $this->t1->id)->value('last_no'))->toBe(1);
});

it('keeps receipt numbers gapless per terminal through a soak with forced failures, logging each failure', function () {
    $workers = [];
    foreach ([$this->t1, $this->t1, $this->t2, $this->t2] as $terminal) {
        $workers[] = [FinishSales::class, ($this->args)($terminal, ['count' => 15, 'fail_every' => 4])];
    }

    $results = Harness::run($workers);

    $refused = collect($results)->sum('refused');
    expect($refused)->toBe(12);
    foreach ([$this->t1, $this->t2] as $terminal) {
        $numbers = DB::table('sales')->where('terminal_id', $terminal->id)->orderBy('receipt_no')->pluck('receipt_no')->map(fn ($n) => (int) $n)->all();
        expect($numbers)->toBe(range(1, 24));
    }
    // Every refusal is in the audit trail, written after its transaction rolled back.
    expect(DB::table('audit_log')->where('action', 'sale.refused')->count())->toBe(12);
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('keeps both terminals\' chains valid when they finish at the same moment, under two seconds at the 95th percentile', function () {
    $results = Harness::run([
        [FinishSales::class, ($this->args)($this->t1, ['count' => 25])],
        [FinishSales::class, ($this->args)($this->t2, ['count' => 25])],
    ]);

    $durations = collect($results)->flatMap(fn ($r) => $r['durations_ms'])->sort()->values();
    $p95 = $durations[(int) ceil(0.95 * $durations->count()) - 1];
    expect($p95)->toBeLessThan(2000.0);
    expect(DB::table('sales')->count())->toBe(50);
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('leaves no half-sale and no used receipt number when the power goes mid-sale', function () {
    [$result] = Harness::run([[CrashDuringSale::class, ($this->args)($this->t1, ['at' => 'before_commit'])]], allowKilled: true);

    expect($result['killed'])->toBeTrue();
    expect(DB::table('sales')->count())->toBe(0);
    expect(DB::table('sale_lines')->count())->toBe(0);
    expect(DB::table('print_jobs')->count())->toBe(0);
    expect((int) DB::table('receipt_counters')->where('terminal_id', $this->t1->id)->value('last_no'))->toBe(0);

    // The next sale takes number 1: no gap.
    $sale = app(Finish::class)->finish($this->t1, $this->cashier, SaleRequest::fromArray(Counter::cashSale([$this->items[0] => [1, 1000]])));
    expect($sale['receipt_number'])->toBe('T01-E1-000001');
});

it('keeps the sale and its pending print when the power goes after commit, before printing', function () {
    [$result] = Harness::run([[CrashDuringSale::class, ($this->args)($this->t1, ['at' => 'after_commit'])]], allowKilled: true);

    expect($result['killed'])->toBeTrue();
    expect(DB::table('sales')->count())->toBe(1);
    expect(DB::table('sales')->value('receipt_number'))->toBe('T01-E1-000001');
    expect(DB::table('print_jobs')->value('status'))->toBe('pending');
    expect(app(Verifier::class)->problems())->toBe([]);
});
