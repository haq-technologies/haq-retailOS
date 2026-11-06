<?php

declare(strict_types=1);

namespace App\Audit;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The local spool for failure entries that could not reach the database in time (spec section 10) [F4].
 * One JSON line per entry, each hash-linked to the one before it, so a spool edited by hand is caught
 * at ingestion. Writers and the ingester share an exclusive file lock.
 */
final class Spool
{
    public function __construct(private readonly string $path) {}

    public function append(AuditEntry $entry): void
    {
        $this->locked(function ($handle) use ($entry): void {
            $prev = $this->lastHash($handle);
            $data = $entry->toArray();
            $line = ['entry' => $data, 'prev' => $prev, 'hash' => hash('sha256', $prev.Hasher::canonical($data))];
            fseek($handle, 0, SEEK_END);
            fwrite($handle, Hasher::canonical($line)."\n");
            fflush($handle);
        });
    }

    /**
     * Moves every spooled entry into the audit trail, in order, in one transaction, then empties the
     * spool. Returns how many were ingested. A broken link stops ingestion and keeps the spool intact.
     */
    public function ingest(AuditLog $log): int
    {
        return $this->locked(function ($handle) use ($log): int {
            $entries = $this->read($handle);
            if ($entries === []) {
                return 0;
            }

            DB::transaction(function () use ($entries, $log): void {
                foreach ($entries as $entry) {
                    $log->record($entry, spooled: true);
                }
            });

            ftruncate($handle, 0);
            fflush($handle);

            return count($entries);
        });
    }

    public function count(): int
    {
        return $this->locked(fn ($handle): int => count($this->read($handle)));
    }

    /**
     * @param  resource  $handle
     * @return list<AuditEntry>
     */
    private function read($handle): array
    {
        rewind($handle);
        $entries = [];
        $prev = Hasher::GENESIS;
        $number = 0;
        while (($raw = fgets($handle)) !== false) {
            $number++;
            if (trim($raw) === '') {
                continue;
            }
            /** @var array{entry: array<string, mixed>, prev: string, hash: string} $line */
            $line = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if ($line['prev'] !== $prev || $line['hash'] !== hash('sha256', $prev.Hasher::canonical($line['entry']))) {
                throw new RuntimeException("The audit spool is broken at line {$number}: its hash link does not match. Nothing was ingested.");
            }
            $entries[] = AuditEntry::fromArray($line['entry']);
            $prev = $line['hash'];
        }

        return $entries;
    }

    /**
     * @param  resource  $handle
     */
    private function lastHash($handle): string
    {
        $entries = $this->read($handle);
        if ($entries === []) {
            return Hasher::GENESIS;
        }
        rewind($handle);
        $last = Hasher::GENESIS;
        while (($raw = fgets($handle)) !== false) {
            if (trim($raw) !== '') {
                /** @var array{hash: string} $line */
                $line = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                $last = $line['hash'];
            }
        }

        return $last;
    }

    /**
     * @template T
     *
     * @param  callable(resource): T  $callback
     * @return T
     */
    private function locked(callable $callback): mixed
    {
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the audit spool directory {$directory}.");
        }
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Cannot open the audit spool {$this->path}.");
        }
        try {
            flock($handle, LOCK_EX);

            return $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
