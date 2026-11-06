<?php

declare(strict_types=1);

namespace App\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Audit roots (spec section 10) [F4]: every minute, and at every Z-report and daily summary, a root hashes
 * the head of every chain and the previous root, so the set of chains is itself tamper-evident. The
 * latest root's hash is printed on the daily summary and the Z-report.
 */
final class Roots
{
    public const REASONS = ['minute', 'z_report', 'daily_summary', 'restore'];

    /**
     * @return array{seq: int, hash: string}
     */
    public function create(string $reason = 'minute'): array
    {
        return DB::transaction(function () use ($reason): array {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('audit_roots', 0))");

            $heads = [];
            /** @var list<object{chain_id: string, seq: int, hash: string}> $rows */
            $rows = DB::select('SELECT DISTINCT ON (chain_id) chain_id, seq, hash FROM audit_log ORDER BY chain_id, seq DESC');
            foreach ($rows as $row) {
                $heads[$row->chain_id] = ['seq' => (int) $row->seq, 'hash' => $row->hash];
            }

            /** @var object{seq: int, hash: string}|null $last */
            $last = DB::selectOne('SELECT seq, hash FROM audit_roots ORDER BY seq DESC LIMIT 1');

            $root = [
                'seq' => $last === null ? 1 : (int) $last->seq + 1,
                'heads' => $heads,
                'reason' => $reason,
                'recorded_at' => Hasher::time(CarbonImmutable::now()),
                'prev_root' => $last === null ? Hasher::GENESIS : $last->hash,
            ];
            $root['hash'] = Hasher::hash($root);

            DB::table('audit_roots')->insert([
                'id' => (string) Str::ulid(),
                ...$root,
                'heads' => Hasher::canonical($heads === [] ? new \stdClass : $heads),
            ]);

            return ['seq' => $root['seq'], 'hash' => $root['hash']];
        });
    }

    /** The latest root's hash, for the daily summary and the Z-report. */
    public function latestHash(): ?string
    {
        /** @var object{hash: string}|null $row */
        $row = DB::selectOne('SELECT hash FROM audit_roots ORDER BY seq DESC LIMIT 1');

        return $row?->hash;
    }
}
