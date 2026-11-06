<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;

it('renders the home page through Inertia with the version and the database', function () {
    $this->get('/')
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
