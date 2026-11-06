<?php

declare(strict_types=1);

namespace App\Sales;

use App\Audit\Hasher;
use Illuminate\Support\Facades\Validator;

/**
 * What the counter sends when the cashier taps Finish (spec section 7) [F5]. The browser previews; the
 * server recalculates every amount, so nothing here is trusted beyond what it describes.
 */
final readonly class SaleRequest
{
    /**
     * @param  list<array{client_line_id: string, item_id: string, qty: int, unit_price_kobo: int, colour: ?string, added_by: string, price_source: string, approval_token: ?string, confirmed_outside_range: bool, retyped: bool, barcode: ?string, search_term: ?string}>  $lines
     * @param  list<array{method: string, amount_kobo: int, tendered_kobo: ?int, reference: ?string, alert_seen: ?bool}>  $payments
     */
    public function __construct(
        public string $idempotencyKey,
        public string $customerType,
        public array $lines,
        public array $payments,
        public ?string $parkedSaleId,
        public ?string $startedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $validator = Validator::make($data, [
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'customer_type' => ['required', 'in:retail,wholesale'],
            'started_at' => ['nullable', 'date'],
            'parked_sale_id' => ['nullable', 'string', 'size:26'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.client_line_id' => ['required', 'string', 'max:64', 'distinct'],
            'lines.*.item_id' => ['required', 'string', 'size:26'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'lines.*.unit_price_kobo' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'lines.*.colour' => ['nullable', 'string', 'max:40'],
            'lines.*.added_by' => ['required', 'in:scan,learn,search,tile,new'], // learn: an unknown scan the cashier matched [F13]
            'lines.*.price_source' => ['required', 'in:set_price,last_price,median,typed'],
            'lines.*.approval_token' => ['nullable', 'string', 'max:64'],
            'lines.*.confirmed_outside_range' => ['sometimes', 'boolean'],
            'lines.*.retyped' => ['sometimes', 'boolean'],
            'lines.*.barcode' => ['nullable', 'string', 'max:120'],
            'lines.*.search_term' => ['nullable', 'string', 'max:60'],
            'payments' => ['required', 'array', 'min:1', 'max:5'],
            'payments.*.method' => ['required', 'in:cash,transfer,pos'],
            'payments.*.amount_kobo' => ['required', 'integer', 'min:1'],
            'payments.*.tendered_kobo' => ['nullable', 'integer', 'min:1'],
            'payments.*.reference' => ['nullable', 'string', 'max:60'],
            'payments.*.alert_seen' => ['nullable', 'boolean'],
        ], [
            'lines.required' => 'The sale has no items.',
            'lines.*.unit_price_kobo.required' => 'Every item needs a price.',
            'lines.*.unit_price_kobo.min' => 'Every item needs a price.',
            'payments.required' => 'Choose how the customer paid.',
        ]);
        if ($validator->fails()) {
            throw new SaleRefused((string) $validator->errors()->first());
        }

        // The validator has checked every field above; this names their types for the code below.
        /** @var array{idempotency_key: string, customer_type: string, started_at?: string|null, parked_sale_id?: string|null, lines: list<array{client_line_id: string|int, item_id: string, qty: int|string, unit_price_kobo: int|string, colour?: string|null, added_by: string, price_source: string, approval_token?: string|null, confirmed_outside_range?: bool|int|string, retyped?: bool|int|string, barcode?: string|null, search_term?: string|null}>, payments: list<array{method: string, amount_kobo: int|string, tendered_kobo?: int|string|null, reference?: string|null, alert_seen?: bool|int|string|null}>} $data */
        $lines = [];
        foreach ($data['lines'] as $l) {
            $colour = isset($l['colour']) ? trim($l['colour']) : '';
            $lines[] = [
                'client_line_id' => (string) $l['client_line_id'],
                'item_id' => strtolower($l['item_id']),
                'qty' => (int) $l['qty'],
                'unit_price_kobo' => (int) $l['unit_price_kobo'],
                'colour' => $colour === '' ? null : $colour,
                'added_by' => $l['added_by'],
                'price_source' => $l['price_source'],
                'approval_token' => $l['approval_token'] ?? null,
                'confirmed_outside_range' => (bool) ($l['confirmed_outside_range'] ?? false),
                'retyped' => (bool) ($l['retyped'] ?? false),
                'barcode' => isset($l['barcode']) && trim($l['barcode']) !== '' ? trim($l['barcode']) : null,
                'search_term' => isset($l['search_term']) && trim($l['search_term']) !== '' ? trim($l['search_term']) : null,
            ];
        }
        $payments = [];
        foreach ($data['payments'] as $p) {
            $reference = isset($p['reference']) ? trim($p['reference']) : '';
            $payments[] = [
                'method' => $p['method'],
                'amount_kobo' => (int) $p['amount_kobo'],
                'tendered_kobo' => isset($p['tendered_kobo']) ? (int) $p['tendered_kobo'] : null,
                'reference' => $reference === '' ? null : $reference,
                'alert_seen' => isset($p['alert_seen']) ? (bool) $p['alert_seen'] : null,
            ];
        }

        return new self(
            idempotencyKey: $data['idempotency_key'],
            customerType: $data['customer_type'],
            lines: $lines,
            payments: $payments,
            parkedSaleId: isset($data['parked_sale_id']) ? strtolower($data['parked_sale_id']) : null,
            startedAt: $data['started_at'] ?? null,
        );
    }

    /**
     * The payload hash (spec section 7) [F5]: the same key with the same cart is a retry; the same key
     * with a changed cart is refused. Timings and how a line was added are not part of the sale itself.
     */
    public function payloadHash(): string
    {
        return Hasher::hash([
            'customer_type' => $this->customerType,
            'parked_sale_id' => $this->parkedSaleId,
            'lines' => array_map(fn (array $l) => [
                'client_line_id' => $l['client_line_id'], 'item_id' => $l['item_id'], 'qty' => $l['qty'],
                'unit_price_kobo' => $l['unit_price_kobo'], 'colour' => $l['colour'], 'approval_token' => $l['approval_token'],
            ], $this->lines),
            'payments' => $this->payments,
        ]);
    }

    public function totalKobo(): int
    {
        return array_sum(array_map(fn (array $l) => $l['qty'] * $l['unit_price_kobo'], $this->lines));
    }
}
