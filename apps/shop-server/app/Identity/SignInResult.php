<?php

declare(strict_types=1);

namespace App\Identity;

use App\Identity\Models\User;
use Carbon\CarbonImmutable;

/**
 * The outcome of a PIN entry, in words a cashier can act on (spec section 15: plain-language errors).
 */
final readonly class SignInResult
{
    private function __construct(
        public string $outcome,
        public ?User $user = null,
        public ?string $token = null,
        public ?CarbonImmutable $expires = null,
        public ?CarbonImmutable $lockedUntil = null,
        public int $waitSeconds = 0,
        public string $timezone = 'Africa/Lagos',
    ) {}

    public static function signedIn(User $user, string $token, CarbonImmutable $expires): self
    {
        return new self('signed_in', $user, $token, $expires);
    }

    public static function wrongPin(): self
    {
        return new self('wrong_pin');
    }

    public static function locked(CarbonImmutable $until, string $timezone): self
    {
        return new self('locked', lockedUntil: $until, timezone: $timezone);
    }

    public static function terminalBackoff(int $seconds): self
    {
        return new self('backoff', waitSeconds: $seconds);
    }

    public static function unavailable(): self
    {
        return new self('unavailable');
    }

    public function succeeded(): bool
    {
        return $this->outcome === 'signed_in';
    }

    public function message(): string
    {
        return match ($this->outcome) {
            'signed_in' => 'Signed in.',
            'wrong_pin' => 'That PIN is not right. Try again.',
            'locked' => 'Too many wrong PINs. This name is locked until '
                .($this->lockedUntil?->setTimezone($this->timezone)->format('H:i') ?? 'later')
                .'. A manager can see this on the dashboard.',
            'backoff' => "Too many wrong PINs on this counter. Wait {$this->waitSeconds} seconds, then try again.",
            default => 'This person cannot sign in. Ask the owner.',
        };
    }
}
