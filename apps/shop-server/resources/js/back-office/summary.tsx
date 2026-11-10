import { useCallback, useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import type { CounterApi } from '@/counter/use-api';
import { clock, longDay, naira, plural } from './format';
import { styles } from './pickers';
import { BucketKey } from './profit-bar';
import type { Bucket } from './types';

/**
 * The daily summary (spec sections 10 and 12) [F14, D18; wireframe DailySummary]: the business day's sales
 * by payment method, revenue in three cost parts, the day's flags and the audit code. The owner sees profit
 * here; the printed slip never carries profit or cost. Printing makes a fresh audit code, so the slip
 * covers the whole day. The sales exports are here too.
 */
interface Summary {
    day: string;
    today: string;
    opened_at: string | null;
    counters: string[];
    sales: {
        count: number;
        total_kobo: number;
        by_method: { method: string; sales: number; amount_kobo: number }[];
        voids: { count: number; total_kobo: number };
        returns: { count: number; total_kobo: number };
    };
    drawers: Drawer[];
    transfers_waiting: { count: number; amount_kobo: number };
    buckets: { revenue_kobo: number; confirmed: Bucket; estimated: Bucket; pending: Bucket };
    flags: Record<string, number>;
    audit_code: string | null;
    printers: { id: string; code: string; name: string }[];
    speed: Speed[];
}

/** Each drawer's day: cash out and in beside sales, and its close with the count (D68). */
interface Drawer {
    drawer: string;
    status: string;
    expected_cash_kobo: number;
    counted_cash_kobo: number | null;
    variance_kobo: number | null;
    flagged: boolean;
    paid_out_kobo: number;
    drop_kobo: number;
    top_up_kobo: number;
}

/** Each cashier's speed that day, from the counter's own timing (check A1) [S8, D47]. On screen only. */
interface Speed {
    cashier: string;
    timed: number;
    median_ms: number | null;
    three_item: { count: number; median_ms: number | null; under_target: number };
    one_scan: { count: number; median_ms: number | null; under_target: number };
}

function seconds(ms: number | null): string {
    return ms === null ? '–' : `${String(Math.round(ms / 1000))} s`;
}

const methodName: Record<string, string> = { cash: 'Cash', transfer: 'Transfer', pos: 'POS' };
const flagName: Record<string, string> = {
    below_cost: 'Below cost',
    below_cost_later: 'Now below cost',
    below_floor: 'Below the floor, approved',
    unusual_price: 'Unusual price',
    no_reference: 'Transfer, no reference',
    transfer_waited: 'Transfer waited over 30 minutes',
    discarded: 'Parked sale discarded',
    no_sale: 'Drawer opened, no sale',
    key_open: 'Drawer opened with key',
    auto_closed: 'Shift closed by itself',
    approval_away: 'Approved while not signed in',
    locked_out: 'Locked out',
    back_entry: 'Re-entered after a restore',
};

export function DailySummary({ api, go }: { api: CounterApi; go: (section: string) => void }) {
    const [day, setDay] = useState<string | null>(null);
    const [data, setData] = useState<Summary | null>(null);
    const [printer, setPrinter] = useState('');
    const [message, setMessage] = useState<{ tone: 'error' | 'ok'; text: string } | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        void api
            .get<Summary>(`/api/back-office/reports/summary${day === null ? '' : `?day=${day}`}`)
            .then((a) => {
                if (a.ok) setData(a.body);
            })
            .catch(() => {
                setMessage({ tone: 'error', text: 'The summary could not load. Check the connection.' });
            });
    }, [api, day]);

    useEffect(() => {
        load();
    }, [load]);

    const chosenPrinter = printer !== '' ? printer : (data?.printers[0]?.id ?? '');

    const print = async () => {
        if (data === null) return;
        setBusy(true);
        setMessage(null);
        try {
            const a = await api.post<{ audit_code?: string; message?: string }>('/api/back-office/reports/summary/print', { day: data.day, terminal_id: chosenPrinter }, 30_000);
            if (a.ok) setMessage({ tone: 'ok', text: `Printed. The slip's audit code is ${a.body.audit_code ?? ''}.` });
            else setMessage({ tone: 'error', text: a.body.message ?? 'It did not print. Try again.' });
        } catch {
            setMessage({ tone: 'error', text: 'The device cannot reach the shop server. Try again.' });
        } finally {
            setBusy(false);
            load();
        }
    };

    const exportSales = async (period: string, label: string) => {
        try {
            const url = `/api/back-office/exports/sales.csv?period=${period}${period === 'day' && data !== null ? `&day=${data.day}` : ''}`;
            if (!(await api.download(url, `sales-${label}.csv`))) setMessage({ tone: 'error', text: 'The export did not download. Try again.' });
        } catch {
            setMessage({ tone: 'error', text: 'The device cannot reach the shop server. Try again.' });
        }
    };

    if (data === null) return message === null ? <p className="text-text-secondary">Loading…</p> : <Notice tone="error">{message.text}</Notice>;

    const flags = Object.entries(data.flags).filter(([, n]) => n > 0);
    const withProfit = data.buckets.confirmed.profit_kobo !== undefined;

    return (
        <div className="flex flex-col gap-6">
            <header className="flex flex-wrap items-end gap-4">
                <div className="flex flex-1 flex-col gap-1">
                    <h1 className="text-3xl font-bold">Daily summary</h1>
                    <p className="text-lg text-text-secondary">
                        {longDay(data.day)}
                        {data.opened_at !== null && ` · opened ${clock(data.opened_at)}`}
                        {data.counters.length > 0 && ` · ${data.counters.join(', ')}`}
                    </p>
                </div>
                <label className="flex flex-col gap-1 text-lg font-semibold">
                    Business day
                    <input
                        type="date"
                        value={data.day}
                        max={data.today}
                        className="min-h-touch rounded-xl border-2 border-border px-3 text-lg font-normal"
                        onChange={(e) => {
                            if (e.target.value !== '') setDay(e.target.value);
                        }}
                    />
                </label>
            </header>

            {message !== null && (message.tone === 'error' ? <Notice tone="error">{message.text}</Notice> : <p role="status" className="rounded-xl bg-panel p-4 text-lg">{message.text}</p>)}

            <div className="grid gap-4 large:grid-cols-2">
                <section aria-labelledby="sum-sales" className="flex flex-col gap-3 rounded-2xl bg-panel p-5">
                    <div className="flex items-baseline justify-between gap-2">
                        <h2 id="sum-sales" className="text-lg font-semibold text-text-secondary">
                            Sales
                        </h2>
                        <span className="text-text-secondary">{plural(data.sales.count, 'sale', 'sales')}</span>
                    </div>
                    <p className="text-4xl font-bold">{naira(data.sales.total_kobo)}</p>
                    <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-lg">
                        {data.sales.by_method.map((m) => (
                            <div key={m.method} className="contents">
                                <dt>
                                    {methodName[m.method] ?? m.method} · {m.sales}
                                </dt>
                                <dd className="text-right font-semibold">{naira(m.amount_kobo)}</dd>
                            </div>
                        ))}
                        <dt>Transfers still waiting</dt>
                        <dd className="text-right font-semibold">{data.transfers_waiting.count}</dd>
                        {data.sales.voids.count > 0 && (
                            <>
                                <dt>Voids · {data.sales.voids.count}</dt>
                                <dd className="text-right font-semibold">{naira(data.sales.voids.total_kobo)}</dd>
                            </>
                        )}
                        {data.sales.returns.count > 0 && (
                            <>
                                <dt>Returns · {data.sales.returns.count}</dt>
                                <dd className="text-right font-semibold">{naira(data.sales.returns.total_kobo)}</dd>
                            </>
                        )}
                    </dl>
                    {(data.sales.voids.count > 0 || data.sales.returns.count > 0) && <p className="text-text-secondary">The total and each method are after voids and returns.</p>}
                </section>

                {data.drawers.length > 0 && (
                    <section aria-labelledby="sum-drawers" className="flex flex-col gap-3 rounded-2xl bg-panel p-5">
                        <h2 id="sum-drawers" className="text-lg font-semibold text-text-secondary">
                            Drawers
                        </h2>
                        {data.drawers.map((d, i) => (
                            <dl key={`${d.drawer}-${String(i)}`} className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-lg">
                                <dt className="font-semibold">{d.drawer}</dt>
                                <dd className="text-right">{d.status === 'open' ? 'Open' : d.status === 'closed' ? 'Closed and counted' : 'Closed by itself, not counted'}</dd>
                                <dt>Expected cash</dt>
                                <dd className="text-right font-semibold">{naira(d.expected_cash_kobo)}</dd>
                                {d.counted_cash_kobo !== null && d.variance_kobo !== null && (
                                    <>
                                        <dt>Counted</dt>
                                        <dd className="text-right font-semibold">{naira(d.counted_cash_kobo)}</dd>
                                        <dt className={d.flagged ? 'font-semibold text-warning-text' : ''}>
                                            {d.variance_kobo === 0 ? 'Right' : d.variance_kobo > 0 ? 'Over by' : 'Short by'}
                                            {d.flagged ? ' (flagged)' : ''}
                                        </dt>
                                        <dd className="text-right font-semibold">{d.variance_kobo === 0 ? '' : naira(Math.abs(d.variance_kobo))}</dd>
                                    </>
                                )}
                                {d.paid_out_kobo + d.drop_kobo + d.top_up_kobo > 0 && (
                                    <>
                                        <dt>Paid out · dropped · topped up</dt>
                                        <dd className="text-right">
                                            {naira(d.paid_out_kobo)} · {naira(d.drop_kobo)} · {naira(d.top_up_kobo)}
                                        </dd>
                                    </>
                                )}
                            </dl>
                        ))}
                    </section>
                )}

                <section aria-labelledby="sum-cost" className="flex flex-col gap-3 rounded-2xl bg-panel p-5">
                    <h2 id="sum-cost" className="text-lg font-semibold text-text-secondary">
                        Revenue by cost
                    </h2>
                    <ul className="flex flex-col gap-2 text-lg">
                        {(
                            [
                                ['confirmed', 'Confirmed at sale'],
                                ['estimated', 'Estimated'],
                                ['pending', 'Waiting for a cost'],
                            ] as const
                        ).map(([key, label]) => (
                            <li key={key} className="flex items-center gap-3">
                                <BucketKey kind={key} />
                                <span className="flex-1">{label}</span>
                                <span className="text-text-secondary">
                                    {data.buckets[key].percent}% · {naira(data.buckets[key].revenue_kobo)}
                                </span>
                                {withProfit && key !== 'pending' && <strong>{naira(data.buckets[key].profit_kobo ?? 0)}</strong>}
                            </li>
                        ))}
                    </ul>
                    {withProfit && <p className="text-text-secondary">Profit shows here only. The printed slip carries no profit or cost.</p>}
                </section>
            </div>

            <section aria-labelledby="sum-flags" className="flex flex-col gap-2">
                <div className="flex items-baseline justify-between gap-2">
                    <h2 id="sum-flags" className="text-xl font-bold">
                        Flags that day
                    </h2>
                    <button type="button" className="min-h-touch text-lg font-semibold text-action-text" onClick={() => { go('flags'); }}>
                        Open Flags
                    </button>
                </div>
                {flags.length === 0 ? (
                    <p className="text-lg text-text-secondary">Nothing was flagged.</p>
                ) : (
                    <ul className="grid gap-2 medium:grid-cols-2 large:grid-cols-3">
                        {flags.map(([kind, n]) => (
                            <li key={kind} className="flex justify-between rounded-xl border-2 border-border px-4 py-3 text-lg">
                                <span>{flagName[kind] ?? kind}</span>
                                <strong>{n}</strong>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {data.speed.length > 0 && (
                <section aria-labelledby="sum-speed" className="flex flex-col gap-2">
                    <h2 id="sum-speed" className="text-xl font-bold">
                        Counter speed
                    </h2>
                    <p className="text-text-secondary">From each counter's own timing. The pilot's goals: a 3-item sale under 30 seconds, one scanned item under 8 seconds.</p>
                    <div className="overflow-x-auto rounded-2xl border-2 border-border">
                        <table className="w-full min-w-[640px] text-left text-lg tabular-nums">
                            <thead className="text-base text-text-secondary">
                                <tr className="border-b border-border">
                                    <th scope="col" className="p-3 font-semibold">Cashier</th>
                                    <th scope="col" className="p-3 text-right font-semibold">Sales timed</th>
                                    <th scope="col" className="p-3 text-right font-semibold">Typical sale</th>
                                    <th scope="col" className="p-3 text-right font-semibold">3-item sales under 30 s</th>
                                    <th scope="col" className="p-3 text-right font-semibold">One scan under 8 s</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.speed.map((r) => (
                                    <tr key={r.cashier} className="border-b border-border last:border-b-0">
                                        <td className="p-3 font-semibold">{r.cashier}</td>
                                        <td className="p-3 text-right">{r.timed}</td>
                                        <td className="p-3 text-right">{seconds(r.median_ms)}</td>
                                        <td className="p-3 text-right">{r.three_item.count === 0 ? '–' : `${String(r.three_item.under_target)} of ${String(r.three_item.count)} · typical ${seconds(r.three_item.median_ms)}`}</td>
                                        <td className="p-3 text-right">{r.one_scan.count === 0 ? '–' : `${String(r.one_scan.under_target)} of ${String(r.one_scan.count)} · typical ${seconds(r.one_scan.median_ms)}`}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            <section aria-labelledby="sum-print" className="flex flex-col gap-3 rounded-2xl border-2 border-border p-5">
                <h2 id="sum-print" className="text-xl font-bold">
                    Print the summary
                </h2>
                <p className="text-text-secondary">
                    The slip prints on a counter's 80 mm printer, with sales by payment method, revenue in the three cost parts, the day's flags and the audit code. Keep it: if the day's records are ever changed, they will no longer match the code.
                    {data.audit_code !== null && ` The latest audit code is ${data.audit_code}; printing makes a fresh one.`}
                </p>
                {data.printers.length === 0 ? (
                    <Notice tone="warning">No counter has a printer set, so the summary cannot print. HAQ sets the printers in the shop profile.</Notice>
                ) : (
                    <div className="flex flex-wrap items-end gap-3">
                        <label className="flex flex-col gap-1 text-lg font-semibold">
                            Printer
                            <select
                                value={chosenPrinter}
                                className="min-h-touch rounded-xl border-2 border-border bg-background px-3 text-lg font-normal"
                                onChange={(e) => {
                                    setPrinter(e.target.value);
                                }}
                            >
                                {data.printers.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name} ({p.code})
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button type="button" disabled={busy} className={styles.primary} onClick={() => void print()}>
                            {busy ? 'Printing…' : 'Print daily summary'}
                        </button>
                    </div>
                )}
            </section>

            <section aria-labelledby="sum-export" className="flex flex-col gap-3">
                <h2 id="sum-export" className="text-xl font-bold">
                    Export sales to CSV
                </h2>
                <p className="text-text-secondary">One row per item sold, opening cleanly in Excel. Cost columns appear only for those who may see cost.</p>
                <div className="flex flex-wrap gap-2">
                    <button type="button" className={styles.button} onClick={() => void exportSales('day', data.day)}>
                        This day
                    </button>
                    <button type="button" className={styles.button} onClick={() => void exportSales('week', 'week')}>
                        This week
                    </button>
                    <button type="button" className={styles.button} onClick={() => void exportSales('month', 'month')}>
                        This month
                    </button>
                    <button type="button" className={styles.button} onClick={() => void exportSales('all', 'all')}>
                        Everything
                    </button>
                </div>
            </section>
        </div>
    );
}
