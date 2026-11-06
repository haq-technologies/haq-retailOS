<?php

declare(strict_types=1);

use App\Audit\AuditLog;
use App\Audit\Spool;
use App\Audit\Verifier;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness;
use Tests\Concurrency\Scenarios\AppendAuditEntries;
use Tests\Concurrency\Scenarios\HoldBranchChain;
use Tests\Concurrency\Scenarios\MakeRoots;
use Tests\Concurrency\Scenarios\RecordFailureWhileBusy;
use Tests\Support\Shop;

/*
 * Audit chains under real concurrency (spec sections 10 and 14) [F4, F6]: separate processes, committed
 * transactions, a real PostgreSQL.
 */

beforeEach(function () {
    Harness::freshDatabase();
    @unlink(config()->string('audit.spool'));
});

it('keeps every chain gapless and valid while eight workers append at once', function () {
    $shop = Shop::create(terminals: 2);
    [$t1, $t2] = $shop['terminals'];

    $workers = [];
    foreach ([$t1->id, $t1->id, $t1->id, $t2->id, $t2->id, $t2->id, null, null] as $terminal) {
        $workers[] = [AppendAuditEntries::class, ['terminal' => $terminal, 'count' => 25]];
    }
    $workers[] = [MakeRoots::class, ['count' => 5]];

    Harness::run($workers);

    expect(DB::table('audit_log')->count())->toBe(200);
    foreach (["terminal:{$t1->id}", "terminal:{$t2->id}", "branch:{$shop['branch']->id}"] as $chain) {
        $seqs = DB::table('audit_log')->where('chain_id', $chain)->orderBy('seq')->pluck('seq')->map(fn ($s) => (int) $s)->all();
        $expected = $chain === "branch:{$shop['branch']->id}" ? 50 : 75;
        expect($seqs)->toBe(range(1, $expected));
    }
    expect(DB::table('audit_sensitive')->count())->toBe(40);
    expect(DB::table('audit_roots')->count())->toBe(5);

    expect(app(Verifier::class)->problems())->toBe([]);
});

it('spools a failure instead of waiting when its chain is busy, then ingests it into a valid chain', function () {
    Shop::create();

    [, $failure] = Harness::run([
        [HoldBranchChain::class, ['hold_ms' => 1500]],
        [RecordFailureWhileBusy::class],
    ]);

    // The failure gave up after the 200 ms lock timeout and went to the spool, never blocking.
    expect($failure['spooled'])->toBeTrue();
    expect($failure['ms'])->toBeLessThan(1000);
    expect(app(Spool::class)->count())->toBe(1);

    expect(app(Spool::class)->ingest(app(AuditLog::class)))->toBe(1);
    expect(app(Spool::class)->count())->toBe(0);

    $failed = DB::table('audit_log')->where('action', 'sale.failed')->first();
    expect($failed)->not->toBeNull();
    expect((bool) $failed->spooled)->toBeTrue();
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('logs a failure directly when its chain is free', function () {
    Shop::create();

    [$failure] = Harness::run([[RecordFailureWhileBusy::class]]);

    expect($failure['spooled'])->toBeFalse();
    expect(DB::table('audit_log')->where('action', 'sale.failed')->count())->toBe(1);
    expect(app(Verifier::class)->problems())->toBe([]);
});
