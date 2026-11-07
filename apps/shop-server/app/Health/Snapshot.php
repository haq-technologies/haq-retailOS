<?php

declare(strict_types=1);

namespace App\Health;

use App\Backups\Status;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * What only the scheduler can see, collected every minute for the health page (spec section 4) [F24, D45]:
 * the backup disk's status files, both disks' free space, the HTTPS certificate's expiry, and the host's
 * own report of its SSDs and its time source (deploy/host-health.sh). The app's container has none of
 * these mounted, so the page reads this snapshot.
 *
 * @phpstan-type Disk array{free: int, total: int}
 * @phpstan-type Ssd array{device: string, model: ?string, passed: ?bool, life_left: ?int}
 */
final class Snapshot
{
    public const KEY = 'health.snapshot';

    /** Set by the back office's Back up now; the scheduler passes it to the backup service. */
    public const BACKUP_REQUEST = 'health.backup_requested';

    /**
     * @return array<string, mixed>|null
     */
    public static function read(): ?array
    {
        $snapshot = Cache::get(self::KEY);

        /** @var array<string, mixed>|null */
        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        $status = Status::fromConfig();

        return [
            'checked_at' => CarbonImmutable::now()->toIso8601String(),
            'backups' => $status->enabled() ? self::backups($status) : null,
            'disks' => [
                'main' => self::disk(storage_path()),
                'backup' => $status->enabled() && $status->directory !== null ? self::disk(dirname($status->directory)) : null,
            ],
            'certificate' => self::certificate(config()->string('health.certificate')),
            'host' => $status->enabled() ? self::host($status->read('host.json')) : null,
        ];
    }

    /** Passes a Back up now from the back office to the backup service, which watches for the file. */
    public static function forwardBackupRequest(): bool
    {
        $status = Status::fromConfig();
        $requested = Cache::get(self::BACKUP_REQUEST);
        if (! is_string($requested) || ! $status->enabled()) {
            return false;
        }
        $status->write('backup-now.json', ['requested_at' => $requested]);
        Cache::forget(self::BACKUP_REQUEST);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function backups(Status $status): array
    {
        $file = $status->read('backup.json');
        $list = array_values(array_filter(is_array($file['backups'] ?? null) ? $file['backups'] : [], fn (mixed $b) => is_array($b) && is_int($b['stop'] ?? null)));
        usort($list, fn (array $a, array $b) => $a['stop'] <=> $b['stop']);
        $last = end($list);
        $fulls = array_filter($list, fn (array $b) => ($b['type'] ?? null) === 'full');
        $lastFull = end($fulls);
        $failed = $status->read('backup-failed.json');
        $failedAt = is_int($failed['failed_at'] ?? null) ? $failed['failed_at'] : null;
        $lastStop = is_array($last) ? (int) $last['stop'] : 0;

        $restore = $status->read('restore.json');
        $label = is_string($restore['label'] ?? null) ? $restore['label'] : null;
        $verified = $label !== null && preg_match('/^[0-9A-Za-z_-]+$/', $label) === 1 ? $status->read("verified-{$label}.json") : null;
        $lastGood = is_file($status->path('last-verified-restore')) ? (int) trim((string) file_get_contents($status->path('last-verified-restore'))) : 0;

        return [
            'last' => is_array($last) ? ['label' => is_string($last['label'] ?? null) ? $last['label'] : '', 'type' => is_string($last['type'] ?? null) ? $last['type'] : '', 'at' => self::iso($lastStop)] : null,
            'last_full' => is_array($lastFull) ? self::iso((int) $lastFull['stop']) : null,
            'count' => count($list),
            'failed_at' => $failedAt !== null && $failedAt > $lastStop ? self::iso($failedAt) : null,
            'restore' => $restore === null ? null : [
                'state' => is_string($restore['state'] ?? null) ? $restore['state'] : null,
                'label' => $label,
                'verified' => is_bool($restore['verified'] ?? null) ? $restore['verified'] : null,
                'finished_at' => is_int($restore['finished_at'] ?? null) ? self::iso($restore['finished_at']) : null,
                'problems' => is_array($verified['problems'] ?? null) ? array_values(array_filter($verified['problems'], 'is_string')) : [],
            ],
            'last_verified_restore' => $lastGood > 0 ? self::iso($lastGood) : null,
            'backup_now' => self::asked($status),
        ];
    }

    /** A Back up now the backup service has not answered yet. */
    private static function asked(Status $status): bool
    {
        $asked = $status->read('backup-now.json');
        $done = is_file($status->path('backup-now-done')) ? trim((string) file_get_contents($status->path('backup-now-done'))) : '';

        return is_string($asked['requested_at'] ?? null) && $asked['requested_at'] !== $done;
    }

    /**
     * @return array{free: int, total: int}|null
     */
    private static function disk(string $path): ?array
    {
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        return $free === false || $total === false ? null : ['free' => (int) $free, 'total' => (int) $total];
    }

    /**
     * @return array{expires_at: string, subject: ?string}|null
     */
    private static function certificate(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $cert = openssl_x509_parse((string) file_get_contents($path));
        if (! is_array($cert) || ! is_int($cert['validTo_time_t'] ?? null)) {
            return null;
        }
        $subject = $cert['subject']['CN'] ?? null;

        return ['expires_at' => self::iso($cert['validTo_time_t']), 'subject' => is_string($subject) ? $subject : null];
    }

    /**
     * The host's own report (deploy/host-health.sh, every 15 minutes): its SSDs and its time source.
     *
     * @param  array<string, mixed>|null  $file
     * @return array<string, mixed>|null
     */
    private static function host(?array $file): ?array
    {
        if ($file === null) {
            return null;
        }
        $ssds = [];
        foreach (is_array($file['disks'] ?? null) ? $file['disks'] : [] as $d) {
            if (is_array($d) && is_string($d['device'] ?? null)) {
                $ssds[] = [
                    'device' => $d['device'], 'model' => is_string($d['model'] ?? null) ? $d['model'] : null,
                    'passed' => is_bool($d['passed'] ?? null) ? $d['passed'] : null,
                    'life_left' => is_int($d['life_left'] ?? null) ? $d['life_left'] : null,
                ];
            }
        }

        return [
            'written_at' => is_int($file['written_at'] ?? null) ? self::iso($file['written_at']) : null,
            'disks' => $ssds,
            'smartctl' => ($file['smartctl'] ?? null) === true,
            'time_synced' => is_bool($file['time_synced'] ?? null) ? $file['time_synced'] : null,
        ];
    }

    private static function iso(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestamp($timestamp)->toIso8601String();
    }
}
