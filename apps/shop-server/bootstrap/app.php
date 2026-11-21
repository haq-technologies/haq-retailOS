<?php

declare(strict_types=1);

use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\HandleInertiaRequests;
use App\Platform\Database\Unavailable;
use App\Platform\Http\RequestContext;
use App\Platform\Logging\QueryErrors;
use App\Platform\Logging\ReportThrottle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
        // First of all, so every log line of a request, a 404's included, names the request (finding LG5).
        $middleware->prepend(RequestContext::class);
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            ContentSecurityPolicy::class,
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
        // A failed query is logged without its values: the connections mask the SQL's, and this leaves out
        // PostgreSQL's DETAIL line, which can hold a whole row (finding LG1).
        $exceptions->map(QueryException::class, fn (QueryException $e) => QueryErrors::forLog($e));
        // The same error is written in full a few times a minute and counted every time (findings LG6, LG8).
        $exceptions->throttle(fn (Throwable $e) => ReportThrottle::for($e));
        // A 500 names its reference, the request ID's last characters, so a cashier can read it to HAQ
        // support and support can find the error's lines (finding LG5). A page load shows errors/500.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            if ($response->getStatusCode() !== 500 || config()->boolean('app.debug') || ! ($request->is('api/*') || $request->expectsJson())) {
                return $response;
            }
            $reference = RequestContext::reference();

            return response()->json([
                'message' => 'The server had a problem. Try again; if it happens again, tell HAQ support the reference '.$reference.'.',
                'reference' => $reference,
            ], 500, ['Cache-Control' => 'no-store']);
        });
    })->create()
    ->dontMergeFrameworkConfiguration();
