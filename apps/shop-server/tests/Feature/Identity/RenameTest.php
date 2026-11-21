<?php

declare(strict_types=1);

use App\Identity\Role;
use App\Sales\Finish;
use App\Sales\Sales;
use Illuminate\Support\Facades\DB;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * Correcting a name on a tile (D113, finding DP7 of the privacy checks): `sudo haq rename` puts a misspelt
 * name right without splitting the person's history; the audit trail keeps both names.
 */

it('corrects a name on a tile, keeps the history whole, and audits both names', function () {
    $counter = Counter::ready(items: 1);
    $amina = $counter['cashier'];
    People::add('Halima', Role::Owner, '135790');
    $sale = app(Finish::class)->finish($counter['terminals'][0], $amina, Counter::request(Counter::cashSale([$counter['items'][0]->id => [1, 5000]])));

    $this->artisan('shop:rename-user', ['person' => 'Amina', 'name' => ' Aminat '])->expectsOutputToContain('Amina is now Aminat')->assertSuccessful();

    expect($amina->fresh()?->name)->toBe('Aminat');
    // The same person: the sale they rang is theirs, under the right name.
    expect(Sales::summary($sale['id'])['cashier'])->toBe('Aminat');
    $entry = DB::table('audit_log')->where('action', 'identity.user_renamed')->first();
    expect(json_decode($entry->redacted_before, true))->toBe(['name' => 'Amina'])->and(json_decode($entry->redacted_after, true))->toBe(['name' => 'Aminat']);

    $this->artisan('shop:rename-user', ['person' => 'Aminat', 'name' => 'Aminat'])->expectsOutputToContain('already has that name')->assertSuccessful();
    $this->artisan('shop:rename-user', ['person' => 'Aminat', 'name' => str_repeat('a', 61)])->expectsOutputToContain('1 to 60 characters')->assertFailed();
    $this->artisan('shop:rename-user', ['person' => 'Nobody', 'name' => 'Somebody'])->assertFailed();
    expect(DB::table('audit_log')->where('action', 'identity.user_renamed')->count())->toBe(1);
});
