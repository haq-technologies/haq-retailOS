import { useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import type { CounterApi } from '@/counter/use-api';
import { clock, longDay, naira, plural } from './format';
import { BucketKey, ProfitBar } from './profit-bar';
import type { Can, DashboardData } from './types';

/**
 * The owner's dashboard (wireframes BackDashboard and BackDashboardPhone): sales today by payment method,
 * profit in three parts, and what is waiting. A manager sees the same without profit.
 */
const methodName: Record<string, string> = { cash: 'Cash', transfer: 'Transfer', pos: 'POS' };

export function Dashboard({ api, can, go }: { api: CounterApi; can: Can; go: (section: string) => void }) {
    const [data, setData] = useState<DashboardData | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [withEstimated, setWithEstimated] = useState(true);

    useEffect(() => {
        let live = true;
        const load = () => {
            void api
                .get<DashboardData>('/api/back-office/dashboard')
                .then((a) => {
                    if (live && a.ok) setData(a.body);
                })
                .catch(() => {
                    if (live) setError('The dashboard could not load. Check the connection.');
                });
        };
        load();
        const timer = window.setInterval(load, 30_000);
        return () => {
            live = false;
            window.clearInterval(timer);
        };
    }, [api]);

    if (data === null) return error === null ? <p className="text-text-secondary">Loading…</p> : <Notice tone="error">{error}</Notice>;

    const { profit } = data;
    const confirmedProfit = profit.confirmed.profit_kobo ?? 0;
    const estimatedProfit = profit.estimated.profit_kobo ?? 0;

    return (
        <div className="flex flex-col gap-6">
            <header className="flex flex-col gap-1">
                <h1 className="text-3xl font-bold">Dashboard</h1>
                <p className="text-lg text-text-secondary">
                    {longDay(data.day)}
                    {data.opened_at !== null && ` · business day opened ${clock(data.opened_at)}`}
                    {data.counters_selling > 0 && ` · ${plural(data.counters_selling, 'counter', 'counters')} selling`}
                </p>
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
                    <div className="flex min-h-[88px] flex-col items-start justify-center rounded-2xl border-2 border-border p-4">
                        <span className="text-lg font-semibold">Catalogue health · {data.coverage_percent}%</span>
                        <span className="text-text-secondary">of revenue has a cost, since trading began</span>
                    </div>
                </div>
            </section>
        </div>
    );
}
