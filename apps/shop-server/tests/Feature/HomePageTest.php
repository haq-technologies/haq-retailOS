<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;

it('renders the server page through Inertia with the version and the database', function () {
    $this->get('/server')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('home')
            ->where('version', config('app.version'))
            ->where('database', fn (string $database) => str_starts_with($database, 'PostgreSQL 18.'))
        );
});

it('answers the health check', function () {
    $this->get('/up')->assertOk();
});

it('sends a counter from the root to its name tiles', function () {
    $this->get('/')->assertRedirect('/sign-in');
});
