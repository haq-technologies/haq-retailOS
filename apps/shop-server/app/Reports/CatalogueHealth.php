<?php

declare(strict_types=1);

namespace App\Reports;

use App\Catalogue\Merges;
use App\Catalogue\ReviewQueue;
use App\Cost\Backfill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue health (spec section 12) [v1.3; wireframe CatalogueHealth]: the share of revenue with a cost
 * against the pilot's goal (80% by day 14), what waits in review, how many items sold have a barcode, and
 * the best sellers the counter has not learned a barcode for yet. Percentages and counts only: no money,
 * so everyone who reviews the catalogue may see it.
 */
final readonly class CatalogueHealth
{
    public const GOAL_PERCENT = 80;

    public const GOAL_DAY = 14;

    public function __construct(private ReviewQueue $review) {}

    /**
     * @return array<string, mixed>
     */
    public function now(string $zone): array
    {
        [$covered, $total] = Backfill::coverage();
        $buckets = Profit::buckets();
        $first = DB::table('sales')->whereIn('kind', ['sale', 'back_entry'])->min('recorded_at');
        $since = is_string($first) ? CarbonImmutable::parse($first)->setTimezone($zone)->startOfDay() : null;

        // How many of the top Needs-cost items would pass the goal, entered in revenue order.
        $toGoal = null;
        if ($total > 0 && $covered * 100 < $total * self::GOAL_PERCENT) {
            $sum = $covered;
            foreach (NeedsCost::queue(limit: PHP_INT_MAX)['items'] as $i => $item) {
                $sum += $item['revenue_kobo'];
                if ($sum * 100 >= $total * self::GOAL_PERCENT) {
                    $toGoal = $i + 1;
                    break;
                }
            }
        }

        /** @var array{counts: array{names: int, items: int, barcodes: int, duplicates: int}, brands: list<mixed>, models: list<mixed>, words: list<mixed>, learned: list<mixed>, conflicts: list<mixed>} $queue */
        $queue = $this->review->all();
        $roots = Merges::rootsCte();
        /** @var object{sold: int|string, with_code: int|string} $codes */
        $codes = DB::selectOne(<<<SQL
            WITH RECURSIVE {$roots},
            sold AS (SELECT DISTINCT r.root_id FROM sale_lines l JOIN sales s ON s.id = l.sale_id JOIN item_roots r ON r.item_id = l.item_id WHERE s.kind IN ('sale', 'back_entry'))
            SELECT count(*) AS sold,
                   count(*) FILTER (WHERE EXISTS (SELECT 1 FROM barcodes b JOIN item_roots br ON br.item_id = b.item_id WHERE br.root_id = sold.root_id AND b.status <> 'disabled')) AS with_code
            FROM sold
            SQL);

        return [
            'since' => $since?->toDateString(),
            'coverage_percent' => $total === 0 ? 0 : intdiv($covered * 100, $total),
            'buckets' => ['confirmed' => $buckets['confirmed']['percent'], 'estimated' => $buckets['estimated']['percent'], 'pending' => $buckets['pending']['percent']],
            'goal' => ['percent' => self::GOAL_PERCENT, 'by' => $since?->addDays(self::GOAL_DAY - 1)->toDateString(), 'items_to_goal' => $toGoal],
            'review' => [
                'new_items' => $queue['counts']['items'],
                'names' => $queue['counts']['names'], 'brands' => count($queue['brands']), 'models' => count($queue['models']), 'words' => count($queue['words']),
                'barcodes' => $queue['counts']['barcodes'], 'learned' => count($queue['learned']), 'conflicts' => count($queue['conflicts']),
                'duplicates' => $queue['counts']['duplicates'],
            ],
            'barcodes' => ['items_sold' => (int) $codes->sold, 'with_barcode' => (int) $codes->with_code,
                'percent' => (int) $codes->sold === 0 ? 0 : intdiv((int) $codes->with_code * 100, (int) $codes->sold)],
            'no_barcode' => $this->bestSellersWithoutCode(),
        ];
    }

    /**
     * The items sold most that have no barcode yet, with how cashiers added them (spec section 6) [S8].
     *
     * @return list<array{item_id: string, name: string, sold: int, tiles: int, search: int, other: int}>
     */
    private function bestSellersWithoutCode(): array
    {
        $roots = Merges::rootsCte();
        /** @var list<object{item_id: string, name: string, sold: int|string, tiles: int|string, search: int|string, other: int|string}> $rows */
        $rows = DB::select(<<<SQL
            WITH RECURSIVE {$roots}
            SELECT r.root_id AS item_id, i.display_name AS name, sum(l.qty) AS sold,
                   count(*) FILTER (WHERE t.lines->>(l.line_no - 1) = 'tile') AS tiles,
                   count(*) FILTER (WHERE t.lines->>(l.line_no - 1) = 'search') AS search,
                   count(*) FILTER (WHERE coalesce(t.lines->>(l.line_no - 1), '') NOT IN ('tile', 'search')) AS other
            FROM sale_lines l JOIN sales s ON s.id = l.sale_id JOIN item_roots r ON r.item_id = l.item_id JOIN items i ON i.id = r.root_id
            LEFT JOIN sale_timings t ON t.sale_id = s.id
            WHERE s.kind IN ('sale', 'back_entry') AND NOT EXISTS (
                SELECT 1 FROM barcodes b JOIN item_roots br ON br.item_id = b.item_id WHERE br.root_id = r.root_id AND b.status <> 'disabled'
            )
            GROUP BY r.root_id, i.display_name
            ORDER BY sold DESC, name LIMIT 10
            SQL);

        return array_map(fn (object $r) => ['item_id' => $r->item_id, 'name' => $r->name, 'sold' => (int) $r->sold,
            'tiles' => (int) $r->tiles, 'search' => (int) $r->search, 'other' => (int) $r->other], $rows);
    }
}
