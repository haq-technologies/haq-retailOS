export interface Can {
    dashboard: boolean;
    profit: boolean;
    costs: boolean;
    prices: boolean;
    review: boolean;
    names: boolean;
    categories: boolean;
    promote: boolean;
    flags: boolean;
    summary: boolean;
    catalogue: boolean;
    settings: boolean;
    health: boolean;
    recovery: boolean;
    audit: boolean;
}

export interface Me {
    user: { id: string; name: string; role: string };
    can: Can;
}

export interface Bucket {
    revenue_kobo: number;
    profit_kobo?: number;
    percent: number;
}

export interface Buckets {
    revenue_kobo: number;
    confirmed: Bucket;
    estimated: Bucket;
    pending: Bucket;
}

export interface DashboardData {
    day: string;
    opened_at: string | null;
    counters_selling: number;
    sales: { count: number; total_kobo: number; by_method: { method: string; sales: number; amount_kobo: number }[] };
    transfers_waiting: { count: number; amount_kobo: number };
    profit: Buckets;
    coverage_percent: number;
    attention: { total: number; flags: Flag[] };
    /** Read live and gone once put right: sales to re-enter after a restore, and the server's health warnings. */
    alerts: { kind: string; title: string; detail: string; open: string | null }[];
    /** "System checks passed at 06:00: …" when every server check passes. */
    system: string | null;
    cashiers: CashierRow[];
    todo: { needs_cost: number; flags_week: number; review: number; first_sales: number };
}

/** One flag (spec section 8). cost_kobo is there only for those who may see cost. */
export interface Flag {
    key: string;
    kind: string;
    label: string;
    at: string;
    title: string;
    detail: string;
    who: string | null;
    cashier_id: string | null;
    approver: string | null;
    terminal: string | null;
    sale_ids: string[];
    receipt_number: string | null;
    amount_kobo: number | null;
    cost_kobo?: number;
    live: boolean;
    seen_by: string | null;
    seen_at: string | null;
}

export interface CashierRow {
    id: string;
    name: string;
    role: string;
    sales: number;
    below_range: number;
    below_range_limit: number;
    needs_manager: boolean;
    discarded: number;
    items_created: number;
    no_reference: number;
    no_sale: number;
    key_open: number;
    transfers_waited: number;
}
