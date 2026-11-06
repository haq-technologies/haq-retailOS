<?php

declare(strict_types=1);

use App\Catalogue\Learning;
use App\Catalogue\Models\Brand;
use App\Catalogue\Models\Category;
use App\Catalogue\Models\Item;
use App\Catalogue\Models\PicklistValue;
use App\Catalogue\StarterCatalogue;
use App\Counter\Feed;
use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Sales\Finish;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Tests\Support\Catalogue;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * The counter's API (spec sections 4, 6 and 9) [R5, F13, F20, F25, C2]: the catalogue feed, barcodes and
 * aliases learned from selling, new items with their guardrails, and opening a shift.
 */

beforeEach(function () {
    // Feature tests never commit, so the feed's commit-time trigger fires at each statement instead.
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $this->counter = Counter::ready(items: 2);
    app(StarterCatalogue::class)->load(config()->string('catalogue.seed'));
    [$this->t1] = $this->counter['terminals'];
    $this->cashier = $this->counter['cashier'];
    [$this->a, $this->b] = $this->counter['items'];
    $this->device = People::enrol($this->t1);
    $this->as = function (string $name = 'Amina', string $pin = '1357') {
        $user = User::query()->where('name', $name)->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $this->device)->withHeader(ActingToken::HEADER, $token);
    };
});

function sellLine(string $itemId, array $line = [], ?User $cashier = null): array
{
    $data = Counter::cashSale([$itemId => [1, (int) ($line['unit_price_kobo'] ?? 5000)]]);
    $data['lines'][0] = [...$data['lines'][0], ...$line];

    return app(Finish::class)->finish(test()->t1, $cashier ?? test()->cashier, Counter::request($data));
}

it('pages a snapshot of the catalogue that holds every row once, then polls the changes after it', function () {
    $as = ($this->as)();
    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $page = app(Feed::class)->snapshot($cursor, 150);
        foreach ($page['rows'] as $table => $rows) {
            foreach ($rows as $row) {
                $seen[$table][] = $row['id'];
            }
        }
        $cursor = $page['next'];
        $pages++;
    } while ($cursor !== null && $pages < 50);

    expect($pages)->toBeGreaterThan(2);
    expect(count($seen['picklist_values']))->toBe(DB::table('picklist_values')->count())->toBe(count(array_unique($seen['picklist_values'])));
    expect(count($seen['items']))->toBe(DB::table('items')->count());
    $seq = $page['seq'];
    expect($seq)->toBe(Feed::seqNow());

    // Nothing has changed since, so the next poll is empty.
    $as->getJson("/api/counter/catalogue/changes?after={$seq}")->assertOk()->assertJsonPath('seq', $seq)->assertJsonPath('rows', []);

    // A renamed brand and a sale both arrive; the sale brings the item's new price pre-fill. No cost leaves.
    Brand::query()->where('seed_id', 'brand.oraimo')->firstOrFail()->update(['aliases' => ['oraimo', 'oriamo']]);
    sellLine($this->a->id, ['unit_price_kobo' => 7500]);
    $changes = $as->getJson("/api/counter/catalogue/changes?after={$seq}")->assertOk()->json();
    expect($changes['seq'])->toBeGreaterThan($seq);
    expect(collect($changes['rows']['brands'])->pluck('name'))->toContain('Oraimo');
    expect(collect($changes['prices'])->firstWhere('item_id', $this->a->id)['retail']['prefill'])->toBe(7500);
    expect(json_encode($changes))->not->toContain('cost');
});

it('reports a row that was deleted as removed', function () {
    $seq = Feed::seqNow();
    app(Learning::class)->recordSearch('earpiece', $this->a->id);
    $alias = DB::table('aliases')->where('term', 'earpiece')->value('id');
    DB::table('aliases')->where('id', $alias)->delete();

    $changes = app(Feed::class)->changes($seq);
    expect($changes['removed']['aliases'] ?? [])->toBe([$alias]);
});

it('learns a barcode from the cashier\'s match, confirms it after two further sales, and flags a conflict', function () {
    $code = '6901234567892';
    ($this->as)()->getJson("/api/counter/barcodes/{$code}")->assertOk()->assertJsonPath('junk', false)->assertJsonPath('candidates', []);
    ($this->as)()->postJson('/api/counter/barcodes', ['code' => $code, 'item_id' => $this->a->id])->assertOk()->assertJsonPath('learned', true);

    // The link works at once, unconfirmed; the sale that taught it does not count.
    ($this->as)()->getJson("/api/counter/barcodes/{$code}")->assertJsonPath('candidates.0.item_id', $this->a->id)->assertJsonPath('candidates.0.status', 'unconfirmed');
    sellLine($this->a->id, ['added_by' => 'learn', 'barcode' => $code]);
    sellLine($this->a->id, ['added_by' => 'scan', 'barcode' => $code]);
    expect(DB::table('barcodes')->where('code', $code)->value('status'))->toBe('unconfirmed');
    sellLine($this->a->id, ['added_by' => 'scan', 'barcode' => $code]);
    expect(DB::table('barcodes')->where('code', $code)->value('status'))->toBe('confirmed');

    // A different cashier picks a different item for the same code: both links are flagged.
    $bola = People::add('Bola', Role::Cashier, '2468');
    app(Learning::class)->linkBarcode($code, $this->b->id, $bola->id);
    expect(DB::table('barcodes')->where('code', $code)->where('conflicted', true)->count())->toBe(2);
    expect(app(Learning::class)->candidates($code)[0]['item_id'])->toBe($this->b->id); // most recently chosen first

    // Undo changes no sale.
    $link = DB::table('barcodes')->where('code', $code)->where('item_id', $this->b->id)->value('id');
    app(Learning::class)->undo($link, $bola->id);
    expect(app(Learning::class)->candidates($code))->toHaveCount(1);
    expect(DB::table('sales')->count())->toBe(3);
});

it('never learns a junk code', function (string $code) {
    expect(Learning::isJunk($code))->toBeTrue();
    expect(app(Learning::class)->linkBarcode($code, $this->a->id, $this->cashier->id))->toBeFalse();
    expect(DB::table('barcodes')->count())->toBe(0);
})->with(['short' => '12345', 'repeated' => '000000', 'repeated letters' => 'AAAAAAAA']);

it('stops learning a code linked to more than five items', function () {
    $items = [$this->a, $this->b];
    for ($i = 3; $i <= 6; $i++) {
        $items[] = Catalogue::anyItem("item {$i}");
    }
    foreach ($items as $item) {
        app(Learning::class)->linkBarcode('INVOICE-0001', $item->id, $this->cashier->id);
    }
    expect(app(Learning::class)->candidates('INVOICE-0001'))->toHaveCount(5);
    expect(Learning::isJunk('INVOICE-0001'))->toBeTrue();
});

it('makes a search term an alias after it led to the same item three times, and never stores short terms', function () {
    sellLine($this->a->id, ['search_term' => 'ea']);
    expect(DB::table('aliases')->count())->toBe(0);

    sellLine($this->a->id, ['search_term' => 'Earpiece']);
    sellLine($this->a->id, ['search_term' => 'earpiece ']);
    expect(DB::table('aliases')->where('term', 'earpiece')->value('status'))->toBe('candidate');
    sellLine($this->a->id, ['search_term' => 'EARPIECE']);
    expect(DB::table('aliases')->where('term', 'earpiece')->first())->status->toBe('active')->hits->toBe(3);
});

it('creates an item at the counter: category first, similar items offered, a typed brand pending', function () {
    $earbuds = Category::query()->where('code', 'earbuds')->firstOrFail();
    $as = ($this->as)();

    $as->postJson('/api/counter/items', ['facets' => ['model' => 'Buds 5']])->assertStatus(422)->assertJsonPath('message', 'Choose the item\'s category first.');

    $created = $as->postJson('/api/counter/items', ['category_id' => $earbuds->id, 'brand_text' => 'Newmax', 'facets' => ['model' => 'Buds 5']])->assertStatus(201)->json('item');
    expect($created['display_name'])->toBe('Newmax Buds 5 Earbuds')->and($created['status'])->toBe('pending_review');
    expect(Brand::query()->where('name', 'Newmax')->value('status'))->toBe('pending');

    // "newmax" is the same brand; a near-identical item is offered first, then made on "create anyway".
    $similar = $as->postJson('/api/counter/items', ['category_id' => $earbuds->id, 'brand_text' => 'newmax', 'facets' => ['model' => 'Buds 5 Pro']])->assertStatus(422);
    expect($similar->json('similar.0.name'))->toBe('Newmax Buds 5 Earbuds');
    $as->postJson('/api/counter/items', ['category_id' => $earbuds->id, 'brand_text' => 'newmax', 'facets' => ['model' => 'Buds 5 Pro'], 'confirm_new' => true])->assertStatus(201)
        ->assertJsonPath('item.display_name', 'Newmax Buds 5 Pro Earbuds');
    expect(Brand::query()->whereRaw('lower(name) = ?', ['newmax'])->count())->toBe(1);
});

it('takes a typed phone model as pending, where the category allows it', function () {
    $cases = Category::query()->where('code', 'cases')->firstOrFail();
    $item = ($this->as)()->postJson('/api/counter/items', ['category_id' => $cases->id, 'phone_model_text' => 'Tecno Spark 40', 'confirm_new' => true])->assertStatus(201)->json('item');
    expect($item['display_name'])->toContain('Tecno Spark 40')->toContain('Pouch');
    expect(PicklistValue::query()->where('list', 'phone_models')->where('name', 'Tecno Spark 40')->value('status'))->toBe('pending');

    $earbuds = Category::query()->where('code', 'earbuds')->firstOrFail();
    ($this->as)()->postJson('/api/counter/items', ['category_id' => $earbuds->id, 'phone_model_text' => 'Tecno Spark 40'])->assertStatus(422)->assertJsonPath('message', 'Pick the phone model from the list.');
});

it('prints a "Not in the list" item with the cashier\'s word, never "Others"', function () {
    $as = ($this->as)();
    $as->postJson('/api/counter/items', ['not_in_list_word' => 'Others'])->assertStatus(422);
    $as->postJson('/api/counter/items', ['not_in_list_word' => 'ab'])->assertStatus(422);

    $item = $as->postJson('/api/counter/items', ['not_in_list_word' => 'Solar lamp', 'free_text' => 'small', 'confirm_new' => true])->assertStatus(201)->json('item');
    expect($item['display_name'])->toBe('Solar lamp small')->and($item['status'])->toBe('pending_category');
});

it('in Request-only mode, sells a typed line under its category with at least two words', function () {
    app(Settings::class)->set('catalogue.cashier_item_creation', 'request_only', 'console');
    $earbuds = Category::query()->where('code', 'earbuds')->firstOrFail();
    $as = ($this->as)();

    $as->postJson('/api/counter/items', ['category_id' => $earbuds->id, 'free_text' => 'misc stuff'])->assertStatus(422);
    $as->postJson('/api/counter/items', ['category_id' => $earbuds->id, 'free_text' => 'Blue'])->assertStatus(422);
    $item = $as->postJson('/api/counter/items', ['category_id' => $earbuds->id, 'brand_text' => 'Ignored', 'free_text' => 'neckband sport', 'confirm_new' => true])->assertStatus(201)->json('item');
    expect($item['display_name'])->toBe('Earbuds neckband sport');
    expect(Brand::query()->where('name', 'Ignored')->exists())->toBeFalse();
});

it('opens the drawer\'s shift with the counted float, once', function () {
    $as = ($this->as)();
    $as->getJson('/api/counter/shift')->assertOk()->assertJsonPath('shift', null);
    $as->postJson('/api/counter/shift', ['opening_float_kobo' => 1_000_000])->assertStatus(201)->assertJsonPath('shift.opening_float_kobo', 1_000_000)->assertJsonPath('shift.unattributed', false);
    $as->postJson('/api/counter/shift', ['opening_float_kobo' => 500_000])->assertStatus(409);

    $sale = sellLine($this->a->id);
    expect(DB::table('sales')->where('id', $sale['id'])->value('shift_id'))->toBe(DB::table('shifts')->value('id'));
});

it('gives prices for many items at once, for the counter\'s cache', function () {
    sellLine($this->a->id, ['unit_price_kobo' => 6000]);
    $prices = ($this->as)()->postJson('/api/counter/prices', ['item_ids' => [$this->a->id, $this->b->id]])->assertOk()->json('prices');
    expect($prices)->toHaveCount(2);
    expect(collect($prices)->firstWhere('item_id', $this->a->id)['retail']['prefill'])->toBe(6000);
});
