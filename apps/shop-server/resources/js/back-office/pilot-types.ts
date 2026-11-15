/** What the measured pilot's screens read from the server (D56). Totals and percentages only, never cost. */
export type Status = 'passed' | 'failed' | 'open';

export interface DayRow {
    n: number;
    day: string;
    today: boolean;
    app_kobo: number;
    notebook_kobo: number | null;
    left_kobo: number;
    verdict: string;
}

export interface Decision {
    decision: 'continue' | 'stop';
    note: string | null;
    at: string;
    by: string;
    notebook_stops: string | null;
    changes: number;
}

export interface Card {
    started: boolean;
    today: string;
    start: { first_day: string; at: string; by: string; note: string | null; restarts: number } | null;
    measured?: number;
    complete?: boolean;
    days?: DayRow[];
    totals?: { status: Status; matching: number; not_matching: number; waiting: number; needed: number; of: number };
    receipts?: { status: Status; sales: number; duplicates: number; gaps: { counter: string; epoch: number; missing: number; low: number; high: number }[]; no_receipt: number; pending_print: string[]; lost_waiting: number; recovered: number };
    speed?: { status: Status; timed: number; days_with: number; days_under: number; cashiers: { cashier: string; count: number; median_ms: number | null; under_target: number }[] };
    coverage?: { status: Status; counted_at: string | null; percent: number; confirmed_percent: number; estimated_percent: number; pending_percent: number; now_percent: number | null };
    passed?: number;
    decision: Decision | null;
    can_decide: boolean;
    can_export: boolean;
    rules: { days: number; match_days: number; speed_target_ms: number; speed_min_sales: number; coverage_percent: number; notebook_stops_after_days: number };
}

export interface MethodRow {
    method: string;
    app_kobo: number;
    notebook_kobo: number | null;
    difference_kobo: number | null;
    traced_kobo: number;
    left_kobo: number | null;
}

export interface Trace {
    id: string;
    method: string;
    amount_kobo: number;
    side: 'notebook' | 'app';
    what: string;
    receipt_number: string | null;
    by: string;
    at: string;
}

export interface Day {
    day: string;
    n: number | null;
    today: boolean;
    open: boolean;
    app_kobo: number;
    notebook_kobo: number | null;
    entered: { by: string; at: string } | null;
    methods: MethodRow[];
    traces: Trace[];
    verdict: string;
    sales: { id: string; receipt_number: string; at: string; back_entry: boolean; total_kobo: number; counter: string; cashier: string; cash_kobo: number; transfer_kobo: number; pos_kobo: number }[];
}
