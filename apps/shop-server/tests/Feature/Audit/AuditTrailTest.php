<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Audit\Hasher;
use App\Audit\Roots;
use App\Audit\Spool;
use App\Audit\Verifier;
use App\Identity\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Support\People;
use Tests\Support\Shop;

/*
 * The audit trail (spec section 10) [F4, F10]. Concurrency and tampering are tested in the multi-process
 * suite (tests/Concurrency); these tests check the shape of what is written.
 */

beforeEach(function () {
    $this->spool = storage_path('framework/testing/audit-spool-'.getmypid().'.jsonl');
    @unlink($this->spool);
    app()->instance(Spool::class, new Spool($this->spool));
});

afterEach(fn () => @unlink($this->spool));

it('puts terminal actions on the terminal chain and the rest on the branch chain', function () {
    $shop = Shop::create();
    [$t1] = $shop['terminals'];
    $log = app(AuditLog::class);

    $log->record(new AuditEntry(action: 'pin.signed_in', terminalId: $t1->id, actorId: People::add('Amina', Role::Cashier)->id));
    $log->record(new AuditEntry(action: 'settings.changed'));
    $log->record(new AuditEntry(action: 'pin.signed_out', terminalId: $t1->id));

    $rows = DB::table('audit_log')->orderBy('chain_id')->orderBy('seq')->get();
    expect($rows->pluck('chain_id')->unique()->sort()->values()->all())->toBe(["branch:{$shop['branch']->id}", "terminal:{$t1->id}"]);

    $terminal = $rows->where('chain_id', "terminal:{$t1->id}")->values();
    expect($terminal->pluck('seq')->map(fn ($s) => (int) $s)->all())->toBe([1, 2]);
    expect($terminal[0]->prev_hash)->toBe(Hasher::GENESIS);
    expect($terminal[1]->prev_hash)->toBe($terminal[0]->hash);
    expect($terminal[0]->device)->toBe('T01');

    expect(app(Verifier::class)->problems())->toBe([]);
});

it('keeps sensitive values out of the main row, committed to it by a hash', function () {
    Shop::create();

    app(AuditLog::class)->record(new AuditEntry(
        action: 'cost.changed',
        before: ['change' => 'cost changed'],
        after: ['change' => 'cost changed'],
        sensitiveBefore: ['unit_cost_kobo' => 150_000],
        sensitiveAfter: ['unit_cost_kobo' => 175_000],
    ));

    $row = DB::table('audit_log')->first();
    expect($row->redacted_after)->not->toContain('175000');
    expect($row->sensitive_hash)->toBe(Hasher::hash(['before' => ['unit_cost_kobo' => 150_000], 'after' => ['unit_cost_kobo' => 175_000]]));

    $sensitive = DB::table('audit_sensitive')->where('audit_id', $row->id)->first();
    expect(json_decode($sensitive->after, true))->toBe(['unit_cost_kobo' => 175_000]);
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('rolls an entry back with the action it describes', function () {
    Shop::create();

    try {
        DB::transaction(function () {
            app(AuditLog::class)->record(new AuditEntry(action: 'sale.finished'));
            throw new RuntimeException('power cut');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('audit_log')->count())->toBe(0);
});

it('links each root to the one before it and names every chain head', function () {
    $shop = Shop::create();
    $log = app(AuditLog::class);
    $log->record(new AuditEntry(action: 'a'));
    $first = app(Roots::class)->create();
    $log->record(new AuditEntry(action: 'b', terminalId: $shop['terminals'][0]->id));
    $second = app(Roots::class)->create('daily_summary');

    $roots = DB::table('audit_roots')->orderBy('seq')->get();
    expect($roots[0]->prev_root)->toBe(Hasher::GENESIS);
    expect($roots[1]->prev_root)->toBe($first['hash']);
    expect(array_keys(json_decode($roots[1]->heads, true)))->toHaveCount(2);
    expect(app(Roots::class)->latestHash())->toBe($second['hash']);
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('refuses to record before the shop profile gives the server its branch', function () {
    app(AuditLog::class)->record(new AuditEntry(action: 'anything'));
})->throws(ModelNotFoundException::class);

it('hash-links the spool and refuses a spool edited by hand', function () {
    Shop::create();
    $spool = app(Spool::class);
    $spool->append(new AuditEntry(action: 'sale.failed', after: ['n' => 1]));
    $spool->append(new AuditEntry(action: 'sale.failed', after: ['n' => 2]));
    expect($spool->count())->toBe(2);

    file_put_contents($this->spool, str_replace('"n":2', '"n":3', (string) file_get_contents($this->spool)));

    expect(fn () => $spool->ingest(app(AuditLog::class)))->toThrow(RuntimeException::class, 'The audit spool is broken at line 2');
    expect(DB::table('audit_log')->count())->toBe(0);
});

it('ingests the spool in order onto the branch chain, marked as spooled', function () {
    Shop::create();
    $spool = app(Spool::class);
    $spool->append(new AuditEntry(action: 'sale.failed', after: ['n' => 1], recordedAt: now()->toImmutable()->subMinute()));
    $spool->append(new AuditEntry(action: 'sale.failed', after: ['n' => 2]));

    expect($spool->ingest(app(AuditLog::class)))->toBe(2);

    $rows = DB::table('audit_log')->orderBy('seq')->get();
    expect($rows->pluck('spooled')->map(fn ($v) => (bool) $v)->all())->toBe([true, true]);
    expect(json_decode($rows[0]->redacted_after, true))->toBe(['n' => 1]);
    expect($spool->count())->toBe(0);
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('refuses an audit subject that is not a ULID, which would come back padded and break its hash', function () {
    Shop::create();
    expect(fn () => app(AuditLog::class)->record(new AuditEntry(action: 'test.subject', subjectType: 'backup', subjectId: '20261106-215828F')))
        ->toThrow(InvalidArgumentException::class, 'must be a ULID');
    expect(DB::table('audit_log')->where('action', 'test.subject')->count())->toBe(0);
});
