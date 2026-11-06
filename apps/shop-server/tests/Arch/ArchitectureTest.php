<?php

declare(strict_types=1);

/*
 * The modular monolith's boundaries (D23). Each module is a namespace under app/ and may use only the
 * modules listed for it, which keeps the dependencies one-way. Add a module here before writing it.
 */

const MODULES = [
    'Platform' => [],
    'Organisation' => ['Platform'],
    'Audit' => ['Platform', 'Organisation'],
    'Settings' => ['Platform', 'Organisation', 'Audit'],
    'Identity' => ['Platform', 'Organisation', 'Audit', 'Settings'],
    'Catalogue' => ['Platform', 'Organisation', 'Audit', 'Settings', 'Identity'],
    'Cost' => ['Platform', 'Organisation', 'Audit', 'Settings', 'Identity', 'Catalogue'],
    'Shifts' => ['Platform', 'Organisation', 'Audit', 'Settings', 'Identity'],
    'Sales' => ['Platform', 'Organisation', 'Audit', 'Settings', 'Identity', 'Catalogue', 'Cost', 'Shifts'],
    'Printing' => ['Platform', 'Organisation', 'Audit', 'Settings', 'Identity', 'Catalogue', 'Sales', 'Shifts'],
    'Counter' => ['Platform', 'Organisation', 'Audit', 'Settings', 'Identity', 'Catalogue', 'Cost', 'Shifts', 'Sales', 'Printing'],
];

foreach (MODULES as $module => $allowed) {
    $forbidden = array_map(
        fn (string $other) => "App\\{$other}",
        array_values(array_diff(array_keys(MODULES), [$module, ...$allowed])),
    );

    if ($forbidden !== []) {
        arch("{$module} uses only the modules beneath it")
            ->expect("App\\{$module}")
            ->not->toUse($forbidden);
    }
}

arch('the Laravel glue does not reach into modules except through their service providers')
    ->expect('App\Http')
    ->not->toUse(array_map(fn (string $m) => "App\\{$m}", array_keys(MODULES)));

arch('only the Page helper renders Inertia pages, so no Eloquent model reaches a page')
    ->expect('Inertia\Inertia')
    ->toOnlyBeUsedIn('App\Platform\Http\Page');

arch('no inertia() helper either')
    ->expect('inertia')
    ->not->toBeUsed();

arch('models live in a module, never in App\Models')
    ->expect('App\Models')
    ->not->toBeUsed();

arch('every class declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch()->preset()->php();

arch()->preset()->security();
