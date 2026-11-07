<?php

declare(strict_types=1);

namespace App\Sales;

use Illuminate\Support\Facades\DB;

/**
 * Reads a finished sale as the counter shows it and the receipt prints it. No cost appears here: cost
 * never leaves the server for a role that may not see it (spec section 2) [F10].
 */
final class Sales
{
    /**
     * @return array{id: string, receipt_number: string, business_day: string, recorded_at: string, customer_type: string, cashier: string, cashier_id: string, total_kobo: int, change_kobo: int, lines: list<array{client_line_id: string, item_id: string, name: string, colour: ?string, qty: int, unit_price_kobo: int, line_total_kobo: int}>, payments: list<array{method: string, amount_kobo: int, tendered_kobo: ?int, change_kobo: ?int, reference: ?string}>, print_status: ?string, retried: bool}
     */
    public static function summary(string $saleId): array
    {
        /** @var object{id: string, receipt_number: string, business_day: string, recorded_at: string, customer_type: string, cashier_id: string, total_kobo: int}|null $sale */
        $sale = DB::table('sales')->where('id', $saleId)->first(['id', 'receipt_number', 'business_day', 'recorded_at', 'customer_type', 'cashier_id', 'total_kobo']);
        if ($sale === null) {
            throw new \RuntimeException("No sale {$saleId}.");
        }
        /** @var list<object{client_line_id: string, item_id: string, name_snapshot: string, colour_note: ?string, qty: int, unit_price_kobo: int, line_total_kobo: int}> $lines */
        $lines = DB::table('sale_lines')->where('sale_id', $saleId)->orderBy('line_no')
            ->get(['client_line_id', 'item_id', 'name_snapshot', 'colour_note', 'qty', 'unit_price_kobo', 'line_total_kobo'])->all();
        /** @var list<object{method: string, amount_kobo: int, tendered_kobo: ?int, change_kobo: ?int, reference: ?string}> $payments */
        $payments = DB::table('payments')->where('sale_id', $saleId)->orderBy('id')->get(['method', 'amount_kobo', 'tendered_kobo', 'change_kobo', 'reference'])->all();
        $print = DB::table('print_jobs')->where('sale_id', $saleId)->where('kind', 'receipt')->value('status');
        $cashier = DB::table('users')->where('id', $sale->cashier_id)->value('name');

        return [
            'id' => $sale->id,
            'receipt_number' => $sale->receipt_number,
            'business_day' => $sale->business_day,
            'recorded_at' => $sale->recorded_at,
            'customer_type' => $sale->customer_type,
            'cashier' => is_string($cashier) ? $cashier : '',
            // The counter's journal keeps who sold it and what, so a sale lost to a restore can be re-entered.
            'cashier_id' => $sale->cashier_id,
            'total_kobo' => (int) $sale->total_kobo,
            'change_kobo' => array_sum(array_map(fn (object $p) => (int) ($p->change_kobo ?? 0), $payments)),
            'lines' => array_map(fn (object $l) => [
                'client_line_id' => $l->client_line_id, 'item_id' => $l->item_id, 'name' => $l->name_snapshot, 'colour' => $l->colour_note,
                'qty' => (int) $l->qty, 'unit_price_kobo' => (int) $l->unit_price_kobo, 'line_total_kobo' => (int) $l->line_total_kobo,
            ], $lines),
            'payments' => array_map(fn (object $p) => [
                'method' => $p->method, 'amount_kobo' => (int) $p->amount_kobo,
                'tendered_kobo' => $p->tendered_kobo === null ? null : (int) $p->tendered_kobo,
                'change_kobo' => $p->change_kobo === null ? null : (int) $p->change_kobo, 'reference' => $p->reference,
            ], $payments),
            'print_status' => is_string($print) ? $print : null,
            'retried' => false,
        ];
    }
}
