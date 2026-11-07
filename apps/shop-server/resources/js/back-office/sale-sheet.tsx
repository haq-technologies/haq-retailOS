import { useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import type { CounterApi } from '@/counter/use-api';
import { clock, longDay, naira } from './format';

/**
 * One sale, behind every "View sale" (wireframes FlagList and BackDashboard): the receipt, each line with
 * how its price was checked, and the payments. Cost shows only for those who may see it; the server
 * leaves it out for everyone else.
 */
interface SaleLine {
    name: string;
    colour: string | null;
    qty: number;
    unit_price_kobo: number;
    line_total_kobo: number;
    approver: string | null;
    check: string | null;
    usual: { low: number; high: number } | null;
    cost_kobo?: number | null;
    cost_bucket?: 'confirmed' | 'estimated' | 'pending';
}

interface Sale {
    receipt_number: string;
    kind: string;
    total_kobo: number;
    business_day: string;
    recorded_at: string;
    customer_type: string;
    clock_state: string;
    cashier: string;
    terminal: string;
    lines: SaleLine[];
    payments: { method: string; amount_kobo: number; tendered_kobo: number | null; change_kobo: number | null; reference: string | null; alert_seen: boolean | null }[];
}

const method: Record<string, string> = { cash: 'Cash', transfer: 'Transfer', pos: 'POS' };
const bucket: Record<string, string> = { confirmed: 'cost at sale', estimated: 'estimated cost', pending: 'no cost yet' };

export function SaleSheet({ api, saleIds, onClose }: { api: CounterApi; saleIds: string[]; onClose: () => void }) {
    const [index, setIndex] = useState(0);
    const [loaded, setLoaded] = useState<{ id: string; sale: Sale } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const id = saleIds[index];
    // The sale shown is the one asked for; while the next loads, nothing stale is shown.
    const sale = loaded !== null && loaded.id === id ? loaded.sale : null;

    useEffect(() => {
        if (id === undefined) return;
        let live = true;
        void api
            .get<Sale>(`/api/back-office/sales/${id}`)
            .then((a) => {
                if (!live) return;
                if (a.ok) setLoaded({ id, sale: a.body });
                else setError('This sale could not be found.');
            })
            .catch(() => {
                if (live) setError('The device cannot reach the shop server. Try again.');
            });
        return () => {
            live = false;
        };
    }, [api, id]);

    return (
        <Sheet title={sale === null ? 'Sale' : `Sale ${sale.receipt_number}`} onClose={onClose} wide>
            {error !== null && <Notice tone="error">{error}</Notice>}
            {sale === null && error === null && <p className="text-lg text-text-secondary">Loading…</p>}
            {sale !== null && (
                <div className="flex flex-col gap-4 text-lg">
                    <p className="text-text-secondary">
                        {longDay(sale.business_day)} · {clock(sale.recorded_at)} · {sale.cashier} on {sale.terminal} · {sale.customer_type === 'wholesale' ? 'Wholesale' : 'Retail'}
                        {sale.clock_state !== 'trusted' && ' · the clock was not verified'}
                    </p>
                    <ul className="flex flex-col">
                        {sale.lines.map((l, i) => (
                            <li key={i} className="flex flex-wrap items-baseline gap-x-4 gap-y-1 border-b border-border py-3">
                                <span className="flex-1 font-semibold">
                                    {l.qty > 1 && `${String(l.qty)} × `}
                                    {l.name}
                                    {l.colour !== null && <span className="font-normal text-text-secondary"> · {l.colour}</span>}
                                </span>
                                <span>{naira(l.line_total_kobo)}</span>
                                <span className="w-full text-base text-text-secondary">
                                    {naira(l.unit_price_kobo)} each
                                    {l.usual !== null && ` · usual ${l.usual.low === l.usual.high ? naira(l.usual.low) : `${naira(l.usual.low)} to ${naira(l.usual.high)}`}`}
                                    {l.check !== null && ` · ${l.check}`}
                                    {l.approver !== null && ` by ${l.approver}`}
                                    {l.cost_bucket !== undefined && ` · ${l.cost_kobo != null ? `${naira(l.cost_kobo)} ` : ''}${bucket[l.cost_bucket] ?? ''}`}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p className="flex justify-between text-xl font-bold">
                        <span>Total</span>
                        <span>{naira(sale.total_kobo)}</span>
                    </p>
                    <ul className="flex flex-col gap-1">
                        {sale.payments.map((p, i) => (
                            <li key={i} className="flex flex-wrap justify-between gap-2">
                                <span>
                                    {method[p.method] ?? p.method}
                                    {p.method === 'transfer' && (p.reference !== null && p.reference !== '' ? ` · reference ${p.reference}` : ' · no reference')}
                                    {p.method === 'transfer' && p.alert_seen === true && ' · alert seen'}
                                    {p.method === 'cash' && p.tendered_kobo !== null && ` · given ${naira(p.tendered_kobo)}, change ${naira(p.change_kobo ?? 0)}`}
                                </span>
                                <span>{naira(p.amount_kobo)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {saleIds.length > 1 && (
                <div className="flex items-center justify-between gap-3">
                    <button type="button" disabled={index === 0} className="min-h-touch rounded-xl border-2 border-border px-5 text-lg font-semibold text-action-text disabled:opacity-50" onClick={() => { setIndex(index - 1); }}>
                        Previous sale
                    </button>
                    <span className="text-text-secondary">
                        {index + 1} of {saleIds.length}
                    </span>
                    <button type="button" disabled={index >= saleIds.length - 1} className="min-h-touch rounded-xl border-2 border-border px-5 text-lg font-semibold text-action-text disabled:opacity-50" onClick={() => { setIndex(index + 1); }}>
                        Next sale
                    </button>
                </div>
            )}
        </Sheet>
    );
}
