<?php

declare(strict_types=1);

namespace App\Health;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HAQ support on a shop's server, written down (D110, finding DP3 of the privacy checks). The owner consents
 * once, in the shop's contract; each visit is then recorded on the branch's audit chain and shown on the
 * owner's flag list, so support is never invisible:
 *
 * - every `sudo haq` command that reaches the shop's records (haq calls support:record), on Ubuntu and, through
 *   `haq-helper server`, on Windows;
 * - every SSH sign-in to an Ubuntu server, which deploy/host-health.sh reads from the journal and reports in
 *   host.json every 15 minutes. It reports the last day each time, so a sign-in is recorded once, by its key.
 */
final readonly class SupportAccess
{
    /** How far back a sign-in is still taken: the host reports a day, and a late report must not lose any. */
    private const SIGN_IN_DAYS = 2;

    public function __construct(private AuditLog $audit) {}

    /** A server command run by support. */
    public function command(string $what, ?string $by, ?string $from): void
    {
        $this->audit->record(new AuditEntry(action: 'support.command', subjectType: 'server',
            after: ['command' => mb_substr($what, 0, 40), 'by' => self::clean($by), 'from' => self::clean($from)]));
    }

    /**
     * Records each SSH sign-in in the host's report that is not recorded yet.
     *
     * @param  array<string, mixed>|null  $report  host.json
     * @return int how many were new
     */
    public function signIns(?array $report): int
    {
        $new = 0;
        $since = CarbonImmutable::now()->subDays(self::SIGN_IN_DAYS)->getTimestamp();
        foreach (is_array($report['sign_ins'] ?? null) ? $report['sign_ins'] : [] as $s) {
            if (! is_array($s) || ! is_int($s['at'] ?? null) || $s['at'] < $since || ! is_string($s['user'] ?? null)) {
                continue;
            }
            $from = is_string($s['from'] ?? null) ? $s['from'] : null;
            $method = is_string($s['method'] ?? null) ? $s['method'] : null;
            $key = hash('sha256', implode('|', [$s['at'], $s['user'], $from ?? '', is_int($s['port'] ?? null) ? $s['port'] : '']));
            $known = DB::table('audit_log')->where('action', 'support.signed_in')->whereRaw("redacted_after->>'key' = ?", [$key])->exists();
            if ($known) {
                continue;
            }
            $this->audit->record(new AuditEntry(action: 'support.signed_in', subjectType: 'server',
                after: ['key' => $key, 'user' => self::clean($s['user']), 'from' => self::clean($from), 'method' => self::clean($method)],
                recordedAt: CarbonImmutable::createFromTimestamp($s['at'])));
            $new++;
        }

        return $new;
    }

    /** A short printable word from the host or the shell, never a whole line of its log. */
    private static function clean(?string $value): ?string
    {
        $value = preg_replace('/[^\w.:@+-]/u', '', (string) $value) ?? '';

        return $value === '' ? null : mb_substr($value, 0, 64);
    }
}
