export interface Can {
    dashboard: boolean;
    profit: boolean;
    costs: boolean;
    prices: boolean;
    review: boolean;
    flags: boolean;
    summary: boolean;
    catalogue: boolean;
    settings: boolean;
    health: boolean;
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
    todo: { needs_cost: number };
}
