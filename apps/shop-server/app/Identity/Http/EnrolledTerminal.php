<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\Enrolment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only from an enrolled counter PC, and attaches its terminal.
 */
final readonly class EnrolledTerminal
{
    public function __construct(private Enrolment $enrolment) {}

    public function handle(Request $request, Closure $next): Response
    {
        $cookie = $request->cookie(Enrolment::COOKIE);
        $terminal = $this->enrolment->terminalFor(is_string($cookie) ? $cookie : null);
        if ($terminal === null) {
            return $request->expectsJson()
                ? response()->json(['message' => 'This PC is not enrolled as a counter. Ask HAQ support to enrol it.'], 401)
                : redirect()->route('identity.enrol');
        }
        $request->attributes->set('terminal', $terminal);

        return $next($request);
    }
}
