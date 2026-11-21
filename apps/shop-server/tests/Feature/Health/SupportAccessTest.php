<?php

declare(strict_types=1);

use App\Health\Health;
use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * HAQ support on the server, and the server's disk encryption (D110 and D111; findings DP3 and DP5 of the
 * privacy checks): every support command and SSH sign-in goes on the audit trail and the owner's flag list,
 * and the health page warns while the server's disks are not encrypted.
 */

beforeEach(function () {
    $this->dir = storage_path('framework/testing/support-'.Str::random(8));
    mkdir($this->dir, 0o777, true);
    config(['backups.status_dir' => $this->dir, 'health.certificate' => $this->dir.'/server.crt']);
    $this->counter = Counter::ready(items: 1);
    [$this->t1] = $this->counter['terminals'];
    People::add('Halima', Role::Owner, '135790');
    $this->device = People::enrol(Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'D01', 'name' => 'Owner laptop', 'kind' => 'back_office']));
    $this->owner = function () {
        $user = User::query()->where('name', 'Halima')->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => '135790'])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $this->device)->withHeader(ActingToken::HEADER, $token);
    };
    $this->host = function (array $extra): void {
        file_put_contents($this->dir.'/host.json', json_encode(['written_at' => time(), 'smartctl' => true, 'disks' => [], 'time_synced' => true, ...$extra]));
    };
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

it('records each support command and shows it on the owner\'s flag list', function () {
    $this->artisan('support:record', ['what' => 'export', '--by' => 'haq', '--from' => '192.168.1.20'])->assertSuccessful();

    $entry = DB::table('audit_log')->where('action', 'support.command')->first();
    expect(json_decode($entry->redacted_after, true))->toEqual(['command' => 'export', 'by' => 'haq', 'from' => '192.168.1.20']);

    $flag = ($this->owner)()->getJson('/api/back-office/flags?period=today&kind=support_access')->assertOk()->json('flags.0');
    expect($flag['title'])->toBe('Server command: haq export')->and($flag['detail'])->toContain('Run by haq from 192.168.1.20');
    expect(collect(($this->owner)()->getJson('/api/back-office/dashboard')->json('attention.flags'))->pluck('kind'))->toContain('support_access');
});

it('records each SSH sign-in the host reports, once, and none from long ago', function () {
    $at = time() - 600;
    $signIn = ['at' => $at, 'user' => 'haq', 'from' => '192.168.1.20', 'port' => 51514, 'method' => 'key'];
    ($this->host)(['sign_ins' => [$signIn, ['at' => time() - 5 * 86400, 'user' => 'old', 'from' => '10.0.0.9', 'port' => 22, 'method' => 'key'], ['user' => 'no time']]]);

    $this->artisan('health:check')->assertSuccessful();
    $this->artisan('health:check')->assertSuccessful();

    $rows = DB::table('audit_log')->where('action', 'support.signed_in')->get();
    expect($rows)->toHaveCount(1);
    expect(strtotime($rows[0]->recorded_at))->toBe($at);
    $flag = ($this->owner)()->getJson('/api/back-office/flags?period=today&kind=support_access')->json('flags.0');
    expect($flag['title'])->toBe('Someone signed in to the server from 192.168.1.20')->and($flag['detail'])->toStartWith('As haq, with a key.');

    // A second sign-in from the same place a minute later is a new visit.
    ($this->host)(['sign_ins' => [$signIn, [...$signIn, 'at' => $at + 60, 'port' => 51600]]]);
    $this->artisan('health:check')->assertSuccessful();
    expect(DB::table('audit_log')->where('action', 'support.signed_in')->count())->toBe(2);
    $this->artisan('audit:verify')->assertSuccessful();
});

it('warns while the server\'s disk is not encrypted, and says nothing when it cannot tell', function () {
    ($this->host)(['encryption' => ['system' => false, 'backup' => true]]);
    $this->artisan('health:check')->assertSuccessful();
    $warning = collect(app(Health::class)->warnings())->firstWhere('kind', 'encryption');
    expect($warning['title'])->toBe('The server\'s disk is not encrypted')->and($warning['detail'])->toContain('BitLocker');
    ($this->owner)()->getJson('/api/back-office/health')->assertOk()->assertJsonPath('host.encryption.system', false);

    // The backup disk shows on the health page, but is not warned about until its encryption is designed (D111).
    foreach ([['system' => true, 'backup' => false], ['system' => true, 'backup' => true], ['system' => null, 'backup' => null], null] as $encryption) {
        ($this->host)($encryption === null ? [] : ['encryption' => $encryption]);
        $this->artisan('health:check')->assertSuccessful();
        expect(collect(app(Health::class)->warnings())->where('kind', 'encryption'))->toBeEmpty();
    }
});
