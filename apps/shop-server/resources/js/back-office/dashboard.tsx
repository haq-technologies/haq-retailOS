import { useCallback, useEffect, useState } from 'react';
import { CheckIcon, WarningIcon } from '@/components/icons';
import { Notice } from '@/components/notice';
import type { CounterApi } from '@/counter/use-api';
import { clock, longDay, naira, plural, when } from './format';
import { BucketKey, ProfitBar } from './profit-bar';
import { SaleSheet } from './sale-sheet';
import type { Can, CashierRow, DashboardData } from './types';

/**
 * The owner's dashboard (wireframes BackDashboard and BackDashboardPhone): sales today by payment method,
 * profit in three parts, and what is waiting. A manager sees the same without profit.
 */
const methodName: Record<string, string> = { cash: 'Cash', transfer: 'Transfer', pos: 'POS' };

export function Dashboard({ api, can, go, onChanged }: { api: CounterApi; can: Can; go: (section: string) => void; onChanged: () => void }) {
    const [data, setData] = useState<DashboardData | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [withEstimated, setWithEstimated] = useState(true);
    const [viewing, setViewing] = useState<string[] | null>(null);

    const load = useCallback(() => {
        void api
            .get<DashboardData>('/api/back-office/dashboard')
            .then((a) => {
                if (a.ok) setData(a.body);
            })
            .catch(() => {
                setError('The dashboard could not load. Check the connection.');
            });
    }, [api]);

    useEffect(() => {
        load();
        const timer = window.setInterval(load, 30_000);
        return () => {
            window.clearInterval(timer);
        };
    }, [load]);

    const markSeen = async (key: string) => {
        try {
            await api.post('/api/back-office/flags/seen', { keys: [key] });
        } catch {
            setError('The device cannot reach the shop server. Try again.');
        }
        load();
        onChanged();
    };

    if (data === null) return error === null ? <p className="text-text-secondary">Loading…</p> : <Notice tone="error">{error}</Notice>;

    const { profit } = data;
    const confirmedProfit = profit.confirmed.profit_kobo ?? 0;
    const estimatedProfit = profit.estimated.profit_kobo ?? 0;

    return (
        <div className="flex flex-col gap-6">
            <header className="flex flex-wrap items-end gap-4">
                <div className="flex flex-1 flex-col gap-1">
                    <h1 className="text-3xl font-bold">Dashboard</h1>
                    <p className="text-lg text-text-secondary">
                        {longDay(data.day)}
                        {data.opened_at !== null && ` · business day opened ${clock(data.opened_at)}`}
                        {data.counters_selling > 0 && ` · ${plural(data.counters_selling, 'counter', 'counters')} selling`}
                    </p>
                </div>
                {can.summary && (
                    <button type="button" className="min-h-touch rounded-xl border-2 border-border px-5 text-lg font-semibold text-action-text" onClick={() => { go('summary'); }}>
                        Print daily summary
                    </button>
                )}
            </header>

            <div className="grid gap-4 large:grid-cols-2">
                <section aria-labelledby="sales-h" className="flex flex-col gap-3 rounded-2xl bg-panel p-5">
                    <div className="flex items-baseline justify-between gap-2">
                        <h2 id="sales-h" className="text-lg font-semibold text-text-secondary">
                            Sales today
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
                    </dl>
                    {data.transfers_waiting.count > 0 && (
                        <p className="text-lg text-warning-text">
                            {plural(data.transfers_waiting.count, 'transfer', 'transfers')} waiting: {naira(data.transfers_waiting.amount_kobo)}
                        </p>
                    )}
                </section>

                <section aria-labelledby="profit-h" className="flex flex-col gap-3 rounded-2xl bg-panel p-5">
                    <div className="flex items-baseline justify-between gap-2">
                        <h2 id="profit-h" className="text-lg font-semibold text-text-secondary">
                            {can.profit ? 'Profit today, in three parts' : 'Revenue today, by cost'}
                        </h2>
                        {can.profit && (
                            <label className="flex min-h-touch items-center gap-2">
                                <input
                                    type="checkbox"
                                    className="h-6 w-6"
                                    checked={withEstimated}
                                    onChange={(e) => {
                                        setWithEstimated(e.target.checked);
                                    }}
                                />
                                Count estimated profit
                            </label>
                        )}
                    </div>
                    {can.profit && (
                        <>
                            <p className="text-4xl font-bold">{naira(confirmedProfit + (withEstimated ? estimatedProfit : 0))}</p>
                            <p className="text-text-secondary">
                                {naira(confirmedProfit)} confirmed at sale{withEstimated ? ` plus ${naira(estimatedProfit)} estimated` : ''}. Revenue still waiting for a cost is left out.
                            </p>
                        </>
                    )}
                    <ProfitBar profit={profit} />
                    <ul className="flex flex-col gap-2 text-lg">
                        <li className="flex items-center gap-3">
                            <BucketKey kind="confirmed" />
                            <span className="flex-1">Confirmed at sale</span>
                            <span className="text-text-secondary">
                                {profit.confirmed.percent}% · {naira(profit.confirmed.revenue_kobo)}
                            </span>
                            {can.profit && <strong>{naira(confirmedProfit)}</strong>}
                        </li>
                        <li className="flex items-center gap-3">
                            <BucketKey kind="estimated" />
                            <span className="flex-1">Estimated</span>
                            <span className="text-text-secondary">
                                {profit.estimated.percent}% · {naira(profit.estimated.revenue_kobo)}
                            </span>
                            {can.profit && <strong>{naira(estimatedProfit)}</strong>}
                        </li>
                        <li className="flex items-center gap-3">
                            <BucketKey kind="pending" />
                            <span className="flex-1">Waiting for a cost</span>
                            <span className="text-text-secondary">
                                {profit.pending.percent}% · {naira(profit.pending.revenue_kobo)}
                            </span>
                            {can.profit && <span className="text-text-secondary">not known</span>}
                        </li>
                    </ul>
                    <p className="text-text-secondary">No margin is ever guessed. Enter costs to move revenue out of “Waiting for a cost”.</p>
                    {can.costs && data.todo.needs_cost > 0 && (
                        <button
                            type="button"
                            className="min-h-touch self-start rounded-xl border-2 border-action px-5 text-lg font-semibold text-action-text"
                            onClick={() => {
                                go('costs');
                            }}
                        >
                            Enter costs
                        </button>
                    )}
                </section>
            </div>

            {data.alerts.length > 0 && (
                <ul aria-label="Put these right" className="flex flex-col gap-2">
                    {data.alerts.map((a) => (
                        <li key={`${a.kind}:${a.title}`} className="flex flex-col gap-3 rounded-2xl border-2 border-warning p-4 medium:flex-row medium:items-center">
                            <WarningIcon className="hidden h-7 w-7 shrink-0 text-warning-text medium:block" />
                            <span className="flex min-w-0 flex-1 flex-col gap-1">
                                <span className="text-lg font-bold">{a.title}</span>
                                <span>{a.detail}</span>
                            </span>
                            {a.open !== null && (a.open !== 'server' || can.health) && (
                                <button
                                    type="button"
                                    className="min-h-touch self-start rounded-xl bg-action px-6 text-lg font-bold text-white medium:self-center"
                                    onClick={() => {
                                        if (a.open !== null) go(a.open);
                                    }}
                                >
                                    Open
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {can.flags && data.attention.total > 0 && (
                <section aria-labelledby="attention-h" className="flex flex-col gap-2">
                    <div className="flex items-baseline justify-between gap-2">
                        <h2 id="attention-h" className="text-xl font-bold">
                            Needs your attention · {data.attention.total}
                        </h2>
                        <a href="#/flags" className="min-h-touch content-center text-lg font-semibold text-action-text">
                            All flags
                        </a>
                    </div>
                    <ul className="flex flex-col rounded-2xl border-2 border-border">
                        {data.attention.flags.map((f) => (
                            <li key={f.key} className="flex flex-col gap-3 border-b border-border p-4 last:border-b-0 medium:flex-row medium:items-center">
                                <WarningIcon className="hidden h-6 w-6 shrink-0 text-warning-text medium:block" />
                                <span className="flex min-w-0 flex-1 flex-col gap-1">
                                    <span className="text-lg font-semibold">{f.title}</span>
                                    <span className="text-text-secondary">
                                        {f.detail}
                                        {f.who !== null && ` · ${f.who}`}
                                        {f.approver !== null && `, approved by ${f.approver}`}
                                        {f.terminal !== null && `, ${f.terminal}`} · {when(f.at)}
                                    </span>
                                </span>
                                <span className="flex flex-wrap gap-2">
                                    {f.sale_ids.length > 0 && (
                                        <button type="button" className="min-h-touch rounded-xl border-2 border-border px-4 text-lg font-semibold text-action-text" onClick={() => { setViewing(f.sale_ids); }}>
                                            {f.sale_ids.length > 1 ? 'View sales' : 'View sale'}
                                        </button>
                                    )}
                                    <button type="button" className="min-h-touch rounded-xl border-2 border-border px-4 text-lg font-semibold text-action-text" onClick={() => void markSeen(f.key)}>
                                        Mark as seen
                                    </button>
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section aria-labelledby="todo-h" className="flex flex-col gap-2">
                <h2 id="todo-h" className="text-xl font-bold">
                    To do
                </h2>
                <div className="grid gap-3 medium:grid-cols-2 large:grid-cols-3">
                    {can.costs && (
                        <button type="button" className="flex min-h-[88px] flex-col items-start justify-center rounded-2xl border-2 border-border p-4 text-left" onClick={() => { go('costs'); }}>
                            <span className="text-lg font-semibold">Needs cost · {data.todo.needs_cost}</span>
                            <span className="text-text-secondary">Items sold with no cost, highest revenue first</span>
                        </button>
                    )}
                    {can.prices && (
                        <button type="button" className="flex min-h-[88px] flex-col items-start justify-center rounded-2xl border-2 border-border p-4 text-left" onClick={() => { go('prices'); }}>
                            <span className="text-lg font-semibold">First-sale prices · {data.todo.first_sales}</span>
                            <span className="text-text-secondary">Sold today with nothing to compare</span>
                        </button>
                    )}
                    {can.review && (
                        <button type="button" className="flex min-h-[88px] flex-col items-start justify-center rounded-2xl border-2 border-border p-4 text-left" onClick={() => { go('review'); }}>
                            <span className="text-lg font-semibold">Review · {data.todo.review}</span>
                            <span className="text-text-secondary">Brands, new items, barcodes and duplicates</span>
                        </button>
                    )}
                    {can.flags && (
                        <button type="button" className="flex min-h-[88px] flex-col items-start justify-center rounded-2xl border-2 border-border p-4 text-left" onClick={() => { go('flags'); }}>
                            <span className="text-lg font-semibold">Flags · {data.todo.flags_week}</span>
                            <span className="text-text-secondary">Flagged this week, newest first</span>
                        </button>
                    )}
                    <button type="button" disabled={!can.catalogue} className="flex min-h-[88px] flex-col items-start justify-center rounded-2xl border-2 border-border p-4 text-left" onClick={() => { go('catalogue'); }}>
                        <span className="text-lg font-semibold">Catalogue health · {data.coverage_percent}%</span>
                        <span className="text-text-secondary">of revenue has a cost; goal 80%</span>
                    </button>
                </div>
            </section>

            {data.cashiers.length > 0 && <CashiersToday rows={data.cashiers} />}

            {data.system !== null && (
                <p className="flex items-center gap-2 text-base text-text-secondary">
                    <CheckIcon className="h-5 w-5 shrink-0 text-text" />
                    {data.system}
                </p>
            )}

            {viewing !== null && (
                <SaleSheet
                    api={api}
                    saleIds={viewing}
                    onClose={() => {
                        setViewing(null);
                    }}
                />
            )}
        </div>
    );
}

const roleName: Record<string, string> = { cashier: 'Cashier', manager: 'Manager', owner: 'Owner', storekeeper: 'Storekeeper' };

/** Each person's day at the counter (spec section 8) [F11, F12, S2, S21]. */
function CashiersToday({ rows }: { rows: CashierRow[] }) {
    return (
        <section aria-labelledby="cashiers-h" className="flex flex-col gap-2">
            <h2 id="cashiers-h" className="text-xl font-bold">
                Cashiers today
            </h2>
            <div className="overflow-x-auto rounded-2xl border-2 border-border">
                <table className="w-full min-w-[900px] text-left text-lg tabular-nums">
                    <thead className="text-base text-text-secondary">
                        <tr className="border-b border-border">
                            <th scope="col" className="p-3 font-semibold">Cashier</th>
                            <th scope="col" className="p-3 text-right font-semibold">Sales</th>
                            <th scope="col" className="p-3 text-right font-semibold">Below-range confirms</th>
                            <th scope="col" className="p-3 text-right font-semibold">Parked sales discarded</th>
                            <th scope="col" className="p-3 text-right font-semibold">Items created</th>
                            <th scope="col" className="p-3 text-right font-semibold">Transfers with no reference</th>
                            <th scope="col" className="p-3 text-right font-semibold">Transfers waiting over 30 min</th>
                            <th scope="col" className="p-3 text-right font-semibold">Drawer, no sale</th>
                            <th scope="col" className="p-3 text-right font-semibold">Drawer opened with key</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.id} className="border-b border-border last:border-b-0">
                                <th scope="row" className="p-3 font-semibold">
                                    {r.name} <span className="font-normal text-text-secondary">· {roleName[r.role] ?? r.role}</span>
                                </th>
                                <td className="p-3 text-right">{r.sales}</td>
                                <td className="p-3 text-right">
                                    {r.below_range} of {r.below_range_limit}
                                    {r.needs_manager && <span className="block text-base text-warning-text">the next needs a manager</span>}
                                </td>
                                <td className="p-3 text-right">{r.discarded}</td>
                                <td className={`p-3 text-right ${r.items_created_high ? 'font-semibold text-warning-text' : ''}`}>
                                    {r.items_created_high && <WarningIcon className="mr-1 inline h-5 w-5 align-text-bottom" />}
                                    {r.items_created}
                                    {r.items_created_high ? ', high' : ''}
                                </td>
                                <td className="p-3 text-right">{r.no_reference}</td>
                                <td className="p-3 text-right">{r.transfers_waited}</td>
                                <td className="p-3 text-right">{r.no_sale}</td>
                                <td className="p-3 text-right">{r.key_open}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
