<?php

declare(strict_types=1);

use App\Catalogue\Items;
use App\Catalogue\Learning;
use App\Catalogue\Models\Brand;
use App\Catalogue\Models\Category;
use App\Catalogue\Models\Item;
use App\Catalogue\Models\PicklistValue;
use App\Catalogue\NewItems;
use App\Catalogue\StarterCatalogue;
use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Reports\NeedsCost;
use App\Sales\Finish;
use App\Settings\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Catalogue;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * Review (spec sections 3, 6 and 14 C; wireframes ReviewNames, ReviewNewItems, ReviewBarcodes and
 * MergeItems) [F13, F18, C2, v1.3]: what cashiers added while selling. No answer changes a sale.
 */

beforeEach(function () {
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $this->counter = Counter::ready(items: 1);
    app(StarterCatalogue::class)->load(config()->string('catalogue.seed'));
    [$this->t1] = $this->counter['terminals'];
    $this->cashier = $this->counter['cashier'];
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
    $this->earbuds = Category::query()->where('code', 'earbuds')->firstOrFail();
    $this->oraimo = Brand::query()->where('seed_id', 'brand.oraimo')->firstOrFail();
});

/** A cashier creates an item at the counter, as the New item sheet does. */
function cashierItem(array $input): Item
{
    return app(NewItems::class)->create([...$input, 'confirm_new' => true], test()->cashier);
}

function reviewSell(Item $item, int $price): void
{
    app(Finish::class)->finish(test()->t1, test()->cashier, Counter::request(Counter::cashSale([$item->id => [1, $price]])));
}

it('lists a typed brand with the listed brand it looks like; a manager merges it and no sale changes', function () {
    $item = cashierItem(['category_id' => $this->earbuds->id, 'brand_text' => 'Oriamo', 'facets' => ['model' => 'FreePods 3C']]);
    reviewSell($item, 950_000);
    expect($item->display_name)->toBe('Oriamo FreePods 3C Earbuds');

    $brand = ($this->manages)()->getJson('/api/back-office/review')->assertOk()->json('brands.0');
    expect($brand)->toMatchArray(['name' => 'Oriamo', 'typed_by' => 'Amina', 'sales' => 1])->and($brand['looks_like']['name'])->toBe('Oraimo');

    ($this->stores)()->postJson("/api/back-office/review/brands/{$brand['id']}/merge", ['into_id' => $this->oraimo->id])->assertForbidden();
    ($this->manages)()->postJson("/api/back-office/review/brands/{$brand['id']}/merge", ['into_id' => $this->oraimo->id])->assertOk()->assertJson(['moved' => 1, 'merged' => 0]);

    expect($item->fresh()->display_name)->toBe('Oraimo FreePods 3C Earbuds')->and($item->fresh()->brand_id)->toBe($this->oraimo->id);
    expect(DB::table('sale_lines')->value('name_snapshot'))->toBe('Oriamo FreePods 3C Earbuds'); // the receipt keeps what it printed
    expect(Brand::query()->find($brand['id'])->status)->toBe('merged');
    expect(DB::table('audit_log')->where('action', 'catalogue.brand_merged')->count())->toBe(1);
});

it('merges an item into the one it now matches when a brand merge makes them the same, and a manager can add a new brand', function () {
    $listed = app(Items::class)->findOrCreate($this->earbuds, ['model' => 'X1'], $this->oraimo);
    $typed = cashierItem(['category_id' => $this->earbuds->id, 'brand_text' => 'Oriamo', 'facets' => ['model' => 'X1']]);
    $other = cashierItem(['category_id' => $this->earbuds->id, 'brand_text' => 'Yesido', 'facets' => ['model' => 'Y2']]);

    $oriamo = Brand::query()->where('name', 'Oriamo')->firstOrFail();
    ($this->manages)()->postJson("/api/back-office/review/brands/{$oriamo->id}/merge", ['into_id' => $this->oraimo->id])->assertOk()->assertJson(['moved' => 0, 'merged' => 1]);
    expect($typed->fresh())->status->toBe('merged')->merged_into_id->toBe($listed->id);

    $yesido = Brand::query()->where('name', 'Yesido')->firstOrFail();
    ($this->manages)()->postJson("/api/back-office/review/brands/{$yesido->id}/approve")->assertOk();
    expect($yesido->fresh()->status)->toBe('approved')->and($other->fresh()->display_name)->toBe('Yesido Y2 Earbuds');
    ($this->manages)()->postJson("/api/back-office/review/brands/{$yesido->id}/approve")->assertStatus(422);
});

it('adds a typed phone model to its list, or makes it the listed one it was meant to be', function () {
    $cases = Category::query()->where('code', 'cases')->firstOrFail();
    $first = cashierItem(['category_id' => $cases->id, 'phone_model_text' => 'Infinix Hot 60i']);
    $model = PicklistValue::query()->where('name', 'Infinix Hot 60i')->firstOrFail();
    expect($model->status)->toBe('pending');

    ($this->manages)()->postJson("/api/back-office/review/models/{$model->id}/approve")->assertOk();
    expect($model->fresh()->status)->toBe('active');

    $second = cashierItem(['category_id' => $cases->id, 'phone_model_text' => 'Infinix Hot60i']);
    $typo = PicklistValue::query()->where('name', 'Infinix Hot60i')->firstOrFail();
    $row = collect(($this->manages)()->getJson('/api/back-office/review')->json('models'))->firstWhere('id', $typo->id);
    expect($row['looks_like']['name'])->toBe('Infinix Hot 60i');

    ($this->manages)()->postJson("/api/back-office/review/models/{$typo->id}/merge", ['into_id' => $model->id])->assertOk()->assertJson(['merged' => 1]);
    expect($second->fresh())->status->toBe('merged')->merged_into_id->toBe($first->id);
    expect($typo->fresh()->status)->toBe('archived');
});

it('lets only the owner create a category for a typed product word, and moves its items there', function () {
    $item = cashierItem(['not_in_list_word' => 'Solar light']);
    reviewSell($item, 700_000);
    expect(($this->owns)()->getJson('/api/back-office/review')->json('words.0'))->toMatchArray(['word' => 'Solar light', 'sales' => 1, 'low_kobo' => 700_000]);

    ($this->manages)()->postJson('/api/back-office/review/categories', ['typed' => 'Solar light', 'word' => 'Solar Light', 'name' => 'Solar lights'])->assertForbidden();
    $made = ($this->owns)()->postJson('/api/back-office/review/categories', ['typed' => 'Solar light', 'word' => 'Solar Light', 'name' => 'Solar lights'])->assertOk()->assertJson(['moved' => 1])->json();

    $category = Category::query()->findOrFail($made['category_id']);
    expect($category)->name->toBe('Solar lights')->word->toBe('Solar Light')->sellable->toBeTrue();
    expect($item->fresh())->status->toBe('active')->category_id->toBe($category->id)->display_name->toBe('Solar Light');
    expect(($this->owns)()->getJson('/api/back-office/review')->json('words'))->toBe([]);
    ($this->owns)()->postJson('/api/back-office/review/categories', ['typed' => 'Solar light', 'word' => 'Solar Light', 'name' => 'Solar lights'])->assertStatus(422);
});

it('takes a new item off the list when it looks right, or after an edit, and refuses an edit that makes a duplicate', function () {
    $right = cashierItem(['category_id' => $this->earbuds->id, 'brand_id' => $this->oraimo->id, 'facets' => ['model' => 'P47']]);
    $wrong = cashierItem(['category_id' => $this->earbuds->id, 'brand_id' => $this->oraimo->id, 'facets' => ['model' => 'P4B']]);
    expect(collect(($this->stores)()->getJson('/api/back-office/review')->json('items'))->pluck('name')->sort()->values()->all())
        ->toBe(['Oraimo P47 Earbuds', 'Oraimo P4B Earbuds']);

    ($this->stores)()->postJson("/api/back-office/review/items/{$right->id}/approve")->assertOk();
    expect($right->fresh()->status)->toBe('active');

    $form = ($this->stores)()->getJson("/api/back-office/catalogue/categories/{$this->earbuds->id}/form")->assertOk()->json('facets');
    expect(array_column($form, 'key'))->toBe(['brand', 'model']);
    ($this->stores)()->postJson("/api/back-office/catalogue/items/{$wrong->id}", ['category_id' => $this->earbuds->id, 'brand_id' => $this->oraimo->id, 'facets' => ['model' => 'P47'], 'free_text' => null])
        ->assertStatus(409)->assertJsonPath('same.id', $right->id);
    ($this->stores)()->postJson("/api/back-office/catalogue/items/{$wrong->id}", ['category_id' => $this->earbuds->id, 'brand_id' => $this->oraimo->id, 'facets' => ['model' => 'P48'], 'free_text' => null])
        ->assertOk()->assertJson(['name' => 'Oraimo P48 Earbuds']);
    expect($wrong->fresh()->status)->toBe('active');
    expect(DB::table('audit_log')->where('action', 'catalogue.item_changed')->count())->toBe(1);
});

it('marks a Request-only line as a typed line, and makes it a proper item', function () {
    app(Settings::class)->set('catalogue.cashier_item_creation', 'request_only', 'console');
    $line = cashierItem(['category_id' => $this->earbuds->id, 'free_text' => 'neckband sport']);
    expect($line->source)->toBe('typed');
    expect(($this->owns)()->getJson('/api/back-office/review')->json('items.0'))->toMatchArray(['name' => 'Earbuds neckband sport', 'typed_line' => true]);

    // Only the owner or the storekeeper promotes a typed line (spec section 6) [F20].
    ($this->manages)()->postJson("/api/back-office/catalogue/items/{$line->id}", ['category_id' => $this->earbuds->id, 'brand_id' => $this->oraimo->id, 'facets' => ['model' => 'Neckband Sport'], 'free_text' => null])
        ->assertForbidden();
    ($this->owns)()->postJson("/api/back-office/catalogue/items/{$line->id}", ['category_id' => $this->earbuds->id, 'brand_id' => $this->oraimo->id, 'facets' => ['model' => 'Neckband Sport'], 'free_text' => null])
        ->assertOk()->assertJson(['name' => 'Oraimo Neckband Sport Earbuds']);
    ($this->owns)()->postJson("/api/back-office/catalogue/items/{$line->id}", ['category_id' => $this->earbuds->id, 'brand_id' => null, 'facets' => [], 'free_text' => 'others'])->assertStatus(422);
});

it('merges two items without changing a sale, counts them together in reports, refuses a loop, and undoes it (check C6)', function () {
    $a = $this->counter['items'][0];
    $b = Catalogue::anyItem('item 2');
    reviewSell($a, 100_000);
    reviewSell($b, 200_000);
    $lines = DB::table('sale_lines')->orderBy('id')->get()->toArray();

    $merge = ($this->manages)()->postJson("/api/back-office/review/items/{$b->id}/merge", ['into_id' => $a->id])->assertOk()->json('merge_id');
    expect($b->fresh())->status->toBe('merged')->merged_into_id->toBe($a->id);
    expect(DB::table('sale_lines')->orderBy('id')->get()->toArray())->toEqual($lines);
    expect(NeedsCost::queue()['items'])->toHaveCount(1)->and(NeedsCost::queue()['items'][0])->toMatchArray(['item_id' => $a->id, 'sales' => 2, 'revenue_kobo' => 300_000]);
    ($this->owns)()->postJson('/api/back-office/costs/preview', ['item_ids' => [$a->id], 'unit_cost_kobo' => 50_000, 'basis' => 'first_null'])->assertJson(['sales' => 2]);
    expect(app(Items::class)->findOrCreate(Category::query()->where('code', 'test_things')->firstOrFail(), freeText: 'item 2')->id)->toBe($a->id);

    // Merging back would make a loop: refused.
    ($this->manages)()->postJson("/api/back-office/review/items/{$a->id}/merge", ['into_id' => $b->id])->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'loop'));
    expect(($this->manages)()->getJson('/api/back-office/review')->json('merges.0'))->toMatchArray(['id' => $merge, 'sales' => 2]);

    ($this->manages)()->postJson("/api/back-office/review/merges/{$merge}/undo")->assertOk();
    expect($b->fresh())->status->toBe('active')->merged_into_id->toBeNull();
    expect(NeedsCost::queue()['items'])->toHaveCount(2);
    ($this->manages)()->postJson("/api/back-office/review/merges/{$merge}/undo")->assertStatus(422);
    expect(DB::table('merge_events')->pluck('kind')->sort()->values()->all())->toBe(['merge', 'undo']);
    expect(fn () => DB::table('merge_events')->delete())->toThrow(QueryException::class);
});

it('suggests likely duplicates, keeping the one from the catalogue, until someone says they are not the same', function () {
    $listed = app(Items::class)->findOrCreate($this->earbuds, ['model' => 'FreePods 3C'], $this->oraimo, source: 'owner');
    $typed = cashierItem(['category_id' => $this->earbuds->id, 'brand_id' => $this->oraimo->id, 'facets' => ['model' => 'FreePod 3C']]);

    $pair = ($this->owns)()->getJson('/api/back-office/review')->json('duplicates.0');
    expect($pair['keep']['id'])->toBe($listed->id)->and($pair['merge']['id'])->toBe($typed->id)->and($pair['reason'])->toContain('differ by one letter');

    ($this->owns)()->postJson('/api/back-office/review/duplicates/not-same', ['item_id' => $typed->id, 'other_id' => $listed->id])->assertOk();
    expect(($this->owns)()->getJson('/api/back-office/review')->json('duplicates'))->toBe([]);
});

it('undoes a learned barcode, settles a conflict, removes a wrong search word for good, and follows a merge when scanned', function () {
    $a = $this->counter['items'][0];
    $b = Catalogue::anyItem('item 2');
    $learning = app(Learning::class);

    $learning->linkBarcode('6941812709883', $a->id, $this->cashier->id);
    $link = ($this->stores)()->getJson('/api/back-office/review')->json('learned.0');
    expect($link)->toMatchArray(['code' => '6941812709883', 'learned_by' => 'Amina', 'confirmations' => 0]);
    ($this->stores)()->postJson("/api/back-office/review/barcodes/{$link['id']}/undo")->assertOk();
    expect(DB::table('barcodes')->where('id', $link['id'])->value('status'))->toBe('disabled');

    // Two cashiers chose different items for one code: keep one, and the other link is undone.
    $bola = People::add('Bola', Role::Cashier, '2468');
    $learning->linkBarcode('6953156270342', $a->id, $this->cashier->id);
    $learning->linkBarcode('6953156270342', $b->id, $bola->id);
    expect(($this->stores)()->getJson('/api/back-office/review')->json('conflicts.0.links'))->toHaveCount(2);
    ($this->stores)()->postJson('/api/back-office/review/barcodes/settle', ['code' => '6953156270342', 'keep_item_id' => $a->id])->assertOk();
    expect(DB::table('barcodes')->where('code', '6953156270342')->pluck('status', 'item_id')->all())->toBe([$a->id => 'confirmed', $b->id => 'disabled']);

    // Keep both: the counter asks each time, and choosing one again raises no new conflict.
    $learning->linkBarcode('6900000000017', $a->id, $this->cashier->id);
    $learning->linkBarcode('6900000000017', $b->id, $bola->id);
    ($this->stores)()->postJson('/api/back-office/review/barcodes/settle', ['code' => '6900000000017', 'keep_item_id' => null])->assertOk();
    $learning->recordScan('6900000000017', $b->id, $this->cashier->id);
    expect(DB::table('barcodes')->where('code', '6900000000017')->where('conflicted', true)->count())->toBe(0);

    foreach (range(1, 3) as $i) {
        $learning->recordSearch('hoco iphone', $a->id);
    }
    $alias = collect(($this->stores)()->getJson('/api/back-office/review')->json('aliases'))->firstWhere('term', 'hoco iphone');
    expect($alias['active'])->toBeTrue();
    ($this->stores)()->postJson("/api/back-office/review/aliases/{$alias['id']}/remove")->assertOk();
    $learning->recordSearch('hoco iphone', $a->id);
    expect(DB::table('aliases')->where('id', $alias['id'])->value('status'))->toBe('removed');

    // A merged item's barcode adds the item it now counts under.
    $learning->linkBarcode('6936686402011', $b->id, $this->cashier->id);
    ($this->stores)()->postJson("/api/back-office/review/items/{$b->id}/merge", ['into_id' => $a->id])->assertOk();
    expect($learning->candidates('6936686402011'))->toBe([['item_id' => $a->id, 'name' => $a->display_name, 'status' => 'unconfirmed']]);
});

it('keeps review from cashiers, and names and categories from the storekeeper', function () {
    ($this->as)('Amina', '1357')->getJson('/api/back-office/review')->assertForbidden();
    $brand = Brand::query()->create(['name' => 'Typedbrand', 'status' => 'pending', 'source' => 'cashier']);
    ($this->stores)()->postJson("/api/back-office/review/brands/{$brand->id}/approve")->assertForbidden();
    ($this->stores)()->postJson('/api/back-office/review/categories', ['typed' => 'Fan', 'word' => 'Fan', 'name' => 'Fans'])->assertForbidden();
});
