<?php

declare(strict_types=1);

use App\BackOffice\Access;
use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\People;
use Tests\Support\Shop;

/*
 * Who may open what in the back office (spec section 3, Roles and permissions; D47). Every back-office
 * route is listed here with the ability it needs, and every role is tried on every one: a role without the
 * ability gets 403 before anything else happens, and one with it gets a real answer. A new route fails this
 * test until it is listed, so a route can never forget its check.
 */

/** Each back-office route and the Access ability it needs; null: anyone signed in. */
const BACK_OFFICE_ROUTES = [
    'GET me' => null, 'GET badges' => null, 'GET dashboard' => 'dashboard',
    'GET costs' => 'costs', 'GET costs/items/{item}' => 'costs', 'POST costs/preview' => 'costs', 'POST costs' => 'costs',
    'GET costs/recent' => 'costs', 'GET costs/entries/{entry}/undo' => 'costs', 'POST costs/entries/{entry}/undo' => 'costs',
    'GET flags' => 'flags', 'GET flags.csv' => 'flags', 'POST flags/seen' => 'flags', 'GET sales/{sale}' => 'dashboard',
    'GET review' => 'review', 'POST review/brands/{brand}/approve' => 'names', 'POST review/brands/{brand}/merge' => 'names',
    'POST review/models/{value}/approve' => 'names', 'POST review/models/{value}/merge' => 'names',
    'GET review/groups' => 'categories', 'POST review/categories' => 'categories',
    'POST review/items/{item}/approve' => 'review', 'POST review/items/{item}/merge' => 'review', 'POST review/merges/{merge}/undo' => 'review',
    'POST review/duplicates/not-same' => 'review', 'POST review/barcodes/{barcode}/undo' => 'review', 'POST review/barcodes/settle' => 'review',
    'POST review/aliases/{alias}/remove' => 'review',
    'GET prices/first-sales' => 'prices', 'GET prices/items/{item}' => 'prices', 'POST prices' => 'prices', 'POST prices/undo' => 'prices',
    'GET reports/health' => 'catalogue', 'GET reports/summary' => 'summary', 'POST reports/summary/print' => 'summary',
    'GET exports/items.csv' => 'catalogue', 'GET exports/sales.csv' => 'summary', 'GET exports/shop.zip' => 'export',
    'GET catalogue/categories' => 'catalogue', 'GET catalogue/categories/{category}/form' => 'catalogue', 'GET catalogue/items/{item}' => 'catalogue',
    'POST catalogue/items/{item}' => 'review', 'GET catalogue/search' => 'catalogue', 'GET catalogue/names/{kind}' => 'names',
    'GET audit' => 'audit', 'GET health' => 'health', 'POST health/backup' => 'health', 'POST health/clock' => 'health',
    'GET recovery' => 'recovery', 'POST recovery/lost/{lost}/re-enter' => 'recovery',
    'GET pilot' => 'pilot', 'GET pilot/days/{day}' => 'pilot', 'POST pilot/start' => 'pilot_decide', 'POST pilot/notebook' => 'pilot',
    'POST pilot/traces' => 'pilot', 'POST pilot/traces/{trace}/withdraw' => 'pilot', 'POST pilot/decision' => 'pilot_decide',
    'GET settings' => 'settings', 'POST settings' => 'settings',
];

beforeEach(function () {
    [$t1] = Shop::create()['terminals'];
    $this->office = People::enrol(Terminal::query()->create(['branch_id' => $t1->branch_id, 'code' => 'D01', 'name' => 'Owner laptop', 'kind' => 'back_office']));
    $this->people = [
        'cashier' => [People::add('Amina', Role::Cashier, '1357'), '1357'],
        'storekeeper' => [People::add('Sola', Role::Storekeeper, '2468'), '2468'],
        'manager' => [People::add('Kemi', Role::Manager, '246810'), '246810'],
        'owner' => [People::add('Halima', Role::Owner, '135790'), '135790'],
    ];
});

/** @return list<array{method: string, key: string, uri: string}> every back-office route the app has */
function backOfficeRoutes(): array
{
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var LaravelRoute $route */
        if (! str_starts_with($route->uri(), 'api/back-office/')) {
            continue;
        }
        $method = in_array('POST', $route->methods(), true) ? 'POST' : 'GET';
        $path = substr($route->uri(), strlen('api/back-office/'));
        $uri = preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => match ($m[1]) {
            'day' => '2026-11-10', 'kind' => 'brands', default => strtolower((string) Str::ulid()),
        }, $path);
        $routes[] = ['method' => $method, 'key' => "{$method} {$path}", 'uri' => '/api/back-office/'.$uri];
    }

    return $routes;
}

it('lists every back-office route with the ability it needs', function () {
    $keys = array_column(backOfficeRoutes(), 'key');
    sort($keys);
    $listed = array_keys(BACK_OFFICE_ROUTES);
    sort($listed);

    expect($keys)->toBe($listed);
});

it('refuses each role every route its role may not open, before anything else, and answers the rest', function () {
    $problems = [];
    foreach ($this->people as $role => [$user, $pin]) {
        /** @var User $user */
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->office)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');
        $can = app(Access::class)->for($user);
        foreach (backOfficeRoutes() as $route) {
            $ability = BACK_OFFICE_ROUTES[$route['key']] ?? null;
            $allowed = $ability === null || $can[$ability];
            $client = $this->withCookie(Enrolment::COOKIE, $this->office)->withHeader(ActingToken::HEADER, $token);
            $response = $route['method'] === 'POST' ? $client->postJson($route['uri'], []) : $client->getJson($route['uri']);
            $status = $response->getStatusCode();

            if (! $allowed && $status !== 403) {
                $problems[] = "{$role} {$route['key']}: {$status}, not 403";
            }
            // An allowed read answers, even for a record that is not there; it never errors.
            if ($allowed && $route['method'] === 'GET' && ($status === 403 || $status >= 500)) {
                $problems[] = "{$role} {$route['key']}: {$status}";
            }
        }
    }

    expect($problems)->toBe([]);
});
