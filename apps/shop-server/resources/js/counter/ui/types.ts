/** What Finish answers with (app/Sales/Sales.php summary). */
export interface SaleSummary {
    id: string;
    receipt_number: string;
    business_day: string;
    recorded_at: string;
    customer_type: string;
    cashier: string;
    total_kobo: number;
    change_kobo: number;
    lines: { client_line_id: string; name: string; colour: string | null; qty: number; unit_price_kobo: number; line_total_kobo: number }[];
    payments: { method: string; amount_kobo: number; tendered_kobo: number | null; change_kobo: number | null; reference: string | null }[];
    print_status: string | null;
    retried: boolean;
}

export interface FinishAnswer {
    sale?: SaleSummary;
    message?: string;
    check?: { client_line_id: string; kind: 'confirm' | 'retype' };
    approval?: { action: string; subject_id: string; item_id: string | null; price_kobo: number | null };
}

export interface ParkedEntry {
    id: string;
    kind: 'hold' | 'awaiting_transfer';
    label: string | null;
    amount_kobo: number;
    parked_by: string;
    parked_by_name: string;
    terminal: string;
    parked_at: string;
    age_seconds: number;
    flagged: boolean;
}

/**
 * A random UUID (version 4) for line IDs and idempotency keys. crypto.randomUUID exists only on HTTPS or
 * localhost, so this builds one from getRandomValues, which works everywhere.
 */
export function newId(): string {
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = ((b[6] ?? 0) & 0x0f) | 0x40;
    b[8] = ((b[8] ?? 0) & 0x3f) | 0x80;
    const hex = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
