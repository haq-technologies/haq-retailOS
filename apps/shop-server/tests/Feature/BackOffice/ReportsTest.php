<?php

declare(strict_types=1);

use App\Catalogue\Learning;
use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Printing\PrinterConnection;
use App\Sales\Finish;
use App\Sales\Pricing;
use Illuminate\Support\Facades\DB;
use Tests\Support\Catalogue;
use Tests\Support\Counter;
use Tests\Support\People;
use Tests\Support\RecordingPrinter;

/*
 * Prices and reports (spec sections 7 and 12; wireframes FirstSalePrices, CatalogueHealth and DailySummary)
 * [S4, F10, F14, D18]: first-sale prices with one-tap set and undo, catalogue health, the daily summary on
 * screen and printed, and the CSV exports of items and sales.
 */

beforeEach(function () {
    $this->printer = new RecordingPrinter;
    $this->app->instance(PrinterConnection::class, $this->printer);
    $this->counter = Counter::ready(items: 2);
    [$this->t1] = $this->counter['terminals'];
    [$this->a, $this->b] = $this->counter['items'];
    $this->cashier = $this->counter['cashier'];
    $this->t1->forceFill(['printer_host' => '192.168.1.50', 'printer_port' => 9100])->save();
    $this->owner = People::add('Halima', Role::Owner, '135790');
    $this->manager = People::add('Kemi', Role::Manager, '246810');
    People::add('Sola', Role::Storekeeper, '2468');
    $this->device = People::enrol(Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'D01', 'name' => 'Owner laptop', 'kind' => 'back_office']));
    $this->as = function (string $name, string $pin) {
        $user = User::query()->where('name', $name)->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $this->device)->withHeader(ActingToken::HEADER, $token);
    };
    $this->owns = fn () => ($this->as)('Halima', '135790');
    $this->manages = fn () => ($this->as)('Kemi', '246810');
    $this->stores = fn () => ($this->as)('Sola', '2468');
    $this->sell = function (object $item, int $price, int $qty = 1, array $overrides = []): array {
        $sale = Counter::cashSale([$item->id => [$qty, $price]], $overrides);
        $sale['lines'][0]['confirmed_outside_range'] = true;

        return app(Finish::class)->finish($this->t1, $this->cashier, Counter::request($sale));
    };
});

it('lists first sales with the prices typed, sets one with a tap, and undoes it back to no set price', function () {
    ($this->sell)($this->a, 800_000);
    ($this->sell)($this->a, 750_000);
    ($this->sell)($this->a, 800_000);

    $first = ($this->manages)()->getJson('/api/back-office/prices/first-sales')->assertOk()->json('items');
    expect($first)->toHaveCount(1)->and($first[0])->toMatchArray(['item_id' => $this->a->id, 'customer_type' => 'retail', 'prices' => [800_000, 750_000], 'set' => null]);
    expect($first[0]['sales'])->toHaveCount(3);
    expect(($this->manages)()->getJson('/api/back-office/badges')->json('prices'))->toBe(1);

    ($this->manages)()->postJson('/api/back-office/prices', ['item_id' => $this->a->id, 'customer_type' => 'retail', 'price_kobo' => 800_000])->assertOk();
    expect(app(Pricing::class)->setPrice($this->a->id, 'retail'))->toBe(800_000);
    expect(($this->manages)()->getJson('/api/back-office/prices/first-sales')->json('items.0.set'))->toMatchArray(['price_kobo' => 800_000, 'by' => 'Kemi', 'today' => true]);
    expect(($this->manages)()->getJson('/api/back-office/badges')->json('prices'))->toBe(0);

    ($this->manages)()->postJson('/api/back-office/prices/undo', ['item_id' => $this->a->id, 'customer_type' => 'retail'])->assertOk()->assertJson(['price_kobo' => null]);
    expect(app(Pricing::class)->setPrice($this->a->id, 'retail'))->toBeNull();
    expect(app(Pricing::class)->info($this->a, 'retail'))->toMatchArray(['set_price' => null, 'prefill' => 800_000, 'prefill_source' => 'last_price']);
    ($this->manages)()->postJson('/api/back-office/prices/undo', ['item_id' => $this->a->id, 'customer_type' => 'retail'])->assertStatus(422);
    expect(DB::table('audit_log')->whereIn('action', ['price.set', 'price.undone'])->pluck('action')->all())->toBe(['price.set', 'price.undone']);

    // An item's prices show both customer types, with who set what.
    $prices = ($this->owns)()->getJson("/api/back-office/prices/items/{$this->a->id}")->assertOk()->json('prices.retail');
    expect($prices['history'][0])->toMatchArray(['price_kobo' => null, 'by' => 'Kemi'])->and($prices['history'][1]['price_kobo'])->toBe(800_000);

    ($this->stores)()->getJson('/api/back-office/prices/first-sales')->assertForbidden();
    ($this->as)('Amina', '1357')->postJson('/api/back-office/prices', ['item_id' => $this->a->id, 'customer_type' => 'retail', 'price_kobo' => 1])->assertForbidden();
});

it('shows catalogue health: revenue with a cost against the goal, what waits, and best sellers with no barcode', function () {
    ($this->sell)($this->a, 300_000, 3);
    ($this->sell)($this->b, 100_000);
    ($this->owns)()->postJson('/api/back-office/costs', ['item_ids' => [$this->b->id], 'unit_cost_kobo' => 50_000, 'basis' => 'first_null'])->assertOk();
    app(Learning::class)->linkBarcode('6941812709883', $this->b->id, $this->cashier->id);

    $health = ($this->stores)()->getJson('/api/back-office/reports/health')->assertOk()->json();
    expect($health['coverage_percent'])->toBe(10)->and($health['buckets'])->toBe(['confirmed' => 0, 'estimated' => 10, 'pending' => 90]);
    expect($health['goal'])->toMatchArray(['percent' => 80, 'items_to_goal' => 1]);
    expect($health['barcodes'])->toBe(['items_sold' => 2, 'with_barcode' => 1, 'percent' => 50]);
    expect($health['no_barcode'])->toHaveCount(1)->and($health['no_barcode'][0])->toMatchArray(['item_id' => $this->a->id, 'sold' => 3, 'search' => 1]);
    expect($health['review'])->toMatchArray(['barcodes' => 1, 'learned' => 1]);
    expect(json_encode($health))->not->toContain('kobo');
});

it('shows the day with profit for the owner only, and prints a slip with no profit or cost and the audit code', function () {
    ($this->owns)()->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 120_000, 'basis' => 'first_null'])->assertOk();
    ($this->sell)($this->a, 250_000);
    ($this->sell)($this->b, 100_000, 1, ['payments' => [['method' => 'transfer', 'amount_kobo' => 100_000, 'reference' => null, 'alert_seen' => true]]]);

    $owner = ($this->owns)()->getJson('/api/back-office/reports/summary')->assertOk()->json();
    expect($owner['sales'])->toMatchArray(['count' => 2, 'total_kobo' => 350_000])
        ->and($owner['buckets']['confirmed'])->toMatchArray(['revenue_kobo' => 250_000, 'profit_kobo' => 130_000])
        ->and($owner['flags']['no_reference'])->toBe(1)
        ->and($owner['printers'][0]['code'])->toBe('T01');
    $manager = ($this->manages)()->getJson('/api/back-office/reports/summary')->assertOk();
    expect($manager->getContent())->not->toContain('profit_kobo');

    $printed = ($this->manages)()->postJson('/api/back-office/reports/summary/print', ['terminal_id' => $this->t1->id])->assertOk()->json('audit_code');
    $slip = $this->printer->lastText();
    expect($slip)->toContain('DAILY SUMMARY')->toContain('SALES 2')->toContain('3,500')->toContain('Confirmed at sale')->toContain('No transfer reference')
        ->toContain('AUDIT CODE')->toContain(substr($printed, 0, 19))->toContain('Printed')->toContain('by Kemi');
    expect($slip)->not->toContain('1,300')->not->toContain('1,200')->not->toContain('rofit');
    $root = DB::table('audit_roots')->orderByDesc('seq')->first();
    expect($root->reason)->toBe('daily_summary')->and(str_replace(' ', '', $printed))->toBe(strtoupper(substr($root->hash, 0, 32)));
    expect(DB::table('audit_log')->where('action', 'summary.printed')->count())->toBe(1);

    $this->printer->online = false;
    ($this->manages)()->postJson('/api/back-office/reports/summary/print', ['terminal_id' => $this->t1->id])->assertStatus(503)->assertJsonPath('message', fn (string $m) => str_contains($m, 'Counter 1'));
    ($this->stores)()->getJson('/api/back-office/reports/summary')->assertForbidden();
});

it('exports items and sales as CSV, with cost columns only for the owner, and a merged item counted under the kept one', function () {
    $b2 = Catalogue::anyItem('item 3');
    ($this->owns)()->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 120_000, 'basis' => 'first_null'])->assertOk();
    ($this->sell)($this->a, 250_000);
    ($this->sell)($b2, 100_000);
    ($this->manages)()->postJson("/api/back-office/review/items/{$b2->id}/merge", ['into_id' => $this->b->id])->assertOk();

    $items = ($this->owns)()->get('/api/back-office/exports/items.csv')->assertOk()->getContent();
    expect($items)->toStartWith("\u{FEFF}\"Item code\"")->toContain('"Cost (NGN)"')->toContain('"1200.00"')->toContain('"Merged"');
    $storekeeper = ($this->stores)()->get('/api/back-office/exports/items.csv')->assertOk()->getContent();
    expect($storekeeper)->not->toContain('Cost')->not->toContain('1200.00');

    $sales = ($this->owns)()->get('/api/back-office/exports/sales.csv?period=today')->assertOk()->getContent();
    $lines = array_values(array_filter(explode("\r\n", $sales)));
    expect($lines)->toHaveCount(3)->and($lines[0])->toContain('"Line profit (NGN)"');
    expect($lines[1])->toContain('"Thing item 1"')->toContain('"2500.00"')->toContain('"1200.00"')->toContain('"At sale"')->toContain('"1300.00"');
    expect($lines[2])->toContain('"Thing item 3"')->toContain('"Thing item 2"')->toContain('"Waiting for a cost"');
    $manager = ($this->manages)()->get('/api/back-office/exports/sales.csv?period=today')->assertOk()->getContent();
    expect($manager)->not->toContain('cost')->not->toContain('1200.00')->not->toContain('1300.00');

    ($this->stores)()->get('/api/back-office/exports/sales.csv')->assertForbidden();
    ($this->as)('Amina', '1357')->get('/api/back-office/exports/items.csv')->assertForbidden();
});
