<?php

declare(strict_types=1);

use App\Audit\Verifier;
use App\Organisation\BranchKey;
use App\Sales\Finish;
use App\Sales\Journal;
use App\Sales\JournalNeeded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Counter;

/*
 * The app's side of backups and restores (spec sections 4 and 9; checks in section 14 B) [F1, S14, F24].
 * pgBackRest itself is exercised by the restore drill (docs/operations/restore-drill.md).
 */

beforeEach(function () {
    $this->dir = storage_path('framework/testing/backups-'.Str::random(8));
    mkdir($this->dir, 0o777, true);
    config(['backups.status_dir' => $this->dir]);
    $this->counter = Counter::ready(items: 1);
    [$this->t1, $this->t2] = $this->counter['terminals'];
    [$this->a] = $this->counter['items'];
});

afterEach(function () {
    foreach (glob($this->dir.'/{,*/}*', GLOB_BRACE) ?: [] as $f) {
        is_file($f) && unlink($f);
    }
    @rmdir($this->dir.'/keys');
    @rmdir($this->dir);
});

it('starts a new signed epoch on every counter after a restore, numbering from 1,001, and never repeats one', function () {
    app(Finish::class)->finish($this->t1, $this->counter['cashier'], Counter::request(Counter::cashSale([$this->a->id => [1, 5000]])));
    expect(DB::table('sales')->value('receipt_number'))->toBe('T01-E1-000001');

    $this->artisan('shop:after-restore')->expectsOutputToContain('T01 now numbers receipts T01-E2-001001 onwards.')->assertSuccessful();
    $epoch = DB::table('epochs')->where('terminal_id', $this->t1->id)->orderByDesc('epoch')->first();
    expect($epoch)->epoch->toBe(2)->reason->toBe('restore');
    expect($epoch->signature)->not->toBe('');

    // The counter reports its journal before it sells again (D44); its journal shows nothing newer.
    expect(fn () => app(Finish::class)->finish($this->t1, $this->counter['cashier'], Counter::request(Counter::cashSale([$this->a->id => [1, 5000]]))))
        ->toThrow(JournalNeeded::class);
    app(Journal::class)->report($this->t1, ['highest' => ['1' => 1]]);
    $sale = app(Finish::class)->finish($this->t1, $this->counter['cashier'], Counter::request(Counter::cashSale([$this->a->id => [1, 5000]])));
    expect($sale['receipt_number'])->toBe('T01-E2-001001');

    // The epoch file on the backup disk knows a higher epoch than this copy: the next restore goes above both.
    file_put_contents($this->dir.'/epochs.json', json_encode(['epochs' => ['T01' => 7, 'T02' => 1]]));
    $this->artisan('shop:after-restore', ['--reason' => 'replacement'])->assertSuccessful();
    expect(DB::table('epochs')->where('terminal_id', $this->t1->id)->max('epoch'))->toBe(8);
    // T02 had sold nothing: its first restore made epoch 1, so this one is 2.
    expect(DB::table('epochs')->where('terminal_id', $this->t2->id)->max('epoch'))->toBe(2);
    expect(json_decode((string) file_get_contents($this->dir.'/epochs.json'), true)['epochs'])->toBe(['T01' => 8, 'T02' => 2]);
    expect(DB::table('audit_log')->where('action', 'backup.epochs_started')->count())->toBe(2);
});

it('keeps the branch key and the epochs on the backup disk, and brings the key back to a new machine', function () {
    app(Finish::class)->finish($this->t1, $this->counter['cashier'], Counter::request(Counter::cashSale([$this->a->id => [1, 5000]])));
    $this->artisan('backups:tick')->assertSuccessful();
    $key = app(BranchKey::class)->path();
    expect(file_get_contents($this->dir.'/keys/branch.key'))->toBe(file_get_contents($key));
    expect(json_decode((string) file_get_contents($this->dir.'/epochs.json'), true)['epochs'])->toBe(['T01' => 1]);

    // A spare machine has no key of its own yet; the restore brings the branch's key back.
    $original = (string) file_get_contents($key);
    unlink($key);
    $this->artisan('shop:after-restore')->expectsOutputToContain('The branch key came back from the backup disk.')->assertSuccessful();
    expect(file_get_contents($key))->toBe($original);
});

it('verifies a test restore the backup service made ready: row counts and every audit chain', function () {
    file_put_contents($this->dir.'/restore.json', json_encode(['state' => 'ready', 'label' => '20261108-010000F', 'port' => (int) config('database.connections.pgsql.port')]));
    config(['backups.restore_host' => config('database.connections.pgsql.host')]);

    $this->artisan('backups:tick')->expectsOutput('The test restore of 20261108-010000F verified.')->assertSuccessful();
    $result = json_decode((string) file_get_contents($this->dir.'/verified-20261108-010000F.json'), true);
    expect($result['ok'])->toBeTrue()->and($result['counts'])->toHaveKeys(['sales', 'audit_log', 'epochs']);
    expect(DB::table('audit_log')->where('action', 'backup.restore_verified')->count())->toBe(1);
    // Its audit entry verifies like every other (a label is not a ULID, so it is a value, not the subject).
    expect(app(Verifier::class)->problems())->toBe([]);

    // Once verified, the same copy is not checked again.
    $this->artisan('backups:tick')->doesntExpectOutputToContain('verified')->assertSuccessful();
});

it('records a test restore it cannot check as failed, for the owner to see', function () {
    file_put_contents($this->dir.'/restore.json', json_encode(['state' => 'ready', 'label' => '20261108-010000F', 'port' => 1]));
    config(['backups.restore_host' => '127.0.0.1']);

    $this->artisan('backups:tick')->assertFailed();
    $result = json_decode((string) file_get_contents($this->dir.'/verified-20261108-010000F.json'), true);
    expect($result['ok'])->toBeFalse()->and($result['problems'][0])->toContain('could not be checked');
    expect(DB::table('audit_log')->where('action', 'backup.restore_failed')->count())->toBe(1);
    expect(app(Verifier::class)->problems())->toBe([]);
});

it('does nothing on a machine without the backup service', function () {
    config(['backups.status_dir' => null]);
    $this->artisan('backups:tick')->assertSuccessful();
    $this->artisan('backups:status')->assertFailed();
});
