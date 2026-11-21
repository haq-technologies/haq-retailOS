<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ties every log line of a request to that request (finding LG5). nginx makes the request ID, logs it beside
 * the request and its timings, and passes it here; without nginx (tests, `php artisan serve`) one is made.
 * The ID, the method and the path join Laravel's log context, so an error's entry names them, and a queued
 * job carries them too. The counter and the person are added where they are known (EnrolledTerminal,
 * ActingUser). The answer carries the ID back, and a 500's message shows its last characters as a reference
 * to read to HAQ support.
 */
final class RequestContext
{
    public const HEADER = 'X-Request-Id';

    public const KEY = 'request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->headers->get(self::HEADER);
        if (! is_string($id) || preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            $id = bin2hex(random_bytes(16));
        }
        Context::add(self::KEY, $id);
        Context::add('request', $request->method().' /'.ltrim($request->path(), '/'));

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /** The reference a person reads out: the last 6 characters of the request ID. */
    public static function reference(): ?string
    {
        $id = Context::get(self::KEY);

        return is_string($id) ? substr($id, -6) : null;
    }
}
