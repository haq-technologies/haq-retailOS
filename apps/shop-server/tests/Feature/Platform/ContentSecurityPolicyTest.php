<?php

declare(strict_types=1);

use App\Http\Middleware\ContentSecurityPolicy;
use App\Identity\Enrolment;
use Tests\Support\People;
use Tests\Support\Shop;

/*
 * The Content Security Policy (D64): every page the server renders allows only its own scripts. The browser
 * tests run the built pages under it, so a page that broke it would fail there too.
 */

it('sends the policy with every page, enrolled or not', function () {
    $this->get('/enrol')->assertOk()->assertHeader('Content-Security-Policy', ContentSecurityPolicy::POLICY);

    [$t1] = Shop::create()['terminals'];
    $this->withCookie(Enrolment::COOKIE, People::enrol($t1))->get('/counter')
        ->assertOk()->assertHeader('Content-Security-Policy', ContentSecurityPolicy::POLICY);
});

it('allows script only from the server itself, and no framing', function () {
    expect(ContentSecurityPolicy::POLICY)
        ->toContain("script-src 'self';")->not->toContain("script-src 'self' 'unsafe")
        ->toContain("object-src 'none'")->toContain("frame-ancestors 'none'")->toContain("connect-src 'self'");
});
