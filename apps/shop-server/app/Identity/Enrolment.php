<?php

declare(strict_types=1);

namespace App\Identity;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Organisation\Models\Terminal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enrols a counter PC as a terminal: the terminal's login, which identifies the device only (spec
 * section 3) [F9]. The sysadmin issues a one-time code on the server; typed into the counter's browser,
 * it gives that browser a device secret, kept in an encrypted, HTTP-only cookie. Enrolling again
 * replaces the secret, so the old device stops working.
 */
final readonly class Enrolment
{
    public const COOKIE = 'haq_device';

    /** Long enough to walk from the server to a counter and set it up (D58). */
    public const CODE_MINUTES = 30;

    public function __construct(private AuditLog $audit) {}

    /** A one-time code, valid for 30 minutes, such as 4827-1093. */
    public function issueCode(Terminal $terminal): string
    {
        $code = sprintf('%04d-%04d', random_int(0, 9999), random_int(0, 9999));
        Cache::put($this->cacheKey($code), $terminal->id, CarbonImmutable::now()->addMinutes(self::CODE_MINUTES));

        return $code;
    }

    /**
     * Redeems a code once. Returns the cookie value (terminal ID and device secret), or null.
     */
    public function redeem(string $code): ?string
    {
        $terminalId = Cache::pull($this->cacheKey(trim($code)));
        if (! is_string($terminalId)) {
            return null;
        }
        $terminal = Terminal::query()->whereNull('deactivated_at')->find($terminalId);
        if ($terminal === null) {
            return null;
        }

        $secret = Str::random(48);
        DB::transaction(function () use ($terminal, $secret): void {
            $terminal->forceFill(['device_secret_hash' => hash('sha256', $secret), 'enrolled_at' => CarbonImmutable::now()])->save();
            $this->audit->record(new AuditEntry(action: 'identity.terminal_enrolled', terminalId: $terminal->id, subjectType: 'terminal', subjectId: $terminal->id));
        });

        return $terminal->id.'.'.$secret;
    }

    /** The terminal a device cookie belongs to, or null. */
    public function terminalFor(?string $cookie): ?Terminal
    {
        if ($cookie === null || ! str_contains($cookie, '.')) {
            return null;
        }
        [$id, $secret] = explode('.', $cookie, 2);
        $terminal = Terminal::query()->whereNull('deactivated_at')->find($id);
        $hash = $terminal?->getAttribute('device_secret_hash');
        if ($terminal === null || ! is_string($hash) || ! hash_equals($hash, hash('sha256', $secret))) {
            return null;
        }

        return $terminal;
    }

    private function cacheKey(string $code): string
    {
        return 'identity.enrol.'.hash('sha256', $code);
    }
}
