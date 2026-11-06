<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\ActingToken;
use App\Identity\Models\User;
use App\Identity\SignIn;
use App\Organisation\Models\Terminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the acting person from the request's own token, never from the shared session, so a request
 * in flight during a PIN switch is attributed to whoever sent it (spec section 3) [F9]. The token must
 * belong to this terminal. Each answer carries a renewed token, so an active person stays signed in and
 * an idle one is locked by the token's expiry.
 */
final readonly class ActingUser
{
    public function __construct(private SignIn $signIn) {}

    public function handle(Request $request, Closure $next): Response
    {
        $terminal = $request->attributes->get('terminal');
        $header = $request->header(ActingToken::HEADER);
        $token = is_string($header) ? ActingToken::verify($header) : null;

        if (! $terminal instanceof Terminal || $token === null || $token->terminalId() !== $terminal->id) {
            return response()->json(['message' => 'Sign in with your name and PIN.'], 401);
        }
        $user = User::query()->active()->find($token->userId());
        if ($user === null) {
            return response()->json(['message' => 'This person cannot sign in. Ask the owner.'], 401);
        }
        $request->attributes->set('acting_user', $user);

        $response = $next($request);
        $response->headers->set(ActingToken::HEADER, ActingToken::mint($user->id, $terminal->id, $user->role, $this->signIn->expiry($user->role)));

        return $response;
    }
}
