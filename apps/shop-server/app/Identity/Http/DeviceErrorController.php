<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Platform\Http\RequestContext;
use App\Platform\Logging\ErrorCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * A counter's or back-office device's own errors (finding LG3). In kiosk mode nobody opens Chrome's console,
 * so a screen that failed sends what it knows here: the error's message and stack, and the page's path. Never
 * the cart, a PIN or anything typed: the browser sends only those three. Only an enrolled device may send,
 * at most 20 a minute; the log line carries the counter's code and the request's reference (finding LG5),
 * and the health page counts it (finding LG6).
 */
final class DeviceErrorController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.ErrorCounts::CRASH.','.ErrorCounts::BROWSER],
            'message' => ['required', 'string', 'max:500'],
            'stack' => ['nullable', 'string', 'max:4000'],
            'page' => ['nullable', 'string', 'max:200'],
        ]);

        $crash = $data['kind'] === ErrorCounts::CRASH;
        // The browser's text goes in the context, which the log writes as JSON, so it cannot pass for a line.
        Log::warning($crash ? 'A screen on this device failed and showed "Something went wrong".' : 'A browser error on this device; the screen went on.', [
            'kind' => $data['kind'],
            'page' => $data['page'] ?? null,
            'message' => $data['message'],
            'stack' => $data['stack'] ?? null,
        ]);
        ErrorCounts::add($crash ? ErrorCounts::CRASH : ErrorCounts::BROWSER);

        return response()->json(['reference' => RequestContext::reference()], 202);
    }
}
