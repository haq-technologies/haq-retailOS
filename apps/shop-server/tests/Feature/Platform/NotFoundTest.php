<?php

declare(strict_types=1);

use App\Catalogue\Models\Item;
use Illuminate\Support\Facades\Route;

/*
 * An API's not-found answer is plain (D133, finding VP9): it never names a model's class or anything else
 * inside the server.
 */

it('answers a missing record on the API with a plain message, naming no class', function () {
    Route::get('/api/testing/missing-item', fn () => Item::query()->findOrFail('01JZZZZZZZZZZZZZZZZZZZZZZZ'));

    $response = $this->getJson('/api/testing/missing-item')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect((string) $response->getContent())->not->toContain('App\\')->not->toContain('No query results');

    // A request that does not ask for JSON on an API path gets the same plain answer.
    $this->get('/api/testing/missing-item')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});

it('answers an unknown API path the same way', function () {
    $this->getJson('/api/no-such-thing')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});
