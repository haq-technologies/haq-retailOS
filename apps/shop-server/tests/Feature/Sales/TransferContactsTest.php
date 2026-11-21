<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Printing\Printing;
use App\Printing\Receipt;
use App\Reports\DataExport;
use App\Sales\Finish;
use App\Sales\Parked;
use App\Sales\Sales;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * A transfer's sender, kept apart from the sale and cleared after the reversal window (D112, finding DP6
 * of the privacy checks): the bank reference stays with the sale and prints; the sender's name and phone
 * never print, are seen only by managers and the owner, stay out of the export, and go after 90 days.
 */

beforeEach(function () {
    $this->counter = Counter::ready(items: 1);
    [$this->t1] = $this->counter['terminals'];
    [$this->a] = $this->counter['items'];
    $this->cashier = $this->counter['cashier'];
    People::add('Halima', Role::Owner, '135790');
    People::add('Kemi', Role::Manager, '246810');
    $this->device = People::enrol(Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'D01', 'name' => 'Owner laptop', 'kind' => 'back_office']));
    $this->as = function (string $name, string $pin) {
        $user = User::query()->where('name', $name)->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $this->device)->withHeader(ActingToken::HEADER, $token);
    };
    $this->transfer = function (array $payment = [], array $overrides = []): array {
        $body = Counter::cashSale([$this->a->id => [1, 500_000]], $overrides);
        $body['payments'] = [['method' => 'transfer', 'amount_kobo' => 500_000, 'reference' => 'NIP-0001', 'alert_seen' => true, ...$payment]];

        return $body;
    };
    $this->sell = fn (array $body): array => app(Finish::class)->finish($this->t1, $this->cashier, Counter::request($body));
});

it('keeps a transfer sender apart from the sale, and the receipt only says why', function () {
    $sale = ($this->sell)(($this->transfer)(['sender_name' => ' Chidi Okafor ', 'phone' => '0803 123-4567']));

    expect(DB::table('payments')->where('sale_id', $sale['id'])->value('reference'))->toBe('NIP-0001');
    $contact = DB::table('transfer_contacts')->where('sale_id', $sale['id'])->first();
    expect($contact->sender_name)->toBe('Chidi Okafor')->and($contact->phone)->toBe('08031234567');
    expect(Sales::summary($sale['id'])['payments'][0]['contact_kept'])->toBeTrue();
    // The counter's answer, and so its journal, never carries who sent it.
    expect(json_encode($sale))->not->toContain('Chidi')->not->toContain('0803');

    $text = Receipt::forSale(Sales::summary($sale['id']), 'Counter 1', app(Printing::class)->text(), null, 90)->text();
    expect($text)->toContain('Ref: NIP-0001')->toContain('Your name and phone are kept 90 days,')
        ->not->toContain('Chidi')->not->toContain('0803');
});

it('never refuses a sale over the sender, and keeps only what it can', function () {
    $bad = ($this->sell)(($this->transfer)(['sender_name' => 'Bisi', 'phone' => 'call me']));
    expect(DB::table('transfer_contacts')->where('sale_id', $bad['id'])->first())->sender_name->toBe('Bisi')->phone->toBeNull();

    $none = ($this->sell)(($this->transfer)(['sender_name' => str_repeat('x', 81), 'phone' => '']));
    expect(DB::table('transfer_contacts')->where('sale_id', $none['id'])->exists())->toBeFalse();
    expect(Sales::summary($none['id'])['payments'][0]['contact_kept'])->toBeFalse();

    // A cash payment keeps no sender, whatever it is sent with.
    $cash = Counter::cashSale([$this->a->id => [1, 500_000]]);
    $cash['payments'][0]['sender_name'] = 'Tunde';
    ($this->sell)($cash);
    expect(DB::table('transfer_contacts')->count())->toBe(1);
});

it('keeps the payload hash a sale had before senders existed, so a retry still matches', function () {
    $body = ($this->transfer)();
    $plain = Counter::request($body)->payloadHash();
    $withSender = Counter::request(($this->transfer)(['sender_name' => 'Chidi', 'phone' => '08031234567'], ['idempotency_key' => $body['idempotency_key'], 'lines' => $body['lines']]))->payloadHash();
    expect($withSender)->toBe($plain);

    $first = ($this->sell)($body);
    $again = ($this->sell)([...$body, 'payments' => [[...$body['payments'][0], 'sender_name' => 'Chidi']]]);
    expect($again['id'])->toBe($first['id'])->and($again['retried'])->toBeTrue();
    expect(DB::table('sales')->count())->toBe(1);
});

it('shows the sender to the owner and managers, and leaves it out of the export', function () {
    $sale = ($this->sell)(($this->transfer)(['sender_name' => 'Chidi Okafor', 'phone' => '08031234567']));

    ($this->as)('Halima', '135790')->getJson("/api/back-office/sales/{$sale['id']}")->assertOk()
        ->assertJsonPath('payments.0.sender_name', 'Chidi Okafor')->assertJsonPath('payments.0.phone', '08031234567');
    ($this->as)('Kemi', '246810')->getJson("/api/back-office/sales/{$sale['id']}")->assertOk()->assertJsonPath('payments.0.phone', '08031234567');

    $path = DataExport::temporaryPath();
    app(DataExport::class)->write($path, 'Halima');
    $zip = new ZipArchive;
    $zip->open($path);
    $all = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $all .= (string) $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($path);
    expect($all)->toContain('NIP-0001')->not->toContain('Chidi')->not->toContain('08031234567');
});

it('clears senders after the reversal window, keeping the sale and saying how many went', function () {
    $old = ($this->sell)(($this->transfer)(['sender_name' => 'Chidi', 'phone' => '08031234567']));
    $this->travel(60)->days();
    $recent = ($this->sell)(($this->transfer)(['sender_name' => 'Bisi']));

    $this->travel(31)->days();
    $this->artisan('payments:clear-contacts')->assertSuccessful();
    expect(DB::table('transfer_contacts')->pluck('sale_id')->all())->toBe([$recent['id']]);
    expect(DB::table('payments')->where('sale_id', $old['id'])->value('reference'))->toBe('NIP-0001');
    expect(Sales::summary($old['id'])['payments'][0]['contact_kept'])->toBeFalse();
    $entry = DB::table('audit_log')->where('action', 'payments.transfer_contacts_cleared')->first();
    expect(json_decode($entry->redacted_after, true))->toMatchArray(['count' => 1, 'kept_days' => 90])
        ->and($entry->redacted_after)->not->toContain('Chidi');

    // The owner's console setting shortens it; nothing is cleared twice.
    app(Settings::class)->set('payments.transfer_contact_days', 30, 'console');
    $this->artisan('payments:clear-contacts')->assertSuccessful();
    expect(DB::table('transfer_contacts')->count())->toBe(0);
    expect(DB::table('audit_log')->where('action', 'payments.transfer_contacts_cleared')->count())->toBe(2);
});

it('labels an awaiting transfer until it is finished, then lets the label go', function () {
    $body = ($this->transfer)();
    $finished = app(Parked::class)->park($this->t1, $this->cashier, 'awaiting_transfer', 'Chidi', $body, 500_000);
    $discarded = app(Parked::class)->park($this->t1, $this->cashier, 'awaiting_transfer', 'Bisi', $body, 500_000);

    ($this->sell)([...($this->transfer)(['sender_name' => 'Chidi']), 'parked_sale_id' => $finished]);

    expect(DB::table('parked_sales')->where('id', $finished)->value('label'))->toBeNull();
    expect(DB::table('parked_sales')->where('id', $discarded)->value('label'))->toBe('Bisi');
    expect(DB::table('transfer_contacts')->value('sender_name'))->toBe('Chidi');
});
