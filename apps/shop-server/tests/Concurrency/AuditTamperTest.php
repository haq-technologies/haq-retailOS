<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Audit\Hasher;
use App\Audit\Roots;
use App\Audit\Verifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness;
use Tests\Support\Shop;

/*
 * Tampering is caught (spec section 10) [F3, F4]. Someone with the owner's credentials can switch a
 * trigger off and rewrite a row, but the verifier still finds it. These tests commit, because the
 * owner's connection cannot see another connection's uncommitted rows.
 */

beforeEach(function () {
    Harness::freshDatabase();
    $this->shop = Shop::create();
    $log = app(AuditLog::class);
    foreach (range(1, 3) as $n) {
        $log->record(new AuditEntry(
            action: 'cost.changed',
            subjectType: 'item',
            after: ['change' => 'cost changed'],
            sensitiveBefore: ['unit_cost_kobo' => 100_000 * $n],
            sensitiveAfter: ['unit_cost_kobo' => 110_000 * $n],
        ));
    }
    app(Roots::class)->create();
});

function tamper(string $table, string $sql): void
{
    $owner = DB::connection('pgsql_owner');
    $owner->statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
    $owner->statement($sql);
    $owner->statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
}

it('verifies an untouched trail', function () {
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('catches a rewritten row', function () {
    tamper('audit_log', "UPDATE audit_log SET redacted_after = '{\"change\": \"nothing happened\"}' WHERE seq = 2");

    expect(app(Verifier::class)->problems())->toContain(
        'Chain branch:'.$this->shop['branch']->id.' entry 2 has been changed: its hash no longer matches.'
    );
});

it('catches a deleted row', function () {
    tamper('audit_sensitive', 'DELETE FROM audit_sensitive WHERE audit_id IN (SELECT id FROM audit_log WHERE seq = 2)');
    tamper('audit_log', 'DELETE FROM audit_log WHERE seq = 2');

    $problems = app(Verifier::class)->problems();
    expect($problems)->toContain('Chain branch:'.$this->shop['branch']->id.' jumps from 1 to 3: an entry is missing.');
    expect($problems)->toContain('Chain branch:'.$this->shop['branch']->id.' entry 3 does not link to the entry before it.');
});

it('catches a changed sensitive value, such as a cost', function () {
    tamper('audit_sensitive', "UPDATE audit_sensitive SET after = '{\"unit_cost_kobo\": 1}' WHERE audit_id IN (SELECT id FROM audit_log WHERE seq = 1)");

    expect(app(Verifier::class)->problems())->toContain(
        'Chain branch:'.$this->shop['branch']->id.' entry 1: its sensitive values are missing or changed.'
    );
});

it('catches a whole chain rewritten consistently, through the roots', function () {
    // Rewrite the last row and recompute its hash, so the chain alone still looks valid.
    $row = DB::table('audit_log')->where('seq', 3)->first();
    $fields = [
        'chain_id' => $row->chain_id, 'seq' => 3, 'branch_id' => $row->branch_id, 'terminal_id' => null,
        'actor_id' => null, 'device' => null, 'action' => 'cost.changed', 'subject_type' => 'item', 'subject_id' => null,
        'redacted_before' => null, 'redacted_after' => ['change' => 'nothing happened'], 'sensitive_hash' => $row->sensitive_hash,
        'spooled' => false, 'recorded_at' => Hasher::time(CarbonImmutable::parse($row->recorded_at)), 'prev_hash' => $row->prev_hash,
    ];
    $forged = Hasher::hash($fields);
    tamper('audit_log', "UPDATE audit_log SET redacted_after = '{\"change\": \"nothing happened\"}', hash = '{$forged}' WHERE seq = 3");

    $problems = app(Verifier::class)->problems();
    expect($problems)->toContain('Root 1 names a head of chain '.$row->chain_id.' that is no longer in the log.');
});
