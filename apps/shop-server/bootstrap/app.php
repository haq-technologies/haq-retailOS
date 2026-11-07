<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Platform\Database\Unavailable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

/*
 * Every setting lives in config/. Laravel's own defaults are not merged in, so a deleted connection
 * (Redis, SQLite, MySQL and the like) stays deleted rather than coming back from the framework.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Reboot recovery (spec section 4) [D46]: while the database is starting, answer 503 with Retry-After,
        // so nginx shows "Starting, please wait" and the counter shows Reconnecting, never a 500 error.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! Unavailable::is($e)) {
                return null;
            }
            $headers = ['Retry-After' => '5', 'Cache-Control' => 'no-store'];

            return $request->is('api/*') || $request->expectsJson()
                ? response()->json(['message' => 'The shop server is starting. It answers again in a moment.'], 503, $headers)
                : response((string) file_get_contents(public_path('starting.html')), 503, [...$headers, 'Content-Type' => 'text/html; charset=utf-8']);
        });
        // A database still starting is expected after a power cut; it is not an error to log each time.
        $exceptions->dontReportWhen(fn (Throwable $e) => Unavailable::is($e));
    })->create()
    ->dontMergeFrameworkConfiguration();
