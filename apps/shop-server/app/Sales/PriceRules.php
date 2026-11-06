<?php

declare(strict_types=1);

namespace App\Sales;

use App\Catalogue\Models\Item;
use App\Identity\ApprovalAction;
use App\Identity\ApprovalRefused;
use App\Identity\Approvals;
use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Platform\Money;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * The price checks Finish enforces on the server (spec section 7) [F11, S4, S5]. The counter asks first,
 * so a cashier sees these as prompts; the server checks again, so a tampered browser cannot skip them.
 *
 * - Inside the usual range: nothing to ask.
 * - Outside it: the cashier confirms with one tap. More than 3 times the top: the cashier retypes it.
 * - Below the floor (under 50% of the bottom), or a fourth below-range confirm in the shift: a manager's
 *   approval, bound to this line, item and price.
 * - No range yet: no prompt, and the line goes on the first-sale price list.
 *
 * Runs inside the sale's transaction, so an approval binds only if the sale commits.
 */
class PriceRules
{
    public function __construct(
        private readonly Pricing $pricing,
        private readonly Approvals $approvals,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<string, Item>  $items
     * @return list<array{approval_id: ?string, flags: array<string, mixed>}> One entry per line, in order.
     */
    public function check(Terminal $terminal, User $cashier, SaleRequest $request, array $items, string $shiftId): array
    {
        $floorPercent = $this->int('pricing.floor_percent', 50);
        $confirmLimit = $this->int('pricing.below_range_confirms', 3);
        $retypeMultiple = $this->int('pricing.retype_multiple', 3);
        $confirmsUsed = $this->belowRangeConfirmsUsed($shiftId, $cashier->id);

        $checked = [];
        foreach ($request->lines as $line) {
            $item = $items[$line['item_id']];
            $price = $line['unit_price_kobo'];
            $range = $this->pricing->range($item, $request->customerType);
            $flags = [];
            $approvalId = null;

            if ($range === null) {
                $flags['first_sale'] = true;
            } else {
                $flags['range'] = [$range['low'], $range['high'], $range['source']];
                $usual = $range['low'] === $range['high']
                    ? 'Usual '.Money::naira($range['low'])
                    : 'Usual '.Money::naira($range['low']).' to '.Money::naira($range['high']);

                if ($price > $range['high']) {
                    $flags['outside'] = 'above';
                    if ($price > $range['high'] * $retypeMultiple) {
                        if (! $line['retyped']) {
                            throw new PriceNeedsCheck("{$item->display_name} at ".Money::naira($price).' is more than three times the usual price. Retype the price to make sure.', $line['client_line_id'], 'retype');
                        }
                        $flags['retyped'] = true;
                    } elseif (! $line['confirmed_outside_range']) {
                        throw new PriceNeedsCheck("{$usual}. Confirm ".Money::naira($price)." for {$item->display_name}.", $line['client_line_id'], 'confirm');
                    }
                    $flags['confirmed'] = true;
                } elseif ($price < $range['low']) {
                    $flags['outside'] = 'below';
                    $floor = intdiv($range['low'] * $floorPercent, 100);
                    if ($price < $floor) {
                        $approvalId = $this->bind($terminal, $line, ApprovalAction::PriceBelowFloor,
                            "{$item->display_name} at ".Money::naira($price).' is below the floor. A manager must approve this price.');
                        $flags['below_floor'] = true;
                    } elseif ($confirmsUsed >= $confirmLimit) {
                        $approvalId = $this->bind($terminal, $line, ApprovalAction::BelowRangeConfirmLimit,
                            'That is more prices below the usual range than one shift allows. A manager must approve this one.');
                    } elseif (! $line['confirmed_outside_range']) {
                        throw new PriceNeedsCheck("{$usual}. Sell {$item->display_name} at ".Money::naira($price).'?', $line['client_line_id'], 'confirm');
                    } else {
                        $flags['confirmed'] = true;
                        $confirmsUsed++;
                    }
                }
            }

            $checked[] = ['approval_id' => $approvalId, 'flags' => $flags];
        }

        return $checked;
    }

    /**
     * @param  array{client_line_id: string, item_id: string, unit_price_kobo: int, approval_token: ?string}  $line
     */
    private function bind(Terminal $terminal, array $line, ApprovalAction $action, string $needed): string
    {
        if ($line['approval_token'] === null) {
            throw new ApprovalNeeded($needed, $action, $line['client_line_id'], $line['item_id'], $line['unit_price_kobo']);
        }
        try {
            return $this->approvals->bind($terminal, $line['approval_token'], $action, $line['client_line_id'], $line['item_id'], $line['unit_price_kobo']);
        } catch (ApprovalRefused $e) {
            throw new SaleRefused($e->getMessage());
        }
    }

    /** Below-range prices this cashier confirmed without a manager in this shift [S5]. */
    private function belowRangeConfirmsUsed(string $shiftId, string $cashierId): int
    {
        return DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->where('s.shift_id', $shiftId)->where('s.cashier_id', $cashierId)
            ->whereRaw("l.flags->>'outside' = 'below'")->whereNull('l.approval_id')->count();
    }

    private function int(string $key, int $default): int
    {
        $value = $this->settings->get($key);

        return is_int($value) ? $value : $default;
    }
}
