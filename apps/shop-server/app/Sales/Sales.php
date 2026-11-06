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
     * @return array<string, mixed>
     */
    public static function summary(string $saleId): array
    {
        $sale = DB::table('sales')->where('id', $saleId)->first();
        if ($sale === null) {
            throw new \RuntimeException("No sale {$saleId}.");
        }
        $lines = DB::table('sale_lines')->where('sale_id', $saleId)->orderBy('line_no')
            ->get(['client_line_id', 'name_snapshot', 'colour_note', 'qty', 'unit_price_kobo', 'line_total_kobo']);
        $payments = DB::table('payments')->where('sale_id', $saleId)->get(['method', 'amount_kobo', 'tendered_kobo', 'change_kobo', 'reference']);
        $print = DB::table('print_jobs')->where('sale_id', $saleId)->where('kind', 'receipt')->value('status');
        $cashier = DB::table('users')->where('id', $sale->cashier_id)->value('name');

        return [
            'id' => $sale->id,
            'receipt_number' => $sale->receipt_number,
            'business_day' => $sale->business_day,
            'recorded_at' => $sale->recorded_at,
            'customer_type' => $sale->customer_type,
            'cashier' => $cashier,
            'total_kobo' => (int) $sale->total_kobo,
            'change_kobo' => (int) $payments->sum(fn (object $p) => (int) ($p->change_kobo ?? 0)),
            'lines' => $lines->map(fn (object $l) => [
                'client_line_id' => $l->client_line_id, 'name' => $l->name_snapshot, 'colour' => $l->colour_note,
                'qty' => (int) $l->qty, 'unit_price_kobo' => (int) $l->unit_price_kobo, 'line_total_kobo' => (int) $l->line_total_kobo,
            ])->all(),
            'payments' => $payments->map(fn (object $p) => [
                'method' => $p->method, 'amount_kobo' => (int) $p->amount_kobo,
                'tendered_kobo' => $p->tendered_kobo === null ? null : (int) $p->tendered_kobo,
                'change_kobo' => $p->change_kobo === null ? null : (int) $p->change_kobo, 'reference' => $p->reference,
            ])->all(),
            'print_status' => $print,
            'retried' => false,
        ];
    }
}
