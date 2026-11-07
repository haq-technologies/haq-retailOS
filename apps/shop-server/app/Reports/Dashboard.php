<?php

declare(strict_types=1);

namespace App\Reports;

use App\Catalogue\ReviewQueue;
use App\Cost\Backfill;
use App\Health\Health;
use App\Sales\LostWindow;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The owner's dashboard for one business day (wireframe BackDashboard): sales by payment method, the
 * transfers still waiting, profit in three buckets, and what is waiting for the owner.
 */
final readonly class Dashboard
{
    public function __construct(private Settings $settings, private Flags $flags, private CashierDay $cashiers, private ReviewQueue $review, private Health $health) {}

    /**
     * The business day now: an open shift's, since a shift that runs past midnight keeps its day (spec
     * section 4) [F15]; with no shift open, today's date in the shop's timezone.
     */
    public function today(): string
    {
        $open = DB::table('shifts')->where('status', 'open')->max('business_day');
        if (is_string($open)) {
            return $open;
        }
        $zone = $this->settings->get('business.timezone');

        return CarbonImmutable::now(is_string($zone) ? $zone : 'Africa/Lagos')->toDateString();
    }

    /**
     * What the shop must put right, read live and gone once it is: sales to re-enter after a restore
     * (spec section 9), and the server's health warnings (spec section 8: a backup older than 48 hours, an
     * unverified clock, a certificate close to expiry, grant drift or a chain that does not verify, and the
     * rest of the health page). Unlike flags, these are never marked as seen.
     *
     * @return list<array{kind: string, title: string, detail: string, open: ?string}>
     */
    public function alerts(): array
    {
        $alerts = [];
        $lost = LostWindow::waiting();
        if ($lost > 0) {
            $alerts[] = ['kind' => 'lost_sales', 'title' => $lost === 1 ? '1 sale to re-enter after the restore' : "{$lost} sales to re-enter after the restore",
                'detail' => 'A counter journal holds sales the restored data lacks; customers may hold receipts for them.', 'open' => 'recovery'];
        }
        foreach ($this->health->warnings() as $w) {
            $alerts[] = [...$w, 'kind' => 'health_'.$w['kind'], 'open' => 'server'];
        }

        return $alerts;
    }

    /**
     * @return array<string, mixed>
     */
    public function forDay(string $day, bool $withCost = false): array
    {
        $sales = DailySummary::sales($day);
        /** @var object{n: int|string, amount: int|string} $waiting */
        $waiting = DB::selectOne("SELECT count(*) AS n, coalesce(sum(amount_kobo), 0) AS amount FROM parked_sales WHERE kind = 'awaiting_transfer' AND status = 'open'");
        $opened = DB::table('shifts')->where('business_day', $day)->min('opened_at');
        $counters = DB::table('sales')->where('business_day', $day)->distinct()->count('terminal_id');
        [$covered, $total] = Backfill::coverage();
        // Needs your attention: the week's serious flags nobody has marked as seen yet, newest first.
        $attention = array_values(array_filter(
            $this->flags->list(Period::named('last7', $this->flags->zone()), $withCost, Flags::ATTENTION),
            fn (array $f) => $f['seen_at'] === null,
        ));

        return [
            'day' => $day,
            'opened_at' => is_string($opened) ? $opened : null,
            'counters_selling' => $counters,
            'sales' => $sales,
            'transfers_waiting' => ['count' => (int) $waiting->n, 'amount_kobo' => (int) $waiting->amount],
            'profit' => Profit::buckets($day, $day),
            'coverage_percent' => $total === 0 ? 0 : intdiv($covered * 100, $total),
            'attention' => ['total' => count($attention), 'flags' => array_slice($attention, 0, 5)],
            'alerts' => $alerts = $this->alerts(),
            // The dashboard's line when every server check passes (wireframe BackDashboard).
            'system' => array_filter($alerts, fn (array $a) => str_starts_with($a['kind'], 'health_')) === [] ? $this->health->allClear() : null,
            'cashiers' => $this->cashiers->forDay($day),
            'todo' => [
                'needs_cost' => NeedsCost::queue(limit: 0)['total_items'],
                'flags_week' => count($this->flags->list(Period::named('week', $this->flags->zone()), false)),
                'review' => $this->review->total(),
                'first_sales' => count(array_filter(FirstSales::forDay($day, Period::day($day, $this->flags->zone())), fn (array $g) => $g['set'] === null)),
            ],
        ];
    }
}
