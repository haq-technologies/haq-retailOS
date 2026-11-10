<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Content Security Policy on every page the server renders (D64). Only the shop server's own scripts,
 * styles, fonts, workers and API may load, so injected markup cannot run script or send data elsewhere.
 * Inertia's page data is a JSON data block, which the policy does not treat as script. Inline styles stay
 * allowed: React sets them through the DOM. Only local development with the Vite dev server running goes
 * without it, because that server's scripts come from another origin.
 */
final class ContentSecurityPolicy
{
    public const POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
        ."font-src 'self'; connect-src 'self'; worker-src 'self'; manifest-src 'self'; object-src 'none'; "
        ."base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $devServer = app()->environment('local') && Vite::isRunningHot();
        if (! $devServer && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::POLICY);
        }

        return $response;
    }
}
