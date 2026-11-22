<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * The health check, /up (D130, finding VP5). nginx, `haq check`, update.sh and the starting page ask it.
 * It answers as Laravel's own check does: 200 when every DiagnosingHealth listener passes, 500 when one
 * fails. Laravel's own page loaded fonts and script from other sites and had no Content Security Policy,
 * which broke D64; this page is plain text of its own and allows nothing to load at all.
 */
final class HealthCheck
{
    public const POLICY = "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    public function __invoke(Request $request): Response|JsonResponse
    {
        $up = true;
        try {
            Event::dispatch(new DiagnosingHealth);
        } catch (Throwable $e) {
            report($e);
            $up = false;
        }
        $status = $up ? 200 : 500;
        $headers = ['Content-Security-Policy' => self::POLICY, 'Cache-Control' => 'no-store'];

        if ($request->expectsJson()) {
            return response()->json(['status' => $up ? 'up' : 'down'], $status, $headers);
        }
        $text = $up ? 'The shop server is up.' : 'The shop server is not working.';
        $page = '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><title>HAQ Retail OS</title></head>'
            .'<body><p>'.$text.'</p></body></html>';

        return response($page, $status, [...$headers, 'Content-Type' => 'text/html; charset=utf-8']);
    }
}
