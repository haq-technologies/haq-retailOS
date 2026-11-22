<?php

declare(strict_types=1);

use App\Platform\Http\HealthCheck;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

it('renders the server page through Inertia with the version, and the database answering without its version (AU10)', function () {
    $this->get('/server')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('home')
            ->where('version', config('app.version'))
            ->where('database', 'Answering')
        )
        ->assertDontSee('PostgreSQL');
});

it('answers the health check with a plain page of its own, loading nothing from anywhere (D130, VP5)', function () {
    $response = $this->get('/up')->assertOk()
        ->assertHeader('Content-Security-Policy', HealthCheck::POLICY)
        ->assertSee('The shop server is up.');
    expect((string) $response->getContent())->not->toContain('http')->not->toContain('<script')->not->toContain('<link');
    expect($response->headers->getCookies())->toBe([]);

    $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'up']);
});

it('answers 500 when a health listener fails', function () {
    Event::listen(DiagnosingHealth::class, fn () => throw new RuntimeException('The disk is full.'));

    $this->get('/up')->assertStatus(500)->assertSee('The shop server is not working.')->assertDontSee('The disk is full.');
});

it('sends a counter from the root to its name tiles', function () {
    $this->get('/')->assertRedirect('/counter');
});
