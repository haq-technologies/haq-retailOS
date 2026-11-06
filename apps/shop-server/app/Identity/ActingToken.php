<?php

declare(strict_types=1);

namespace App\Identity;

use Carbon\CarbonImmutable;

/**
 * The acting-user token (spec section 3) [F9]. The terminal's login identifies the device only; each PIN
 * entry mints this short-lived signed token naming the person, bound to that terminal, and every request
 * carries it. A request in flight during a PIN switch keeps the token it was sent with. The token expires
 * with the idle lock (sooner for a manager or owner), and each request renews it.
 */
final class ActingToken
{
    public const HEADER = 'X-Acting-Token';

    /**
     * @param  array{u: string, t: string, r: string, exp: int}  $claims
     */
    private function __construct(public readonly array $claims) {}

    public static function mint(string $userId, string $terminalId, Role $role, CarbonImmutable $expires): string
    {
        $payload = self::base64(json_encode([
            'v' => 1,
            'u' => $userId,
            't' => $terminalId,
            'r' => $role->value,
            'iat' => CarbonImmutable::now()->getTimestamp(),
            'exp' => $expires->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.self::base64(self::signature($payload));
    }

    /** The token's claims if its signature holds and it has not expired; otherwise null. */
    public static function verify(string $token): ?self
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $signature] = $parts;
        if (! hash_equals(self::base64(self::signature($payload)), $signature)) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (! is_array($claims) || ! isset($claims['u'], $claims['t'], $claims['r'], $claims['exp'])
            || ! is_string($claims['u']) || ! is_string($claims['t']) || ! is_string($claims['r']) || ! is_int($claims['exp'])) {
            return null;
        }
        if ($claims['exp'] <= CarbonImmutable::now()->getTimestamp()) {
            return null;
        }

        return new self(['u' => $claims['u'], 't' => $claims['t'], 'r' => $claims['r'], 'exp' => $claims['exp']]);
    }

    public function userId(): string
    {
        return $this->claims['u'];
    }

    public function terminalId(): string
    {
        return $this->claims['t'];
    }

    private static function signature(string $payload): string
    {
        $key = hash_hmac('sha256', 'haq-acting-token-v1', config()->string('app.key'), true);

        return hash_hmac('sha256', $payload, $key, true);
    }

    private static function base64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
