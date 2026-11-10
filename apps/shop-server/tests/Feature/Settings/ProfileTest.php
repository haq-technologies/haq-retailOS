<?php

declare(strict_types=1);

use App\Organisation\Models\Branch;
use App\Organisation\Models\Terminal;
use App\Settings\Profile\InvalidProfile;
use App\Settings\Profile\Profile;
use App\Settings\Registry;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;

/*
 * The shop profile tool (spec sections 6 and 11) [F25, D14]: made before the visit, applied at install,
 * and safe to apply twice.
 */

beforeEach(function () {
    $this->path = storage_path('framework/testing/profile-'.getmypid().'.json');
});

afterEach(fn () => @unlink($this->path));

function makeProfile(string $path, array $extra = []): void
{
    test()->artisan('shop:make-profile', [
        '--shop' => 'Example Accessories',
        '--branch-name' => 'Main branch',
        '--terminals' => 2,
        '--drawers' => 'T01',
        '--address' => '1 Example Street',
        '--phone' => '0800 000 0000',
        '--footer' => 'Goods sold in good condition.',
        '--output' => $path,
        '--no-interaction' => true,
        ...$extra,
    ])->assertSuccessful();
}

it('makes a profile that the installer accepts', function () {
    makeProfile($this->path);

    $profile = json_decode((string) file_get_contents($this->path), true);
    expect($profile['schema_version'])->toBe(1);
    expect($profile['terminals'])->toBe([
        ['code' => 'T01', 'name' => 'Counter 1', 'drawer' => true, 'kind' => 'counter'],
        ['code' => 'T02', 'name' => 'Counter 2', 'drawer' => false, 'kind' => 'counter'],
    ]);
    expect($profile['receipt']['shop_name'])->toBe('Example Accessories');
});

it('puts each counter\'s receipt printer in the profile, so nobody edits it by hand (D59)', function () {
    makeProfile($this->path, ['--printers' => 'T01=192.168.1.21, T02=printer:9101']);

    $profile = json_decode((string) file_get_contents($this->path), true);
    expect($profile['terminals'][0]['printer'])->toBe(['host' => '192.168.1.21', 'port' => 9100])
        ->and($profile['terminals'][1]['printer'])->toBe(['host' => 'printer', 'port' => 9101]);
    $this->artisan('shop:apply-profile', ['path' => $this->path])->assertSuccessful();
    expect(Terminal::query()->where('code', 'T01')->value('printer_host'))->toBe('192.168.1.21');

    expect(fn () => makeProfile($this->path, ['--printers' => 'T01=not an address']))->toThrow(InvalidArgumentException::class);
});

it('sets up the shop, its branch, counters, drawers and receipt text', function () {
    makeProfile($this->path);

    $this->artisan('shop:apply-profile', ['path' => $this->path])->assertSuccessful();

    $branch = Branch::local();
    expect($branch->code)->toBe('B01');
    expect($branch->tenant->name)->toBe('Example Accessories');
    expect(Terminal::query()->orderBy('code')->pluck('code')->all())->toBe(['T01', 'T02']);
    expect(DB::table('drawers')->join('terminals', 'terminals.id', '=', 'drawers.terminal_id')->orderBy('code')->pluck('fitted', 'code')->map(fn ($v) => (bool) $v)->all())
        ->toBe(['T01' => true, 'T02' => false]);

    $settings = app(Settings::class);
    expect($settings->get('receipt.shop_name'))->toBe('Example Accessories');
    expect($settings->get('receipt.footer'))->toBe('Goods sold in good condition.');
    expect($settings->isLocked('receipt.address'))->toBeTrue();

    expect(DB::table('audit_log')->where('action', 'shop.profile_applied')->count())->toBe(1);
});

it('changes nothing when the same profile is applied twice', function () {
    makeProfile($this->path);
    $this->artisan('shop:apply-profile', ['path' => $this->path])->assertSuccessful();
    $counts = fn () => [DB::table('settings_history')->count(), DB::table('audit_log')->count(), DB::table('terminals')->count()];
    $before = $counts();

    $this->artisan('shop:apply-profile', ['path' => $this->path])
        ->expectsOutputToContain('Nothing to change')
        ->assertSuccessful();

    expect($counts())->toBe($before);
});

it('adds counters and changes drawers, but never deletes a counter', function () {
    makeProfile($this->path);
    $this->artisan('shop:apply-profile', ['path' => $this->path])->assertSuccessful();

    makeProfile($this->path, ['--terminals' => 1, '--drawers' => '']);
    $this->artisan('shop:apply-profile', ['path' => $this->path])
        ->expectsOutputToContain('T01 no longer has a cash drawer')
        ->assertSuccessful();

    expect(Terminal::query()->count())->toBe(2);
});

it('refuses a profile for another branch', function () {
    makeProfile($this->path);
    $this->artisan('shop:apply-profile', ['path' => $this->path])->assertSuccessful();

    makeProfile($this->path, ['--branch-code' => 'B02']);
    expect(fn () => $this->artisan('shop:apply-profile', ['path' => $this->path])->run())
        ->toThrow(RuntimeException::class, 'This server runs branch B01');
});

it('explains every problem in an invalid profile and applies none of it', function () {
    file_put_contents($this->path, json_encode([
        'schema_version' => 1,
        'shop' => ['name' => 'Example'],
        'branch' => ['code' => 'b-1', 'name' => 'Main'],
        'terminals' => [['code' => 'Counter1', 'name' => 'Counter 1', 'drawer' => true]],
        'receipt' => ['shop_name' => 'Example'],
        'settings' => ['no.such.setting' => true],
    ]));

    $this->artisan('shop:apply-profile', ['path' => $this->path])
        ->expectsOutputToContain('The profile was not applied, because it is not valid')
        ->assertFailed();
    expect(DB::table('branches')->count())->toBe(0);

    try {
        Profile::fromFile($this->path, app(Registry::class));
        $this->fail('The profile should not be valid.');
    } catch (InvalidProfile $e) {
        expect($e->errors)->toContain('The branch code must be 2 to 8 capital letters or digits, such as B01.');
        expect($e->errors)->toContain("Each counter's code must be T and two digits, such as T01 (it starts every receipt number); a back-office device's is D and two digits, such as D01.");
    }
});

it('applies the example profile in the repository', function () {
    $this->artisan('shop:apply-profile', ['path' => base_path('docker/profile.example.json')])->assertSuccessful();
    expect(Branch::local()->name)->toBe('Main branch');
});
