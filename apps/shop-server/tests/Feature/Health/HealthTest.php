<?php

declare(strict_types=1);

use App\Audit\Console\VerifyCommand;
use App\Health\CatchUp;
use App\Health\Health;
use App\Health\Snapshot;
use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Platform\Clock;
use App\Platform\Console\MutabilityCommand;
use App\Sales\Finish;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * Server health (spec section 4; checks E1, E3, E4 and E6 in section 14; wireframe HealthPage) [F3, F15,
 * F24, D45]: the health page and its warnings on the dashboard, Back up now, the server clock with its
 * unverified mode, and catch-up scheduling of the nightly checks.
 */

beforeEach(function () {
    $this->dir = storage_path('framework/testing/health-'.Str::random(8));
    mkdir($this->dir, 0o777, true);
    config(['backups.status_dir' => $this->dir, 'health.certificate' => $this->dir.'/server.crt']);
    $this->counter = Counter::ready(items: 1);
    [$this->t1] = $this->counter['terminals'];
    [$this->a] = $this->counter['items'];
    $this->owner = People::add('Halima', Role::Owner, '135790');
    People::add('Kemi', Role::Manager, '246810');
    $this->device = People::enrol(Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'D01', 'name' => 'Owner laptop', 'kind' => 'back_office']));
    $this->as = function (string $name = 'Halima', string $pin = '135790') {
        $user = User::query()->where('name', $name)->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $this->device)->withHeader(ActingToken::HEADER, $token);
    };
    $this->sell = fn () => app(Finish::class)->finish($this->t1, $this->counter['cashier'], Counter::request(Counter::cashSale([$this->a->id => [1, 5000]])));
    // A healthy server: a recent backup, a verified test restore, both nightly checks run, a far certificate.
    $this->healthy = function (): void {
        $now = time();
        file_put_contents($this->dir.'/backup.json', json_encode(['backups' => [
            ['label' => '20261101-010000F', 'type' => 'full', 'start' => $now - 6 * 86400, 'stop' => $now - 6 * 86400 + 60],
            ['label' => '20261101-010000F_20261107-010000I', 'type' => 'incr', 'start' => $now - 3600, 'stop' => $now - 3500],
        ]]));
        file_put_contents($this->dir.'/restore.json', json_encode(['state' => 'done', 'label' => '20261101-010000F', 'verified' => true, 'finished_at' => $now - 6 * 86400]));
        file_put_contents($this->dir.'/last-verified-restore', (string) ($now - 6 * 86400));
        file_put_contents($this->dir.'/host.json', json_encode(['written_at' => $now, 'smartctl' => true, 'time_synced' => false,
            'disks' => [['device' => '/dev/nvme0', 'model' => 'Example SSD', 'passed' => true, 'life_left' => 98]]]));
        healthCertificate($this->dir.'/server.crt', days: 700);
        mkdir($this->dir.'/offsite');
        file_put_contents($this->dir.'/offsite/key.json', json_encode(['made_at' => $now - 30 * 86400, 'fingerprint' => 'abc']));
        file_put_contents($this->dir.'/offsite.json', json_encode(['drives' => ['A' => ['copied_at' => $now - 2 * 86400, 'ok' => true, 'bytes' => 1000, 'file' => 'x.tar.age']]]));
        Cache::forever(MutabilityCommand::HEALTH_KEY, ['checked_at' => now()->subHours(3)->toIso8601String(), 'problems' => []]);
        Cache::forever(VerifyCommand::HEALTH_KEY, ['checked_at' => now()->subHours(3)->toIso8601String(), 'problems' => []]);
        Cache::forever(Clock::HIGH_WATER, now()->toIso8601String());
        // The disks are this machine's own; the tests set what counts as full.
        config(['health.disk_warn_percent' => 0, 'health.disk_warn_bytes' => 0]);
    };
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    foreach (glob($this->dir.'/{,*/}*', GLOB_BRACE) ?: [] as $f) {
        is_file($f) && unlink($f);
    }
    @rmdir($this->dir.'/offsite');
    @rmdir($this->dir);
});

/** A self-signed certificate that expires in the given number of days. */
function healthCertificate(string $path, int $days): void
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $csr = openssl_csr_new(['commonName' => 'haq-server.local'], $key);
    $cert = openssl_csr_sign($csr, null, $key, $days);
    openssl_x509_export($cert, $pem);
    file_put_contents($path, $pem);
}

it('shows the owner a healthy server with no warning, and the dashboard says the checks passed', function () {
    ($this->healthy)();
    $this->artisan('health:check')->assertSuccessful();

    $view = ($this->as)()->getJson('/api/back-office/health')->assertOk();
    $view->assertJsonPath('warnings', [])->assertJsonPath('clock.state', 'trusted')->assertJsonPath('host.disks.0.life_left', 98)
        ->assertJsonPath('backups.last.type', 'incr')->assertJsonPath('backups.count', 2);
    expect($view->json('certificate.expires_at'))->toStartWith(now()->addDays(700)->toDateString());
    expect($view->json('wal'))->toHaveKeys(['archived_at', 'failed_at']);
    expect($view->json('database_bytes'))->toBeGreaterThan(0);

    $dashboard = ($this->as)()->getJson('/api/back-office/dashboard')->assertOk();
    expect(collect($dashboard->json('alerts'))->where(fn ($a) => str_starts_with($a['kind'], 'health_')))->toBeEmpty();
    expect($dashboard->json('system'))->toStartWith('System checks passed at ')->toContain('clock verified, certificate valid, audit chains verified');

    // Server health is the owner's; a manager may not open it, and it carries no sales or cost.
    ($this->as)('Kemi', '246810')->getJson('/api/back-office/health')->assertForbidden();
    expect(json_encode($view->json()))->not->toContain('cost')->not->toContain('total_kobo');
});

it('warns of an old backup, a failed test restore, a worn SSD, a near certificate, a full disk and the checks', function () {
    ($this->healthy)();
    $now = time();
    file_put_contents($this->dir.'/backup.json', json_encode(['backups' => [['label' => '20261104-010000F', 'type' => 'full', 'start' => $now - 60 * 3600, 'stop' => $now - 60 * 3600]]]));
    file_put_contents($this->dir.'/backup-failed.json', json_encode(['failed_at' => $now - 3600, 'type' => 'incr']));
    file_put_contents($this->dir.'/restore.json', json_encode(['state' => 'done', 'label' => '20261104-010000F', 'verified' => false, 'finished_at' => $now - 7200]));
    file_put_contents($this->dir.'/verified-20261104-010000F.json', json_encode(['ok' => false, 'problems' => ['The copy has more sales rows (9) than the live database (8).']]));
    file_put_contents($this->dir.'/host.json', json_encode(['written_at' => $now, 'smartctl' => true, 'disks' => [['device' => '/dev/sda', 'model' => 'Old SSD', 'passed' => true, 'life_left' => 4]]]));
    healthCertificate($this->dir.'/server.crt', days: 30);
    Cache::forever(MutabilityCommand::HEALTH_KEY, ['checked_at' => now()->subHours(50)->toIso8601String(), 'problems' => ['audit_log: the app role may DELETE.']]);
    config(['health.disk_warn_percent' => 101]);
    $this->artisan('health:check')->assertSuccessful();

    $warnings = collect(app(Health::class)->warnings());
    expect($warnings->pluck('title')->all())->toContain('The last backup is 60 hours old', 'The last backup failed', 'The weekly test restore failed',
        'An SSD is wearing out', 'The database grants have changed', 'The database grants have not been checked for two days', 'The main disk is nearly full');
    expect($warnings->firstWhere('kind', 'certificate')['title'])->toMatch('/^The server\'s certificate expires in (29|30) days$/');
    expect($warnings->firstWhere('kind', 'restore')['detail'])->toStartWith('The copy has more sales rows');

    $alerts = collect(($this->as)()->getJson('/api/back-office/dashboard')->json('alerts'));
    expect($alerts->firstWhere('kind', 'health_backup'))->open->toBe('server');
    expect(($this->as)()->getJson('/api/back-office/dashboard')->json('system'))->toBeNull();

    // With no checks at all, the page says the scheduler is not running.
    Cache::forget(Snapshot::KEY);
    expect(collect(app(Health::class)->warnings())->pluck('kind'))->toContain('checks');
});

it('passes Back up now to the backup service and shows it running until the service answers', function () {
    ($this->healthy)();
    $this->artisan('health:check')->assertSuccessful();
    ($this->as)()->postJson('/api/back-office/health/backup')->assertStatus(202);
    ($this->as)('Kemi', '246810')->postJson('/api/back-office/health/backup')->assertForbidden();
    expect(DB::table('audit_log')->where('action', 'backup.requested')->count())->toBe(1);

    $this->artisan('health:check')->expectsOutputToContain('Asked the backup service for a backup now.')->assertSuccessful();
    $asked = json_decode((string) file_get_contents($this->dir.'/backup-now.json'), true);
    expect($asked['requested_at'])->toBeString();
    ($this->as)()->getJson('/api/back-office/health')->assertJsonPath('backups.backup_now', true);

    // The backup service answers by noting the request in its own file.
    file_put_contents($this->dir.'/backup-now-done', $asked['requested_at']."\n");
    $this->artisan('health:check')->doesntExpectOutputToContain('Asked the backup service')->assertSuccessful();
    ($this->as)()->getJson('/api/back-office/health')->assertJsonPath('backups.backup_now', false);
});

it('keeps selling with the clock at the year 2000, stamps the sales unverified and asks the owner (check E3)', function () {
    ($this->healthy)();
    expect(($this->sell)()['receipt_number'])->toBe('T01-E1-000001');

    CarbonImmutable::setTestNow('2000-01-01 08:00:00');
    $this->artisan('health:check')->expectsOutputToContain('unverified: before_build')->assertSuccessful();
    $sale = ($this->sell)();
    expect(DB::table('sales')->where('id', $sale['id'])->value('clock_state'))->toBe('unverified');
    expect(DB::table('sales')->where('receipt_number', 'T01-E1-000001')->value('clock_state'))->toBe('trusted');

    $alerts = collect(($this->as)()->getJson('/api/back-office/dashboard')->json('alerts'));
    expect($alerts->firstWhere('kind', 'health_clock'))->title->toBe('The server\'s clock may be wrong');
    // A clock before this version was made cannot be confirmed; HAQ support sets it.
    ($this->as)()->postJson('/api/back-office/health/clock')->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'cannot be right'));

    // Put right, it stays unverified until the owner confirms it; then sales are trusted again.
    CarbonImmutable::setTestNow();
    $this->artisan('health:check')->assertSuccessful();
    expect(app(Clock::class)->stamp())->toBe('unverified');
    ($this->as)()->postJson('/api/back-office/health/clock')->assertOk()->assertJsonPath('clock.state', 'trusted')->assertJsonPath('clock.confirmed_by', 'Halima');
    expect(DB::table('sales')->where('id', ($this->sell)()['id'])->value('clock_state'))->toBe('trusted');
    expect(DB::table('audit_log')->whereIn('action', ['clock.unverified', 'clock.confirmed'])->pluck('action')->all())->toBe(['clock.unverified', 'clock.confirmed']);
});

it('notes a clock that jumped back, and lets the owner confirm a time put back on purpose', function () {
    ($this->healthy)();
    $this->artisan('health:check')->assertSuccessful();
    CarbonImmutable::setTestNow(now()->subMinutes(3));
    $this->artisan('health:check')->assertSuccessful();
    expect(app(Clock::class)->state()['state'])->toBe('trusted'); // a small step back is ordinary

    CarbonImmutable::setTestNow(now()->subHours(2));
    $this->artisan('health:check')->expectsOutputToContain('unverified: jumped_back')->assertSuccessful();
    expect(app(Clock::class)->state())->state->toBe('unverified')->reason->toBe('jumped_back');
    $this->artisan('health:check')->doesntExpectOutputToContain('unverified')->assertSuccessful();
    expect(DB::table('clock_events')->count())->toBe(1);

    ($this->as)()->postJson('/api/back-office/health/clock')->assertOk();
    $this->artisan('health:check')->assertSuccessful();
    expect(app(Clock::class)->state()['state'])->toBe('trusted');
});

it('runs a missed nightly check at the next quiet moment (check E4)', function () {
    $catchUp = app(CatchUp::class);
    // Never run: due as soon as the shop is quiet.
    CarbonImmutable::setTestNow('2026-11-07 13:00:00'); // 14:00 in Lagos, trading
    expect($catchUp->due(MutabilityCommand::HEALTH_KEY))->toBeTrue();
    ($this->sell)();
    expect($catchUp->due(MutabilityCommand::HEALTH_KEY))->toBeFalse();

    // Ten quiet minutes later, it runs.
    CarbonImmutable::setTestNow('2026-11-07 13:11:00');
    expect($catchUp->due(MutabilityCommand::HEALTH_KEY))->toBeTrue();

    // Run today: not again until tomorrow, quiet or not.
    Cache::forever(MutabilityCommand::HEALTH_KEY, ['checked_at' => '2026-11-07T02:00:00+00:00', 'problems' => []]);
    expect($catchUp->due(MutabilityCommand::HEALTH_KEY))->toBeFalse();

    // In the night window it runs even with a sale a minute ago.
    CarbonImmutable::setTestNow('2026-11-08 01:30:00'); // 02:30 in Lagos
    ($this->sell)();
    expect($catchUp->quiet())->toBeTrue()->and($catchUp->due(MutabilityCommand::HEALTH_KEY))->toBeTrue();
});

it('warns when the off-site copy is not set up, overdue or failing (D53)', function () {
    ($this->healthy)();
    $this->artisan('health:check')->assertSuccessful();
    ($this->as)()->getJson('/api/back-office/health')->assertJsonPath('offsite.drives.A.ok', true);
    expect(collect(app(Health::class)->warnings())->where('kind', 'offsite'))->toBeEmpty();

    $now = time();
    file_put_contents($this->dir.'/offsite.json', json_encode(['drives' => [
        'A' => ['copied_at' => $now - 10 * 86400, 'ok' => true],
        'B' => ['copied_at' => $now - 20 * 86400, 'failed_at' => $now - 3600, 'ok' => false, 'reason' => 'drive full'],
    ]]));
    $this->artisan('health:check')->assertSuccessful();
    $titles = collect(app(Health::class)->warnings())->where('kind', 'offsite')->pluck('title')->all();
    expect($titles)->toBe(['No flash drive has had a copy for 10 days', 'The last copy to drive B failed']);

    unlink($this->dir.'/offsite/key.json');
    $this->artisan('health:check')->assertSuccessful();
    expect(collect(app(Health::class)->warnings())->where('kind', 'offsite')->pluck('title')->all())->toBe(['The off-site copy is not set up']);
});
