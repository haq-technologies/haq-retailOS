<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Sales\Finish;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\DevTools\DevTools;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * The cost-leak scanner (spec section 14, check C4; hardening, D50) [F10]: cost appears in no response to
 * a cashier or, by default, a manager: not in the API, the pages' props, the audit views or the CSV
 * exports. It calls every GET route the app has, as each of them, and searches every answer for a planted
 * cost. A new route it cannot call fails the test until the scanner learns it, so nothing is skipped.
 */

/** ₦9,876.54: a cost no selling price or count here comes near, in every form it could be written. */
const PROBE = 987_654;

const PROBE_FORMS = ['987654', '9876.54', '9,876.54', '9876.5', '98765'];

/** Keys that only ever carry a cost. */
const COST_KEYS = ['"cost_kobo"', '"unit_cost_kobo"', '"cost_snapshot_kobo"', '"cost_after_kobo"', '"profit_kobo"', 'Cost (NGN)', 'Profit (NGN)'];

beforeEach(function () {
    $this->counter = Counter::ready(items: 2);
    [$this->t1] = $this->counter['terminals'];
    [$this->a, $this->b] = $this->counter['items'];
    $this->cashier = $this->counter['cashier'];
    People::add('Halima', Role::Owner, '135790');
    People::add('Kemi', Role::Manager, '246810');
    $this->till = People::enrol($this->t1);
    $this->office = People::enrol(Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'D01', 'name' => 'Owner laptop', 'kind' => 'back_office']));
    $this->as = function (string $name, string $pin, string $device) {
        $user = User::query()->where('name', $name)->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token);
    };
    $sell = fn (string $itemId, int $price) => app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(Counter::cashSale([$itemId => [1, $price]], [])));

    // Cost reaches the records every way it can: estimates beside earlier sales, a cost at sale, a sale
    // below cost (a flag that names the cost), the audit's sensitive stream, and a cost entry to undo.
    $sell($this->a->id, 1_500_000);
    $sell($this->b->id, 500_000);
    ($this->as)('Halima', '135790', $this->office)->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id, $this->b->id], 'unit_cost_kobo' => PROBE, 'basis' => 'first_null'])->assertOk();
    $this->sale = $sell($this->b->id, 400_000);
    $this->entry = (string) DB::table('cost_entries')->where('item_id', $this->a->id)->value('id');
});

/**
 * Every GET route the app answers, with its parameters filled from this shop.
 *
 * @return list<string>
 */
function scannedUrls(object $test): array
{
    $values = [
        'item' => $test->a->id, 'sale' => $test->sale['id'], 'code' => '6001234567890', 'kind' => 'brands',
        'category' => (string) DB::table('items')->where('id', $test->a->id)->value('category_id'), 'entry' => $test->entry,
    ];
    // Answers with more in them: the whole period, not only today.
    $queries = ['api/back-office/flags' => '?period=all', 'api/back-office/flags.csv' => '?period=all', 'api/back-office/exports/sales.csv' => '?period=all',
        'api/counter/catalogue/changes' => '?after=0', 'api/back-office/catalogue/search' => '?q=item'];
    $skip = ['up', 'storage/{path}', '_inertia/devtools/entries', '_inertia/devtools/entries/{id}'];

    $urls = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var LaravelRoute $route */
        if (! in_array('GET', $route->methods(), true) || in_array($route->uri(), $skip, true) || str_starts_with($route->uri(), '_ignition')) {
            continue;
        }
        $uri = preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($values, $route): string {
            if (! isset($values[$m[1]])) {
                throw new RuntimeException("The cost-leak scanner cannot call {$route->uri()}: teach it a value for {{$m[1]}}.");
            }

            return (string) $values[$m[1]];
        }, $route->uri());
        $urls[] = '/'.ltrim((string) $uri, '/').($queries[$route->uri()] ?? '');
    }
    sort($urls);

    return $urls;
}

/**
 * @return list<string> what leaked, as "url: form"
 */
function leaks(object $client, array $urls, int $mustAnswer): array
{
    $found = [];
    $answered = [];
    foreach ($urls as $url) {
        $response = $client->get($url, ['Accept' => 'application/json']);
        if ($response->getStatusCode() === 200) {
            $answered[] = $url;
        }
        $body = (string) $response->getContent();
        foreach ([...PROBE_FORMS, ...COST_KEYS] as $form) {
            if (str_contains($body, $form)) {
                $found[] = "{$url}: {$form}";
            }
        }
    }
    // A scan of refusals proves nothing: each person must have had real answers to search.
    expect(count($answered))->toBeGreaterThanOrEqual($mustAnswer);

    return $found;
}

it('finds no cost in any answer to a cashier, at the counter or the back office (check C4)', function () {
    $urls = scannedUrls($this);
    expect(count($urls))->toBeGreaterThan(40);

    expect(leaks(($this->as)('Amina', '1357', $this->till), $urls, mustAnswer: 12))->toBe([]);
    expect(leaks(($this->as)('Amina', '1357', $this->office), $urls, mustAnswer: 8))->toBe([]);
});

it('finds no cost in any answer to a manager while HAQ keeps cost from managers (check C4)', function () {
    // A manager opens most of the back office: the dashboard, flags, review, prices, the summary, the
    // exports, the audit log and lost sales, and all of it is searched.
    expect(leaks(($this->as)('Kemi', '246810', $this->office), scannedUrls($this), mustAnswer: 25))->toBe([]);
    expect(leaks(($this->as)('Kemi', '246810', $this->till), scannedUrls($this), mustAnswer: 25))->toBe([]);
});

it('finds the planted cost in the owner\'s answers, so the scanner can see it', function () {
    $owner = ($this->as)('Halima', '135790', $this->office);
    $seen = array_filter(scannedUrls($this), fn (string $url) => str_contains((string) $owner->get($url, ['Accept' => 'application/json'])->getContent(), '987654'));
    expect($seen)->toContain('/api/back-office/costs/items/'.$this->a->id, '/api/back-office/sales/'.$this->sale['id'], '/api/back-office/audit');
});

it('never records page data for the devtools on a shop server', function () {
    config(['inertia.devtools.enabled' => null]);
    app()->instance('env', 'production');
    app()['env'] = 'production';
    expect(DevTools::enabled())->toBeFalse();
});
