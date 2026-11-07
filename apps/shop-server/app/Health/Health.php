<?php

declare(strict_types=1);

namespace App\Health;

use App\Audit\Console\VerifyCommand;
use App\Platform\Clock;
use App\Platform\Console\MutabilityCommand;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The server's health page (spec section 4, Backups and recovery; check E1 and E6; wireframe HealthPage)
 * [F3, F15, F24, D45]: the continuous WAL copy, the last backup, the last verified test restore, free disk,
 * SSD health, the certificate, the clock, the app version, the grant check and the audit chains, and the
 * warnings the owner's dashboard repeats (spec section 8). Server health only: never sales or cost.
 *
 * @phpstan-type Warning array{kind: string, title: string, detail: string}
 */
final readonly class Health
{
    public function __construct(private Clock $clock, private Settings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function view(): array
    {
        $snapshot = Snapshot::read();

        return [
            'checked_at' => $snapshot['checked_at'] ?? null,
            'backups' => $snapshot['backups'] ?? null,
            'disks' => $snapshot['disks'] ?? null,
            'certificate' => $snapshot['certificate'] ?? null,
            'host' => $snapshot['host'] ?? null,
            'wal' => self::wal(),
            'database_bytes' => self::databaseBytes(),
            'version' => config()->string('app.version'),
            'clock' => $this->clock->state(),
            'grants' => self::lastRun(MutabilityCommand::HEALTH_KEY),
            'audit' => self::lastRun(VerifyCommand::HEALTH_KEY),
            'chains' => (int) DB::table('audit_log')->distinct()->count('chain_id'),
            'warnings' => $this->warnings(),
        ];
    }

    /**
     * Everything that needs putting right, worst first. Each is read live and goes once it is right.
     *
     * @return list<array{kind: string, title: string, detail: string}>
     */
    public function warnings(): array
    {
        $snapshot = Snapshot::read();
        $now = CarbonImmutable::now();
        $out = [];
        $warn = function (string $kind, string $title, string $detail) use (&$out): void {
            $out[] = ['kind' => $kind, 'title' => $title, 'detail' => $detail];
        };

        $grants = self::lastRun(MutabilityCommand::HEALTH_KEY);
        if ($grants !== null && $grants['problems'] !== []) {
            $warn('grants', 'The database grants have changed', 'The nightly check found the database no longer matches its rules: '.$grants['problems'][0].' Call HAQ support.');
        }
        $audit = self::lastRun(VerifyCommand::HEALTH_KEY);
        if ($audit !== null && $audit['problems'] !== []) {
            $warn('audit', 'The audit trail does not verify', $audit['problems'][0].' Call HAQ support; nothing is deleted.');
        }

        $clock = $this->clock->state();
        if ($clock['state'] === 'unverified') {
            $warn('clock', 'The server\'s clock may be wrong', $clock['reason'] === 'before_build'
                ? 'It says '.CarbonImmutable::parse($clock['server_time'])->setTimezone($this->zone())->format('j F Y, H:i').', before this version was made. Selling goes on; sales are marked until the time is put right. Call HAQ support.'
                : 'It went back in time. Selling goes on; sales are marked until the owner confirms the time on the health page.');
        }

        if ($snapshot === null) {
            $warn('checks', 'The server\'s checks have not run', 'The scheduler that checks the backups and the server is not running. Restart the server; if this stays, call HAQ support.');

            return $out;
        }
        $checked = CarbonImmutable::parse(is_string($snapshot['checked_at'] ?? null) ? $snapshot['checked_at'] : '@0');
        if ($checked->lessThan($now->subMinutes(15))) {
            $warn('checks', 'The server\'s checks have stopped', 'They last ran '.$this->ago($checked, $now).'. Restart the server; if this stays, call HAQ support.');
        }

        /** @var array{last: ?array{label: string, type: string, at: string}, failed_at: ?string, restore: ?array{state: ?string, verified: ?bool, problems: list<string>}, last_verified_restore: ?string, count: int}|null $backups */
        $backups = is_array($snapshot['backups'] ?? null) ? $snapshot['backups'] : null;
        if ($backups === null) {
            $warn('backup', 'Backups are not set up on this server', 'There is no backup disk. Call HAQ support.');
        } else {
            $last = $backups['last'] === null ? null : CarbonImmutable::parse($backups['last']['at']);
            if ($last === null) {
                $warn('backup', 'There is no backup yet', 'The first backup runs at the next quiet moment, or now.');
            } elseif ($last->lessThan($now->subHours(config()->integer('health.backup_warn_hours')))) {
                $hours = (int) $last->diffInHours($now, true);
                $warn('backup', $hours < 72 ? "The last backup is {$hours} hours old" : 'The last backup is '.intdiv($hours, 24).' days old',
                    'The backup runs at night, or at the next quiet moment if the server was off. Leave the server on at night; it is on the UPS. It runs now if you ask.');
            }
            if ($backups['failed_at'] !== null) {
                $warn('backup', 'The last backup failed', 'It failed '.$this->ago(CarbonImmutable::parse($backups['failed_at']), $now).'. It tries again at the next quiet moment. If it fails again, call HAQ support.');
            }
            $restore = $backups['restore'];
            if ($restore !== null && $restore['verified'] === false) {
                $warn('restore', 'The weekly test restore failed', ($restore['problems'][0] ?? 'The copy could not be checked.').' Call HAQ support.');
            }
            $good = $backups['last_verified_restore'] === null ? null : CarbonImmutable::parse($backups['last_verified_restore']);
            if ($backups['count'] > 0 && $good !== null && $good->lessThan($now->subDays(config()->integer('health.restore_warn_days')))) {
                $warn('restore', 'No test restore for '.(int) $good->diffInDays($now, true).' days', 'It runs each week at a quiet moment. Leave the server on at night.');
            }
        }

        $wal = self::wal();
        if ($wal !== null && $wal['failed_at'] !== null && ($wal['archived_at'] === null || $wal['failed_at'] > $wal['archived_at'])) {
            $warn('wal', 'The continuous copy to the backup disk is failing', 'The database could not copy its latest changes to the second disk. Check the backup disk; call HAQ support.');
        }

        /** @var array<string, array{free: int, total: int}|null> $disks */
        $disks = is_array($snapshot['disks'] ?? null) ? $snapshot['disks'] : [];
        foreach (['main' => 'main disk', 'backup' => 'backup disk'] as $key => $name) {
            $disk = $disks[$key] ?? null;
            if ($disk !== null && $disk['total'] > 0 && ($disk['free'] * 100 < $disk['total'] * config()->integer('health.disk_warn_percent') || $disk['free'] < config()->integer('health.disk_warn_bytes'))) {
                $warn('disk', "The {$name} is nearly full", self::bytes($disk['free']).' free of '.self::bytes($disk['total']).'. Call HAQ support.');
            }
        }

        /** @var array{disks: list<array{device: string, model: ?string, passed: ?bool, life_left: ?int}>}|null $host */
        $host = is_array($snapshot['host'] ?? null) ? $snapshot['host'] : null;
        foreach ($host['disks'] ?? [] as $ssd) {
            if ($ssd['passed'] === false || ($ssd['life_left'] !== null && $ssd['life_left'] < config()->integer('health.ssd_warn_percent'))) {
                $warn('ssd', 'An SSD is wearing out', ($ssd['model'] ?? $ssd['device']).($ssd['passed'] === false ? ' failed its health check' : " has {$ssd['life_left']}% of its life left").'. Call HAQ support to replace it.');
            }
        }

        /** @var array{expires_at: string}|null $cert */
        $cert = is_array($snapshot['certificate'] ?? null) ? $snapshot['certificate'] : null;
        if ($cert !== null) {
            $expires = CarbonImmutable::parse($cert['expires_at']);
            if ($expires->lessThan($now->addDays(config()->integer('health.certificate_warn_days')))) {
                $warn('certificate', $expires->isPast() ? 'The server\'s certificate has expired' : 'The server\'s certificate expires in '.(int) $now->diffInDays($expires, true).' days',
                    'Counters will stop trusting the server when it expires. HAQ support renews it.');
            }
        }

        foreach ([[MutabilityCommand::HEALTH_KEY, 'grants', 'The database grants'], [VerifyCommand::HEALTH_KEY, 'audit', 'The audit chains']] as [$key, $kind, $what]) {
            // Never run yet (a new server) is not late: it runs at the first quiet moment.
            $run = self::lastRun($key);
            if ($run !== null && CarbonImmutable::parse($run['checked_at'])->lessThan($now->subHours(48))) {
                $warn($kind, "{$what} have not been checked for two days", 'The check runs each night, or at the next quiet moment if the server was off.');
            }
        }

        return $out;
    }

    /**
     * The dashboard's line when every check passes (wireframe BackDashboard); only when warnings() is empty.
     */
    public function allClear(): string
    {
        $snapshot = Snapshot::read();
        /** @var array{last?: array{at?: string}|null}|null $backups */
        $backups = is_array($snapshot['backups'] ?? null) ? $snapshot['backups'] : null;
        $last = $backups['last']['at'] ?? null;

        return 'System checks passed'.(is_string($snapshot['checked_at'] ?? null) ? ' at '.CarbonImmutable::parse($snapshot['checked_at'])->setTimezone($this->zone())->format('H:i') : '')
            .': last backup '.(is_string($last) ? CarbonImmutable::parse($last)->setTimezone($this->zone())->format('j M H:i') : 'none')
            .', clock verified, certificate valid, audit chains verified.';
    }

    /**
     * The continuous copy of WAL to the backup disk, from PostgreSQL's own archiver.
     *
     * @return array{archived_at: ?string, failed_at: ?string, count: int}|null
     */
    public static function wal(): ?array
    {
        /** @var object{archived_count: int|string, last_archived_time: ?string, last_failed_time: ?string}|null $row */
        $row = DB::selectOne('SELECT archived_count, last_archived_time, last_failed_time FROM pg_stat_archiver');
        if ($row === null) {
            return null;
        }

        return [
            'archived_at' => $row->last_archived_time === null ? null : CarbonImmutable::parse($row->last_archived_time)->toIso8601String(),
            'failed_at' => $row->last_failed_time === null ? null : CarbonImmutable::parse($row->last_failed_time)->toIso8601String(),
            'count' => (int) $row->archived_count,
        ];
    }

    /**
     * @return array{checked_at: string, problems: list<string>}|null
     */
    public static function lastRun(string $key): ?array
    {
        $run = Cache::get($key);
        if (! is_array($run) || ! is_string($run['checked_at'] ?? null)) {
            return null;
        }

        return ['checked_at' => $run['checked_at'], 'problems' => array_values(array_filter(is_array($run['problems'] ?? null) ? $run['problems'] : [], 'is_string'))];
    }

    private static function databaseBytes(): int
    {
        /** @var object{size: int|string} $row */
        $row = DB::selectOne('SELECT pg_database_size(current_database()) AS size');

        return (int) $row->size;
    }

    private function ago(CarbonImmutable $at, CarbonImmutable $now): string
    {
        return $at->setTimezone($this->zone())->format('j M').' at '.$at->setTimezone($this->zone())->format('H:i').($at->diffInHours($now, true) >= 1 ? ', '.(int) $at->diffInHours($now, true).' hours ago' : '');
    }

    private static function bytes(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3).' GB' : round($bytes / 1024 ** 2).' MB';
    }

    private function zone(): string
    {
        $zone = $this->settings->get('business.timezone');

        return is_string($zone) ? $zone : 'Africa/Lagos';
    }
}
