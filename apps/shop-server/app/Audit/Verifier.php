<?php

declare(strict_types=1);

namespace App\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Verifies the audit trail end to end (spec sections 4 and 10) [F4]: every chain's sequence is gapless
 * from 1, every row links to the one before it and hashes to its stored hash, every sensitive row
 * matches the hash its main row carries, and every root links to the previous one and names heads that
 * exist. The weekly test restore runs it on the restored copy.
 */
final class Verifier
{
    /**
     * @return list<string> One plain sentence per problem; empty when everything verifies.
     */
    public function problems(): array
    {
        return [...$this->chainProblems(), ...$this->rootProblems()];
    }

    /**
     * @return list<string>
     */
    private function chainProblems(): array
    {
        $problems = [];
        $sensitive = $this->sensitiveHashes();
        $state = [];

        // A cursor streams the rows, so verifying a large log stays flat in memory.
        /** @var iterable<object{id: string, chain_id: string, seq: int, branch_id: string, terminal_id: ?string, actor_id: ?string, device: ?string, action: string, subject_type: ?string, subject_id: ?string, redacted_before: ?string, redacted_after: ?string, sensitive_hash: ?string, spooled: bool, recorded_at: string, prev_hash: string, hash: string}> $rows */
        $rows = DB::cursor('SELECT * FROM audit_log ORDER BY chain_id, seq');
        foreach ($rows as $r) {
            $seq = (int) $r->seq;
            [$expectedSeq, $expectedPrev] = $state[$r->chain_id] ?? [1, Hasher::GENESIS];

            if ($seq !== $expectedSeq) {
                $problems[] = "Chain {$r->chain_id} jumps from {$this->previous($expectedSeq)} to {$seq}: an entry is missing.";
            }
            if ($r->prev_hash !== $expectedPrev) {
                $problems[] = "Chain {$r->chain_id} entry {$seq} does not link to the entry before it.";
            }

            $recomputed = Hasher::hash([
                'chain_id' => $r->chain_id,
                'seq' => $seq,
                'branch_id' => $r->branch_id,
                'terminal_id' => $r->terminal_id,
                'actor_id' => $r->actor_id,
                'device' => $r->device,
                'action' => $r->action,
                'subject_type' => $r->subject_type,
                'subject_id' => $r->subject_id,
                'redacted_before' => $this->decode($r->redacted_before),
                'redacted_after' => $this->decode($r->redacted_after),
                'sensitive_hash' => $r->sensitive_hash,
                'spooled' => (bool) $r->spooled,
                'recorded_at' => Hasher::time(CarbonImmutable::parse($r->recorded_at)),
                'prev_hash' => $r->prev_hash,
            ]);
            if ($recomputed !== $r->hash) {
                $problems[] = "Chain {$r->chain_id} entry {$seq} has been changed: its hash no longer matches.";
            }

            if ($r->sensitive_hash !== null && ($sensitive[$r->id] ?? null) !== $r->sensitive_hash) {
                $problems[] = "Chain {$r->chain_id} entry {$seq}: its sensitive values are missing or changed.";
            }

            $state[$r->chain_id] = [$seq + 1, $r->hash];
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function rootProblems(): array
    {
        $problems = [];
        $expectedSeq = 1;
        $expectedPrev = Hasher::GENESIS;

        /** @var list<object{seq: int, heads: string, reason: string, recorded_at: string, prev_root: string, hash: string}> $roots */
        $roots = DB::select('SELECT seq, heads, reason, recorded_at, prev_root, hash FROM audit_roots ORDER BY seq');
        foreach ($roots as $root) {
            $seq = (int) $root->seq;
            if ($seq !== $expectedSeq) {
                $problems[] = "Root {$seq} follows root {$this->previous($expectedSeq)}: a root is missing.";
            }
            if ($root->prev_root !== $expectedPrev) {
                $problems[] = "Root {$seq} does not link to the root before it.";
            }

            $heads = $this->decode($root->heads) ?? [];
            $recomputed = Hasher::hash([
                'seq' => $seq,
                'heads' => $heads,
                'reason' => $root->reason,
                'recorded_at' => Hasher::time(CarbonImmutable::parse($root->recorded_at)),
                'prev_root' => $root->prev_root,
            ]);
            if ($recomputed !== $root->hash) {
                $problems[] = "Root {$seq} has been changed: its hash no longer matches.";
            }

            foreach ($heads as $chain => $head) {
                /** @var array{seq: int, hash: string} $head */
                $exists = DB::table('audit_log')->where('chain_id', $chain)->where('seq', $head['seq'])->where('hash', $head['hash'])->exists();
                if (! $exists) {
                    $problems[] = "Root {$seq} names a head of chain {$chain} that is no longer in the log.";
                }
            }

            $expectedSeq = $seq + 1;
            $expectedPrev = $root->hash;
        }

        return $problems;
    }

    /**
     * @return array<string, string> audit ID => hash of its sensitive values
     */
    private function sensitiveHashes(): array
    {
        $hashes = [];
        /** @var list<object{audit_id: string, before: ?string, after: ?string}> $rows */
        $rows = DB::select('SELECT audit_id, before, after FROM audit_sensitive');
        foreach ($rows as $row) {
            $hashes[$row->audit_id] = Hasher::hash(['before' => $this->decode($row->before), 'after' => $this->decode($row->after)]);
        }

        return $hashes;
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($value) ? $value : null;
    }

    private function previous(int $expected): string
    {
        return (string) ($expected - 1);
    }
}
