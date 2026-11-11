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
    // This machine's own certificate authority and server certificate, as the certificate job makes them.
    config(['organisation.ca_dir' => $this->dir.'/machine-ca']);
    $this->tls = $this->dir.'/machine-tls';
    $this->counter = Counter::ready(items: 1);
    [$this->t1, $this->t2] = $this->counter['terminals'];
    [$this->a] = $this->counter['items'];
});

afterEach(function () {
    foreach (glob($this->dir.'/{,*/}*', GLOB_BRACE) ?: [] as $f) {
        is_file($f) && unlink($f);
    }
    foreach (['keys', 'machine-ca', 'machine-tls'] as $sub) {
        @rmdir($this->dir.'/'.$sub);
    }
    @rmdir($this->dir);
});

it('starts a new signed epoch on every counter after a restore, numbering from 1,001, and never repeats one', function () {
    app(Finish::class)->finish($this->t1, $this->counter['cashier'], Counter::request(Counter::cashSale([$this->a->id => [1, 5000]])));
    expect(DB::table('sales')->value('receipt_number'))->toBe('T01-E1-000001');

    // This backup disk holds no keys yet (the scheduler never ran here): the restore says what that costs.
    $this->artisan('shop:after-restore')->expectsOutputToContain('T01 now numbers receipts T01-E2-001001 onwards.')
        ->expectsOutputToContain('There is no certificate authority on the backup disk')->assertSuccessful();
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

function machineCertificate(): void
{
    test()->artisan('shop:make-certificate', ['--name' => 'haq-server.local', '--ip' => '192.168.1.10', '--tls-dir' => test()->tls])->assertSuccessful();
}

it('keeps the branch key, the shop\'s authority, the app key and the epochs on the backup disk, and brings them back to a new machine (D69)', function () {
    app(Finish::class)->finish($this->t1, $this->counter['cashier'], Counter::request(Counter::cashSale([$this->a->id => [1, 5000]])));
    machineCertificate();
    $this->artisan('backups:tick')->assertSuccessful();
    $key = app(BranchKey::class)->path();
    $ca = $this->dir.'/machine-ca';
    expect(file_get_contents($this->dir.'/keys/branch.key'))->toBe(file_get_contents($key));
    expect(file_get_contents($this->dir.'/keys/ca.key'))->toBe(file_get_contents($ca.'/ca.key'));
    expect(file_get_contents($this->dir.'/keys/ca.crt'))->toBe(file_get_contents($ca.'/ca.crt'));
    expect(file_get_contents($this->dir.'/keys/app.key'))->toBe(config('app.key')."\n");
    expect(decoct(fileperms($this->dir.'/keys/app.key') & 0o777))->toBe('600');
    expect(json_decode((string) file_get_contents($this->dir.'/epochs.json'), true)['epochs'])->toBe(['T01' => 1]);

    // A spare machine has no branch key of its own yet, and made its own authority and certificate at install.
    $branch = (string) file_get_contents($key);
    $shopAuthority = (string) file_get_contents($ca.'/ca.crt');
    unlink($key);
    foreach ([...glob($ca.'/*') ?: [], ...glob($this->tls.'/*') ?: []] as $file) {
        unlink($file);
    }
    machineCertificate();
    expect(file_get_contents($ca.'/ca.crt'))->not->toBe($shopAuthority);

    // The restore brings back both, so the counters trust this server as before.
    $this->artisan('shop:after-restore')
        ->expectsOutputToContain('The branch key came back from the backup disk.')
        ->expectsOutputToContain('The shop\'s certificate authority came back from the backup disk')
        ->doesntExpectOutputToContain('app key differs')
        ->assertSuccessful();
    expect(file_get_contents($key))->toBe($branch);
    expect(file_get_contents($ca.'/ca.crt'))->toBe($shopAuthority);
    expect(decoct(fileperms($ca.'/ca.key') & 0o777))->toBe('600');

    // The certificate job then issues the server's certificate from the shop's authority.
    $this->artisan('shop:make-certificate', ['--name' => 'haq-server.local', '--ip' => '192.168.1.10', '--tls-dir' => $this->tls])
        ->expectsOutputToContain('the counters already trust')->assertSuccessful();
    expect(openssl_x509_verify((string) file_get_contents($this->tls.'/server.crt'), $shopAuthority))->toBe(1);
    expect(file_get_contents($this->tls.'/ca.crt'))->toBe($shopAuthority);
});

it('leaves a shop\'s keys and epochs alone on a spare installed beside its backups, until the restore (D69)', function () {
    // The shop's server kept these before it failed; the spare is a fresh install with its own.
    mkdir($this->dir.'/keys', 0o700);
    $shop = ['branch.key' => base64_encode(sodium_crypto_sign_keypair()), 'ca.key' => "the shop's authority key\n", 'ca.crt' => "the shop's authority\n", 'app.key' => "base64:the-shop's-app-key\n"];
    foreach ($shop as $name => $contents) {
        file_put_contents($this->dir.'/keys/'.$name, $contents);
    }
    file_put_contents($this->dir.'/epochs.json', json_encode(['epochs' => ['T01' => 4, 'T02' => 2]]));
    machineCertificate();

    // The spare's scheduler runs every five minutes before anyone starts the restore.
    $this->artisan('backups:tick')->expectsOutputToContain('Left as they are, for the restore')->assertSuccessful();
    foreach ($shop as $name => $contents) {
        expect(file_get_contents($this->dir.'/keys/'.$name))->toBe($contents);
    }
    expect(json_decode((string) file_get_contents($this->dir.'/epochs.json'), true)['epochs'])->toBe(['T01' => 4, 'T02' => 2]);

    // The restore brings the authority back. The app key lives in .env, which deploy/restore.sh sets
    // first; run without it, the command says the counters would have to enrol again.
    $this->artisan('shop:after-restore')
        ->expectsOutputToContain('The shop\'s certificate authority came back from the backup disk')
        ->expectsOutputToContain('app key differs from the one on the backup disk')
        ->assertSuccessful();
    expect(file_get_contents($this->dir.'/machine-ca/ca.crt'))->toBe($shop['ca.crt']);
    // The new epochs go above the ones the shop's server knew.
    expect(json_decode((string) file_get_contents($this->dir.'/epochs.json'), true)['epochs'])->toBe(['T01' => 5, 'T02' => 3]);
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
