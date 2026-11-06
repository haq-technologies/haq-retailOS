<?php

declare(strict_types=1);

use App\Catalogue\Models\Category;
use App\Catalogue\StarterCatalogue;
use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Role;
use App\Sales\Finish;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * The counter page and what its client needs from the server (spec sections 4, 6 and 7) [R2, R4, S5, S10].
 */

beforeEach(function () {
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $this->counter = Counter::ready(items: 1);
    app(StarterCatalogue::class)->load(config()->string('catalogue.seed'));
    [$this->t1] = $this->counter['terminals'];
    [$this->a] = $this->counter['items'];
    $this->device = People::enrol($this->t1);
    $this->manager = People::add('Kemi', Role::Manager, '246810');
    $this->as = function () {
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/identity/pin', ['user_id' => $this->counter['cashier']->id, 'pin' => '1357'])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $this->device)->withHeader(ActingToken::HEADER, $token);
    };
});

it('opens the counter page from home on an enrolled counter, with what the client needs and no cost', function () {
    $this->get('/')->assertRedirect('/counter');
    $page = $this->withCookie(Enrolment::COOKIE, $this->device)->get('/counter')->assertOk();
    $page->assertInertia(fn ($p) => $p->component('counter/index')
        ->where('terminal.code', 'T01')->where('terminal.canSell', true)
        ->has('tiles', 2)
        ->where('settings.transferAlertTick', true)->where('settings.floorPercent', 50)->where('settings.cashierItemCreation', 'on')
        ->where('settings.colours', fn ($c) => count($c) > 3));
    expect($page->getContent())->not->toContain('cost');

    $this->defaultCookies = []; // a device that was never enrolled
    $this->get('/counter')->assertRedirect('/enrol');
});

it('serves the catalogue to an enrolled counter before anyone signs in', function () {
    $this->withCookie(Enrolment::COOKIE, $this->device)->getJson('/api/counter/catalogue/snapshot')->assertOk()->assertJsonStructure(['seq', 'rows', 'prices', 'next']);
    $this->withCookie(Enrolment::COOKIE, $this->device)->getJson('/api/counter/catalogue/changes?after=0')->assertOk();
    $this->defaultCookies = [];
    $this->getJson('/api/counter/catalogue/changes?after=0')->assertStatus(401);
});

it('lists the generated shapes, and the first sale of one creates its item', function () {
    $shapes = $this->withCookie(Enrolment::COOKIE, $this->device)->getJson('/api/counter/catalogue/shapes')->assertOk()->json('shapes');
    $cables = Category::query()->where('code', 'cables')->value('id');
    $shape = collect($shapes)->first(fn ($s) => $s['category_id'] === $cables && count($s['facets']) === 2);
    expect($shape)->not->toBeNull()->and($shape['display_name'])->toContain('Cable');

    $first = ($this->as)()->postJson('/api/counter/shapes', ['category_id' => $cables, 'facets' => $shape['facets']])->assertOk()->json();
    expect($first['item']['display_name'])->toBe($shape['display_name']);
    $again = ($this->as)()->postJson('/api/counter/shapes', ['category_id' => $cables, 'facets' => $shape['facets']])->assertOk()->json();
    expect($again['item']['id'])->toBe($first['item']['id']);

    ($this->as)()->postJson('/api/counter/shapes', ['category_id' => $cables, 'facets' => ['length' => $this->a->id]])->assertStatus(422);
});

it('tells the counter which line needs a price check or a manager, so it can show the prompt', function () {
    app(Settings::class)->set('pricing.fill_fallback', 'last_price', 'console');
    $as = ($this->as)();
    foreach ([300_000, 300_000, 300_000, 300_000, 300_000] as $price) {
        $sale = Counter::cashSale([$this->a->id => [1, $price]]);
        app(Finish::class)->finish($this->t1, $this->counter['cashier'], Counter::request($sale));
    }

    $high = Counter::cashSale([$this->a->id => [1, 400_000]]);
    $as->postJson('/api/counter/sales', $high)->assertStatus(422)
        ->assertJsonPath('check.kind', 'confirm')->assertJsonPath('check.client_line_id', $high['lines'][0]['client_line_id']);

    $silly = Counter::cashSale([$this->a->id => [1, 3_000_000]]);
    $as->postJson('/api/counter/sales', $silly)->assertStatus(422)->assertJsonPath('check.kind', 'retype');

    $low = Counter::cashSale([$this->a->id => [1, 50_000]]);
    $as->postJson('/api/counter/sales', $low)->assertStatus(422)
        ->assertJsonPath('approval.action', 'price_below_floor')
        ->assertJsonPath('approval.subject_id', $low['lines'][0]['client_line_id'])
        ->assertJsonPath('approval.item_id', $this->a->id)->assertJsonPath('approval.price_kobo', 50_000);
});

it('ranks search by the last 30 days of sales', function () {
    foreach ([1, 2] as $_) {
        app(Finish::class)->finish($this->t1, $this->counter['cashier'], Counter::request(Counter::cashSale([$this->a->id => [2, 5000]])));
    }
    $prices = ($this->as)()->postJson('/api/counter/prices', ['item_ids' => [$this->a->id]])->json('prices');
    expect($prices[0]['sold_30d'])->toBe(4);
});
