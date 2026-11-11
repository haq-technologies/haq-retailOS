<?php

declare(strict_types=1);

use App\Audit\Verifier;
use App\Identity\Role;
use App\Sales\Finish;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness;
use Tests\Concurrency\Scenarios\ReturnLine;
use Tests\Concurrency\Scenarios\VoidSale;
use Tests\Support\Catalogue;
use Tests\Support\Counter;
use Tests\Support\People;
use Tests\Support\Shop;

/*
 * No double return (spec section 9; check F3 in section 14, the original check D6) [F7; D68]: two counters
 * returning the same line at the same moment cannot both succeed, and a sale cannot be voided on one counter
 * while it is returned on another (D73, QA finding P14). Real processes, committed transactions.
 */

beforeEach(function () {
    Harness::freshDatabase();
    $shop = Shop::create(terminals: 2);
    [$this->t1, $this->t2] = $shop['terminals'];
    $this->cashier = People::add('Amina', Role::Cashier, '1357');
    $this->manager = People::add('Kemi', Role::Manager, '246810');
});

it('lets only one of two counters return the same line at once, round after round (check F3)', function () {
    foreach (range(1, 3) as $round) {
        $sale = app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(Counter::cashSale([Catalogue::anyItem("item {$round}")->id => [2, 150_000]])));
        $line = (string) DB::table('sale_lines')->where('sale_id', $sale['id'])->value('id');
        $args = fn ($terminal) => ['terminal' => $terminal->id, 'manager' => $this->manager->id, 'receipt' => $sale['receipt_number'], 'line' => $line, 'qty' => 2];

        $results = Harness::run([[ReturnLine::class, $args($this->t1)], [ReturnLine::class, $args($this->t2)]]);

        expect(collect($results)->whereNotNull('returned'))->toHaveCount(1);
        expect(collect($results)->whereNotNull('refused')->first()['refused'])->toContain('has already been returned');
        expect((int) DB::table('returns')->where('original_line_id', $line)->sum('qty'))->toBe(2);
    }
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('never both voids a sale on one counter and returns it on another at the same moment (D73, QA finding P14)', function () {
    foreach (range(1, 5) as $round) {
        $sale = app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(Counter::cashSale([Catalogue::anyItem("void or return {$round}")->id => [2, 150_000]])));
        $line = (string) DB::table('sale_lines')->where('sale_id', $sale['id'])->value('id');

        $results = Harness::run([
            [VoidSale::class, ['terminal' => $this->t1->id, 'manager' => $this->manager->id, 'sale' => $sale['id']]],
            [ReturnLine::class, ['terminal' => $this->t2->id, 'manager' => $this->manager->id, 'receipt' => $sale['receipt_number'], 'line' => $line, 'qty' => 2]],
        ]);

        // One of the two goes through, the other is refused in plain words, and the sale is refunded once.
        expect(collect($results)->filter(fn (array $r) => isset($r['voided']) || isset($r['returned'])))->toHaveCount(1);
        expect(collect($results)->whereNotNull('refused')->first()['refused'])->toMatch('/(was voided|has been returned)/');
        expect(-(int) DB::table('sales')->where('reverses_sale_id', $sale['id'])->sum('total_kobo'))->toBe(300_000);
    }
    expect(app(Verifier::class)->problems())->toBe([]);
});
