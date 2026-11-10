<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Sales\Finish;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * The owner's data export (spec section 14: "either way the owner receives his data export") [F10, D57]:
 * the shop's whole record in one ZIP of CSV files, cost included, for the owner only, with a README that
 * names each file and its SHA-256. HAQ makes the same file on the server with shop:export.
 */

beforeEach(function () {
    $this->counter = Counter::ready(items: 2);
    [$this->t1] = $this->counter['terminals'];
    [$this->a, $this->b] = $this->counter['items'];
    $this->cashier = $this->counter['cashier'];
    People::add('Halima', Role::Owner, '135790');
    People::add('Kemi', Role::Manager, '246810');
    $this->device = People::enrol(Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'D01', 'name' => 'Owner laptop', 'kind' => 'back_office']));
    $this->as = function (string $name, string $pin) {
        $user = User::query()->where('name', $name)->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $this->device)->withHeader(ActingToken::HEADER, $token);
    };
    $sell = fn (string $itemId, int $price) => app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(Counter::cashSale([$itemId => [1, $price]])));
    $this->first = $sell($this->a->id, 250_000);
    ($this->as)('Halima', '135790')->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 987_654, 'basis' => 'first_null'])->assertOk();
    $sell($this->b->id, 150_000);
    $this->day = (string) DB::table('sales')->value('business_day');
    ($this->as)('Halima', '135790')->postJson('/api/back-office/pilot/start', ['first_day' => $this->day])->assertOk();
    ($this->as)('Kemi', '246810')->postJson('/api/back-office/pilot/notebook', ['day' => $this->day, 'cash_kobo' => 400_000, 'transfer_kobo' => 0, 'pos_kobo' => 0])->assertOk();
});

/**
 * The files in a ZIP, by name.
 *
 * @return array<string, string>
 */
function zipFiles(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $files[$name] = (string) $zip->getFromName($name);
    }
    $zip->close();

    return $files;
}

it('gives the owner the whole record as one ZIP of CSV files, cost included, and nobody else', function () {
    ($this->as)('Kemi', '246810')->get('/api/back-office/exports/shop.zip')->assertForbidden();
    ($this->as)('Amina', '1357')->get('/api/back-office/exports/shop.zip')->assertForbidden();

    $response = ($this->as)('Halima', '135790')->get('/api/back-office/exports/shop.zip')->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/zip')
        ->and((string) $response->headers->get('Content-Disposition'))->toContain('haq-retail-os-data-');
    $file = $response->baseResponse;
    expect($file)->toBeInstanceOf(BinaryFileResponse::class);
    /** @var BinaryFileResponse $file */
    $path = $file->getFile()->getPathname();
    $files = zipFiles($path);
    unlink($path);

    expect(array_keys($files))->toBe(['README.txt', 'receipts.csv', 'sales-lines.csv', 'payments.csv', 'items.csv', 'set-prices.csv', 'costs.csv', 'shifts.csv',
        'drawer-log.csv', 'flags.csv', 'people.csv', 'audit-log.csv', 'pilot-days.csv', 'pilot-notebook.csv', 'pilot-traces.csv']);
    // Each opens in Excel: a byte-order mark, then the header.
    expect($files['receipts.csv'])->toStartWith("\u{FEFF}\"Receipt\"")->toContain($this->first['receipt_number'])->toContain('"2500.00"');
    expect($files['sales-lines.csv'])->toContain('Unit cost (NGN)')->toContain('9876.54');
    expect($files['costs.csv'])->toContain('9876.54')->toContain('Halima');
    expect($files['pilot-notebook.csv'])->toContain('"4000.00"')->toContain('Kemi');
    expect($files['pilot-days.csv'])->toContain('Started the 14 days');
    expect($files['audit-log.csv'])->toContain('sale.finished')->toContain('pilot.notebook_entered');
    // Names and roles, never PINs or device secrets.
    expect($files['people.csv'])->toContain('"Amina","Cashier","Yes"');
    $hashes = DB::table('users')->whereNotNull('pin_hash')->pluck('pin_hash')->all();
    foreach ($files as $content) {
        foreach ($hashes as $hash) {
            expect(str_contains($content, (string) $hash))->toBeFalse();
        }
    }

    // The README names every file with its SHA-256, so the owner can tell that none changed.
    foreach ($files as $name => $content) {
        if ($name !== 'README.txt') {
            expect($files['README.txt'])->toContain(hash('sha256', $content).'  '.$name);
        }
    }
    expect($files['README.txt'])->toContain('Made ')->toContain('by Halima')->toContain('Audit code when this was made:');
    expect(DB::table('audit_log')->where('action', 'export.downloaded')->count())->toBe(1);
});

it('makes the same export on the server with shop:export, for HAQ to hand over', function () {
    $dir = storage_path('framework/testing');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = $dir.'/export-'.bin2hex(random_bytes(4)).'.zip';
    $this->artisan('shop:export', ['to' => $path])->expectsOutputToContain('2 sales')->assertSuccessful();
    $files = zipFiles($path);
    unlink($path);

    expect($files)->toHaveKey('receipts.csv')->and($files['README.txt'])->toContain('by HAQ support (shop:export)');
    expect(DB::table('audit_log')->where('action', 'export.made')->value('device'))->toBe('server');
});
