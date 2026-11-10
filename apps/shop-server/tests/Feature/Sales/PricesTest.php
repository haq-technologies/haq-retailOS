<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\ApprovalAction;
use App\Identity\Approvals;
use App\Identity\Enrolment;
use App\Identity\Role;
use App\Platform\Money;
use App\Sales\ApprovalNeeded;
use App\Sales\Finish;
use App\Sales\Parked;
use App\Sales\Pricing;
use App\Sales\SaleRefused;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Catalogue;
use Tests\Support\Counter;
use Tests\Support\People;

/*
 * Prices (spec section 7; checks in section 14 A and C) [S4, S5, F11].
 */

beforeEach(function () {
    $this->counter = Counter::ready(items: 1);
    [$this->t1] = $this->counter['terminals'];
    $this->cashier = $this->counter['cashier'];
    [$this->item] = $this->counter['items'];
    $this->pricing = app(Pricing::class);
    $this->manager = People::add('Bola', Role::Manager, '135790');
});

/** Sells the item once at each price. */
function history(object $item, array $prices, string $type = 'retail'): void
{
    foreach ($prices as $price) {
        $sale = Counter::cashSale([$item->id => [1, $price]], ['customer_type' => $type]);
        $sale['lines'][0]['confirmed_outside_range'] = true;
        $sale['lines'][0]['retyped'] = true;
        app(Finish::class)->finish(test()->t1, test()->cashier, Counter::request($sale));
    }
}

/** A one-line sale of the item at a price, with options for the line. */
function priced(object $item, int $price, array $line = []): array
{
    $sale = Counter::cashSale([$item->id => [1, $price]]);
    $sale['lines'][0] = [...$sale['lines'][0], ...$line];

    return $sale;
}

it('prints naira as the spec writes it', function () {
    expect(Money::naira(250_000))->toBe('₦2,500');
    expect(Money::naira(250_050))->toBe('₦2,500.50');
    expect(Money::naira(5))->toBe('₦0.05');
});

it('fills in the set price first, then the last price sold, or the median by shop setting (S4)', function () {
    expect($this->pricing->info($this->item, 'retail')['prefill'])->toBeNull();

    history($this->item, [250_000, 260_000, 300_000]);
    expect($this->pricing->info($this->item, 'retail'))->toMatchArray(['prefill' => 300_000, 'prefill_source' => 'last_price']);

    app(Settings::class)->set('pricing.fill_fallback', 'median', 'console');
    expect($this->pricing->info($this->item, 'retail'))->toMatchArray(['prefill' => 260_000, 'prefill_source' => 'median']);

    $this->pricing->set($this->item, 'retail', 280_000, $this->manager);
    expect($this->pricing->info($this->item, 'retail'))->toMatchArray(['prefill' => 280_000, 'prefill_source' => 'set_price']);
    // Wholesale is separate.
    expect($this->pricing->info($this->item, 'wholesale')['prefill'])->toBeNull();
});

it('lets only a manager or the owner set a price', function () {
    expect(fn () => $this->pricing->set($this->item, 'retail', 280_000, $this->cashier))->toThrow(SaleRefused::class, 'Only a manager or the owner sets prices');
    $this->pricing->set($this->item, 'retail', 280_000, $this->manager);
    expect(DB::table('audit_log')->where('action', 'price.set')->count())->toBe(1);
});

it('has no range before five sales, and puts those first sales on the first-sale list', function () {
    history($this->item, [250_000, 260_000, 270_000, 280_000]);
    expect($this->pricing->range($this->item, 'retail'))->toBeNull();
    expect(DB::table('sale_lines')->whereRaw("flags->>'first_sale' = 'true'")->count())->toBe(4);

    history($this->item, [290_000]);
    expect($this->pricing->range($this->item, 'retail'))->toBe(['low' => 250_000, 'high' => 290_000, 'source' => 'history']);
});

it('uses the 10th to 90th percentile of the last 20 sales', function () {
    history($this->item, [100_000, 250_000, 250_000, 260_000, 270_000, 270_000, 280_000, 290_000, 300_000, 900_000]);
    expect($this->pricing->range($this->item, 'retail'))->toBe(['low' => 100_000, 'high' => 300_000, 'source' => 'history']);
});

describe('with a usual range of ₦2,500 to ₦3,000', function () {
    beforeEach(fn () => history($this->item, [250_000, 260_000, 270_000, 280_000, 300_000]));

    it('asks nothing inside the range', function () {
        $sale = app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(priced($this->item, 270_000)));
        expect($sale['receipt_number'])->not->toBeEmpty();
    });

    it('needs a one-tap confirm outside it, in the spec\'s words', function () {
        expect(fn () => app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(priced($this->item, 200_000))))
            ->toThrow(SaleRefused::class, 'Usual ₦2,500 to ₦3,000. Sell Thing item 1 at ₦2,000?');
        app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(priced($this->item, 200_000, ['confirmed_outside_range' => true])));
        expect(DB::table('sale_lines')->orderByDesc('id')->whereRaw("flags->>'outside' = 'below'")->count())->toBe(1);
    });

    it('needs a retype above three times the top, to catch a slipped zero', function () {
        expect(fn () => app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(priced($this->item, 2_700_000, ['confirmed_outside_range' => true]))))
            ->toThrow(SaleRefused::class, 'more than three times the usual price. Retype the price');
        app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(priced($this->item, 2_700_000, ['retyped' => true])));
    });

    it('needs a manager below the floor, and binds the approval to the line', function () {
        $sale = priced($this->item, 100_000);
        expect(fn () => app(Finish::class)->finish($this->t1, $this->cashier, Counter::request($sale)))
            ->toThrow(SaleRefused::class, 'is below the floor. A manager must approve this price.');

        $approval = app(Approvals::class)->grant($this->t1, $this->cashier->id, $this->manager->id, '135790', ApprovalAction::PriceBelowFloor,
            $sale['lines'][0]['client_line_id'], $this->item->id, 100_000);
        $sale['lines'][0]['approval_token'] = $approval['token'];
        app(Finish::class)->finish($this->t1, $this->cashier, Counter::request($sale));

        expect(DB::table('sale_lines')->whereNotNull('approval_id')->value('approval_id'))->toBe($approval['id']);
        expect(DB::table('approvals')->where('id', $approval['id'])->value('bound_at'))->not->toBeNull();
    });

    it('refuses an approval granted for a different price', function () {
        $sale = priced($this->item, 100_000);
        $approval = app(Approvals::class)->grant($this->t1, $this->cashier->id, $this->manager->id, '135790', ApprovalAction::PriceBelowFloor,
            $sale['lines'][0]['client_line_id'], $this->item->id, 120_000);
        $sale['lines'][0]['approval_token'] = $approval['token'];

        expect(fn () => app(Finish::class)->finish($this->t1, $this->cashier, Counter::request($sale)))
            ->toThrow(ApprovalNeeded::class, 'The line changed after it was approved');
        expect(DB::table('sale_lines')->whereNotNull('approval_id')->count())->toBe(0);
    });

    it('keeps a manager\'s approval bound to its line while the sale goes on, here 5 minutes (S3, check D4)', function () {
        $sale = priced($this->item, 100_000);
        $approval = app(Approvals::class)->grant($this->t1, $this->cashier->id, $this->manager->id, '135790', ApprovalAction::PriceBelowFloor,
            $sale['lines'][0]['client_line_id'], $this->item->id, 100_000);
        expect(DB::table('approvals')->where('id', $approval['id'])->value('bound_at'))->not->toBeNull();

        $this->travel(5)->minutes();
        $sale['lines'][0]['approval_token'] = $approval['token'];
        app(Finish::class)->finish($this->t1, $this->cashier, Counter::request($sale));
        expect(DB::table('sale_lines')->whereNotNull('approval_id')->value('approval_id'))->toBe($approval['id']);
    });

    it('keeps the approval through a slow transfer, and another counter finishes the sale (check D4)', function () {
        [, $t2] = $this->counter['terminals'];
        $sale = priced($this->item, 100_000);
        $approval = app(Approvals::class)->grant($this->t1, $this->cashier->id, $this->manager->id, '135790', ApprovalAction::PriceBelowFloor,
            $sale['lines'][0]['client_line_id'], $this->item->id, 100_000);
        $sale['lines'][0]['approval_token'] = $approval['token'];
        $sale['payments'] = [['method' => 'transfer', 'amount_kobo' => 100_000, 'reference' => 'Chidi', 'alert_seen' => true]];
        $parked = app(Parked::class)->park($this->t1, $this->cashier, 'awaiting_transfer', 'Chidi', $sale, 100_000);

        $this->travel(40)->minutes();
        $done = app(Finish::class)->finish($t2, $this->cashier, Counter::request([...$sale, 'parked_sale_id' => $parked]));
        expect($done['receipt_number'])->toStartWith('T02-');
        expect(DB::table('sale_lines')->whereNotNull('approval_id')->value('approval_id'))->toBe($approval['id']);
    });

    it('uses an approval once, and asks for the manager again when it no longer holds', function () {
        [, $t2] = $this->counter['terminals'];
        // A set price keeps the usual range still, so ₦1,000 stays below the floor after the first sale.
        $this->pricing->set($this->item, 'retail', 270_000, $this->manager);
        $sale = priced($this->item, 100_000);
        $approval = app(Approvals::class)->grant($this->t1, $this->cashier->id, $this->manager->id, '135790', ApprovalAction::PriceBelowFloor,
            $sale['lines'][0]['client_line_id'], $this->item->id, 100_000);
        $sale['lines'][0]['approval_token'] = $approval['token'];
        app(Finish::class)->finish($this->t1, $this->cashier, Counter::request($sale));

        // The same token on a second sale: the counter is asked for a fresh approval, with the line it is for.
        $again = [...$sale, 'idempotency_key' => (string) Str::uuid()];
        try {
            app(Finish::class)->finish($this->t1, $this->cashier, Counter::request($again));
            $this->fail('The approval was used twice.');
        } catch (ApprovalNeeded $e) {
            expect($e->getMessage())->toContain('already been used');
            expect($e->toArray())->toBe(['action' => 'price_below_floor', 'subject_id' => $sale['lines'][0]['client_line_id'], 'item_id' => $this->item->id, 'price_kobo' => 100_000]);
        }

        // Another counter's approval works only when that counter finishes a held cart.
        $other = priced($this->item, 100_000);
        $grant = app(Approvals::class)->grant($this->t1, $this->cashier->id, $this->manager->id, '135790', ApprovalAction::PriceBelowFloor,
            $other['lines'][0]['client_line_id'], $this->item->id, 100_000);
        $other['lines'][0]['approval_token'] = $grant['token'];
        expect(fn () => app(Finish::class)->finish($t2, $this->cashier, Counter::request($other)))->toThrow(ApprovalNeeded::class, 'not for this counter');
        expect(DB::table('sale_lines')->whereNotNull('approval_id')->count())->toBe(1);
    });

    it('answers an approval that no longer holds with the approval to ask for, over HTTP', function () {
        $device = People::enrol($this->t1);
        $token = (string) $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $this->cashier->id, 'pin' => '1357'])->json('token');
        $sale = priced($this->item, 100_000, ['approval_token' => 'no-such-approval']);

        $this->withCookie(Enrolment::COOKIE, $device)->withHeader(ActingToken::HEADER, $token)->postJson('/api/counter/sales', $sale)
            ->assertStatus(422)->assertJsonPath('approval.action', 'price_below_floor')
            ->assertJsonPath('approval.subject_id', $sale['lines'][0]['client_line_id'])->assertJsonPath('approval.price_kobo', 100_000);
    });

    it('needs a manager for the fourth below-range confirm in a shift (S5)', function () {
        // A set price keeps the usual range still while the confirms add up.
        $this->pricing->set($this->item, 'retail', 270_000, $this->manager);
        foreach (range(1, 3) as $i) {
            app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(priced($this->item, 200_000, ['confirmed_outside_range' => true])));
        }
        expect(fn () => app(Finish::class)->finish($this->t1, $this->cashier, Counter::request(priced($this->item, 200_000, ['confirmed_outside_range' => true]))))
            ->toThrow(SaleRefused::class, 'A manager must approve this one.');
    });
});

it('inherits a range from the sibling sharing most facets, else from the category (D30)', function () {
    $close = Catalogue::gadget(['model' => 'X1', 'size' => 'Large']);
    $far = Catalogue::gadget(['model' => 'Z9', 'size' => 'Small']);
    $new = Catalogue::gadget(['model' => 'X1', 'size' => 'Medium']);
    // The far sibling sells first, with nothing to inherit; the close one then inherits from it until it has its own history.
    history($far, [100_000, 110_000, 120_000, 130_000, 140_000, 150_000]);
    history($close, [500_000, 520_000, 540_000, 560_000, 580_000]);

    expect($this->pricing->range($new, 'retail'))->toBe(['low' => 500_000, 'high' => 580_000, 'source' => 'sibling']);

    $lonely = Catalogue::gadget(['model' => 'Q', 'size' => 'Tiny'], 'test_other');
    expect($this->pricing->range($lonely, 'retail'))->toBeNull();
});

it('serves the price info to the counter and lets a manager set a price over HTTP', function () {
    $device = People::enrol($this->t1);
    $token = fn (string $id, string $pin) => (string) $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $id, 'pin' => $pin])->json('token');
    $headers = fn (string $t) => [ActingToken::HEADER => $t];

    $this->withCookie(Enrolment::COOKIE, $device)->withHeaders($headers($token($this->cashier->id, '1357')))
        ->postJson("/api/counter/items/{$this->item->id}/prices", ['customer_type' => 'retail', 'price_kobo' => 150_000])->assertForbidden();

    $this->withCookie(Enrolment::COOKIE, $device)->withHeaders($headers($token($this->manager->id, '135790')))
        ->postJson("/api/counter/items/{$this->item->id}/prices", ['customer_type' => 'retail', 'price_kobo' => 150_000])
        ->assertOk()->assertJsonPath('set_price', 150_000)->assertJsonPath('range.source', 'set_price');
});
