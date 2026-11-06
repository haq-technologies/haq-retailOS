<?php

declare(strict_types=1);

namespace App\Audit;

use App\Organisation\Models\Branch;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Writes the audit trail (spec section 10) [F4, F10, S19].
 *
 * Each terminal has its own hash chain and each branch one more for actions that belong to no terminal.
 * An entry takes a lock only on its own chain's tail, held until the surrounding transaction commits,
 * so terminals never wait on each other. A failure is logged after its transaction has rolled back, on
 * a separate connection with a 200 ms lock timeout; if the lock cannot be had, the entry goes to the
 * hash-linked spool and is ingested later, so logging a failure can never block the counter.
 */
final class AuditLog
{
    /** The separate connection failures are logged on. */
    private const FAILURE_CONNECTION = 'pgsql_audit';

    public function __construct(private readonly Spool $spool) {}

    /**
     * Records an entry inside the current transaction (or its own, if there is none), so the entry
     * commits or rolls back with the action it describes. Returns the entry's ID.
     */
    public function record(AuditEntry $entry, ?Connection $db = null, bool $spooled = false): string
    {
        $db ??= DB::connection();

        return $db->transactionLevel() > 0
            ? $this->write($db, $entry, $spooled)
            : $db->transaction(fn () => $this->write($db, $entry, $spooled));
    }

    /**
     * Records a failed attempt after its transaction has rolled back. Never throws: a busy chain, an
     * unreachable database or anything else sends the entry to the spool instead, so logging a failure
     * can never hide the failure or block the counter. Returns the entry's ID, or null if spooled.
     */
    public function recordFailure(AuditEntry $entry): ?string
    {
        $entry = $entry->recordedAt === null ? $this->stamped($entry) : $entry;

        try {
            $db = $this->failureConnection();

            return $db->transaction(function () use ($db, $entry) {
                $db->statement("SET LOCAL lock_timeout = '200ms'");

                return $this->write($db, $entry, false);
            });
        } catch (\Throwable $e) {
            if (! $e instanceof QueryException || ! $this->isBusyOrUnreachable($e)) {
                report($e);
            }
            $this->spool->append($entry);

            return null;
        }
    }

    private function write(Connection $db, AuditEntry $entry, bool $spooled): string
    {
        // subject_id is a ULID column, char(26): anything shorter would come back padded with spaces and
        // no longer match its hash, so the chain would look tampered with. Refuse it here instead.
        if ($entry->subjectId !== null && preg_match('/^[0-9a-z]{26}$/i', $entry->subjectId) !== 1) {
            throw new InvalidArgumentException("An audit subject must be a ULID, not \"{$entry->subjectId}\".");
        }

        $terminal = $entry->terminalId === null ? null : Terminal::on($db->getName())->findOrFail($entry->terminalId);
        $branchId = $terminal !== null
            ? $terminal->branch_id
            : (string) Branch::on($db->getName())->where('is_local', true)->firstOrFail()->id;
        $chain = $terminal !== null ? "terminal:{$terminal->id}" : "branch:{$branchId}";

        // The chain-tail lock, held to commit [S19].
        $db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$chain]);

        /** @var object{seq: int, hash: string}|null $tail */
        $tail = $db->selectOne('SELECT seq, hash FROM audit_log WHERE chain_id = ? ORDER BY seq DESC LIMIT 1', [$chain]);

        $sensitiveHash = $entry->hasSensitive()
            ? Hasher::hash(['before' => $entry->sensitiveBefore, 'after' => $entry->sensitiveAfter])
            : null;

        $row = [
            'chain_id' => $chain,
            'seq' => $tail === null ? 1 : (int) $tail->seq + 1,
            'branch_id' => $branchId,
            'terminal_id' => $terminal?->id,
            'actor_id' => $entry->actorId,
            'device' => $entry->device ?? $terminal?->code,
            'action' => $entry->action,
            'subject_type' => $entry->subjectType,
            'subject_id' => $entry->subjectId,
            'redacted_before' => $entry->before,
            'redacted_after' => $entry->after,
            'sensitive_hash' => $sensitiveHash,
            'spooled' => $spooled,
            'recorded_at' => Hasher::time($entry->recordedAt ?? CarbonImmutable::now()),
            'prev_hash' => $tail === null ? Hasher::GENESIS : $tail->hash,
        ];
        $row['hash'] = Hasher::hash($row);

        $id = Ulid::generate();
        $db->table('audit_log')->insert([
            'id' => $id,
            ...$row,
            'redacted_before' => $row['redacted_before'] === null ? null : Hasher::canonical($row['redacted_before']),
            'redacted_after' => $row['redacted_after'] === null ? null : Hasher::canonical($row['redacted_after']),
        ]);

        if ($sensitiveHash !== null) {
            $db->table('audit_sensitive')->insert([
                'id' => Ulid::generate(),
                'audit_id' => $id,
                'before' => $entry->sensitiveBefore === null ? null : Hasher::canonical($entry->sensitiveBefore),
                'after' => $entry->sensitiveAfter === null ? null : Hasher::canonical($entry->sensitiveAfter),
            ]);
        }

        return $id;
    }

    private function stamped(AuditEntry $entry): AuditEntry
    {
        return AuditEntry::fromArray([...$entry->toArray(), 'recorded_at' => Hasher::time(CarbonImmutable::now())]);
    }

    private function failureConnection(): Connection
    {
        if (config('database.connections.'.self::FAILURE_CONNECTION) === null) {
            config(['database.connections.'.self::FAILURE_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        }

        return DB::connection(self::FAILURE_CONNECTION);
    }

    private function isBusyOrUnreachable(QueryException $e): bool
    {
        $state = (string) $e->getCode();

        // 55P03: lock not available (the lock timeout); 57014: statement cancelled; 08xxx: connection failures.
        return in_array($state, ['55P03', '57014'], true) || str_starts_with($state, '08');
    }
}
