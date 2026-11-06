<?php

declare(strict_types=1);

namespace App\Counter;

use App\Catalogue\Learning;
use App\Sales\Events\SaleFinished;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * After a sale commits: each scanned line confirms its barcode link, and each searched line counts towards
 * its alias (spec section 6) [F13]. Outside the sale's transaction, so learning never holds a sale up; a
 * failure here is reported and the sale stands.
 */
final readonly class LearnFromSale
{
    public function __construct(private Learning $learning) {}

    public function handle(SaleFinished $event): void
    {
        try {
            $cashier = DB::table('sales')->where('id', $event->saleId)->value('cashier_id');
            foreach (DB::table('sale_lines')->where('sale_id', $event->saleId)->get(['item_id', 'flags']) as $line) {
                /** @var array{barcode?: string, learned?: bool, search_term?: string} $flags */
                $flags = json_decode((string) $line->flags, true) ?: [];
                // The sale that taught a code does not confirm it: two further sales do [F13].
                if (isset($flags['barcode']) && ! ($flags['learned'] ?? false)) {
                    $this->learning->recordScan($flags['barcode'], (string) $line->item_id, is_string($cashier) ? $cashier : null);
                }
                if (isset($flags['search_term'])) {
                    $this->learning->recordSearch($flags['search_term'], (string) $line->item_id);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
