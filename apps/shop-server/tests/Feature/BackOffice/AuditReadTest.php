<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Sales\Finish;
use Tests\Support\Catalogue;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * Reading the audit trail and the counter's speed (spec sections 3, 10 and 12; checks D5 and A1) [F10, S8,
 * D47]: a cashier reads only their own actions on the counter, a manager the branch's, both redacted; only
 * the owner reads sensitive values such as cost. The daily summary shows each cashier's speed on screen.
 */

beforeEach(function () {
    $this->counter = Counter::ready(items: 2);
    [$this->t1] = $this->counter['terminals'];
    [$this->a, $this->b] = $this->counter['items'];
    $this->cashier = $this->counter['cashier'];
    People::add('Halima', Role::Owner, '135790');
    People::add('Kemi', Role::Manager, '246810');
    People::add('Sola', Role::Storekeeper, '2468');
    $this->office = People::enrol(Terminal::query()->create(['branch_id' => $this->t1->branch_id, 'code' => 'D01', 'name' => 'Owner laptop', 'kind' => 'back_office']));
    $this->till = People::enrol($this->t1);
    $this->as = function (string $name, string $pin, ?string $device = null) {
        $device ??= $this->office;
        $user = User::query()->where('name', $name)->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token);
    };
    $this->sell = function (array $lines, int $seconds, array $addedBy = []): array {
        $sale = Counter::cashSale($lines, ['started_at' => now()->subSeconds($seconds)->toIso8601String()]);
        foreach ($addedBy as $i => $how) {
            $sale['lines'][$i]['added_by'] = $how;
        }

        return app(Finish::class)->finish($this->t1, $this->cashier, Counter::request($sale));
    };
});

it('shows the owner every action with its sensitive values, and a manager the same with them held back', function () {
    ($this->sell)([$this->a->id => [1, 5000]], 12);
    ($this->as)('Halima', '135790')->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 123_457, 'basis' => 'first_null'])->assertOk();

    $owner = ($this->as)('Halima', '135790')->getJson('/api/back-office/audit')->assertOk();
    $cost = collect($owner->json('entries'))->firstWhere('action', 'cost.entered');
    expect($cost)->what->toBe('Cost entered')->who->toBe('Halima')->held_back->toBeFalse();
    expect($cost['sensitive']['after']['unit_cost_kobo'])->toBe(123_457);
    expect($owner->json('sensitive'))->toBeTrue();
    expect(collect($owner->json('entries'))->firstWhere('action', 'sale.finished'))->who->toBe('Amina')->device->toBe('Counter 1');

    $manager = ($this->as)('Kemi', '246810')->getJson('/api/back-office/audit')->assertOk();
    $held = collect($manager->json('entries'))->firstWhere('action', 'cost.entered');
    expect($held)->held_back->toBeTrue()->not->toHaveKey('sensitive');
    expect($manager->getContent())->not->toContain('123457')->not->toContain('1234.57')->not->toContain('1,234.57');
    expect($manager->json('sensitive'))->toBeFalse();

    // The storekeeper reads none; nobody can change an entry here, as there is no route to.
    ($this->as)('Sola', '2468')->getJson('/api/back-office/audit')->assertForbidden();
});

it('lets a cashier read only their own actions on the counter', function () {
    ($this->sell)([$this->a->id => [1, 5000]], 12);
    ($this->as)('Halima', '135790')->postJson('/api/back-office/costs', ['item_ids' => [$this->a->id], 'unit_cost_kobo' => 123_457, 'basis' => 'first_null'])->assertOk();

    $mine = ($this->as)('Amina', '1357', $this->till)->getJson('/api/counter/history')->assertOk();
    $who = collect($mine->json('entries'))->pluck('who')->unique()->values()->all();
    expect($who)->toBe(['Amina']);
    expect(collect($mine->json('entries'))->pluck('action'))->toContain('sale.finished')->not->toContain('cost.entered');
    expect($mine->getContent())->not->toContain('123457');
});

it('filters by person and day, and pages through a long log without repeating an entry', function () {
    $audit = app(AuditLog::class);
    $owner = User::query()->where('name', 'Halima')->firstOrFail();
    foreach (range(1, 55) as $n) {
        $audit->record(new AuditEntry(action: 'flag.seen', actorId: $owner->id, subjectType: 'flag', after: ['n' => $n]));
    }
    ($this->sell)([$this->a->id => [1, 5000]], 12);

    $first = ($this->as)('Halima', '135790')->getJson('/api/back-office/audit')->assertOk();
    expect($first->json('entries'))->toHaveCount(50)->and($first->json('next'))->toBeString();
    $second = ($this->as)('Halima', '135790')->getJson('/api/back-office/audit?before='.urlencode((string) $first->json('next')))->assertOk();
    $ids = [...collect($first->json('entries'))->pluck('id'), ...collect($second->json('entries'))->pluck('id')];
    expect(count($ids))->toBe(count(array_unique($ids)));

    $amina = ($this->as)('Halima', '135790')->getJson('/api/back-office/audit?person='.$this->cashier->id)->assertOk();
    expect(collect($amina->json('entries'))->pluck('who')->unique()->values()->all())->toBe(['Amina']);
    ($this->as)('Halima', '135790')->getJson('/api/back-office/audit?day='.now('Africa/Lagos')->subDays(3)->toDateString())->assertOk()->assertJsonPath('entries', []);
});

it('shows each cashier\'s speed on the daily summary, from the counter\'s own timing (check A1)', function () {
    // Three different lines: a 3-item sale in 24 seconds, one in 40, and one scanned item in 5.
    $three = fn (int $s) => ($this->sell)([$this->a->id => [1, 5000], $this->b->id => [2, 4000], (string) Catalogue::anyItem('c'.$s)->id => [1, 1000]], $s);
    $three(24);
    $three(40);
    ($this->sell)([$this->b->id => [1, 4000]], 5, ['scan']);

    $speed = ($this->as)('Kemi', '246810')->getJson('/api/back-office/reports/summary')->assertOk()->json('speed');
    expect($speed)->toHaveCount(1);
    expect($speed[0])->cashier->toBe('Amina')->timed->toBe(3);
    expect($speed[0]['three_item'])->count->toBe(2)->under_target->toBe(1)->median_ms->toBeGreaterThanOrEqual(32_000)->toBeLessThan(33_000);
    expect($speed[0]['one_scan'])->count->toBe(1)->under_target->toBe(1);
});
