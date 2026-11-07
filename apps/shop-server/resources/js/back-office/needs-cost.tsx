import { useCallback, useEffect, useState } from 'react';
import { WarningIcon } from '@/components/icons';
import { Notice } from '@/components/notice';
import type { CounterApi } from '@/counter/use-api';
import { naira, plural, shortDate } from './format';

/**
 * Needs cost (spec section 8) [F14] (wireframes NeedsCost and NeedsCostBulk): items with sales that have
 * no cost, highest revenue first. Enter a cost for one item, or one cost for several; see what it does
 * before saving; save. No sale changes: the estimate is kept beside the earlier sales.
 */
interface QueueItem {
    item_id: string;
    name: string;
    sales: number;
    revenue_kobo: number;
    first_at: string;
}

interface Queue {
    items: QueueItem[];
    total_items: number;
    total_revenue_kobo: number;
    since: string | null;
}

interface ItemDetail {
    item_id: string;
    name: string;
    usual_price_kobo: number | null;
    current_cost_kobo: number | null;
    history: { unit_cost_kobo: number; effective_from: string; by: string | null }[];
}

interface Preview {
    items: { item_id: string; name: string; sales: number; qty: number; revenue_kobo: number; profit_kobo: number; first_at: string | null }[];
    sales: number;
    revenue_kobo: number;
    profit_kobo: number;
    below_cost: { receipt_number: string; item: string; unit_price_kobo: number; recorded_at: string; cashier: string }[];
    coverage_now: number;
    coverage_after: number;
}

const today = () => new Date().toLocaleDateString('en-CA');

export function NeedsCost({ api, onChanged }: { api: CounterApi; onChanged: () => void }) {
    const [queue, setQueue] = useState<Queue | null>(null);
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<string[]>([]);
    const [several, setSeveral] = useState(false);
    const [choosing, setChoosing] = useState(false);
    const [detail, setDetail] = useState<ItemDetail | null>(null);
    const [digits, setDigits] = useState('');
    const [asOf, setAsOf] = useState(today());
    const [basis, setBasis] = useState<'first_null' | 'from_date'>('first_null');
    const [from, setFrom] = useState(today());
    const [preview, setPreview] = useState<Preview | null>(null);
    const [message, setMessage] = useState<{ tone: 'error' | 'ok'; text: string } | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        void api
            .get<Queue>(`/api/back-office/costs?search=${encodeURIComponent(search)}`)
            .then((a) => {
                if (a.ok) setQueue(a.body);
            })
            .catch(() => {
                setMessage({ tone: 'error', text: 'The list could not load. Check the connection.' });
            });
    }, [api, search]);

    useEffect(() => {
        const timer = window.setTimeout(load, 250);
        return () => {
            window.clearTimeout(timer);
        };
    }, [load]);

    // One item: its usual price and cost history.
    useEffect(() => {
        const only = selected.length === 1 && !several ? selected[0] : undefined;
        if (only === undefined) return;
        let live = true;
        void api.get<ItemDetail>(`/api/back-office/costs/items/${only}`).then((a) => {
            if (live && a.ok) setDetail(a.body);
        });
        return () => {
            live = false;
        };
    }, [api, selected, several]);

    const cost = digits === '' ? null : Number.parseInt(digits, 10) * 100;
    const body = useCallback(
        () => ({ item_ids: selected, unit_cost_kobo: cost, as_of: asOf, basis, from: basis === 'from_date' ? from : null }),
        [selected, cost, asOf, basis, from],
    );

    // The preview follows every change, before anything is saved.
    useEffect(() => {
        if (selected.length === 0 || cost === null || choosing) return;
        let live = true;
        const timer = window.setTimeout(() => {
            void api.post<Preview & { message?: string }>('/api/back-office/costs/preview', body()).then((a) => {
                if (live && a.ok) setPreview(a.body);
            });
        }, 300);
        return () => {
            live = false;
            window.clearTimeout(timer);
        };
    }, [api, selected, cost, choosing, body]);

    const save = async () => {
        setBusy(true);
        setMessage(null);
        try {
            const a = await api.post<{ entries?: number; resolved?: number; message?: string }>('/api/back-office/costs', body());
            if (a.ok) {
                setMessage({ tone: 'ok', text: `Saved. ${plural(a.body.resolved ?? 0, 'earlier sale now has', 'earlier sales now have')} an estimated cost.` });
                setSelected([]);
                setDigits('');
                setPreview(null);
                setDetail(null);
                load();
                onChanged();
            } else {
                setMessage({ tone: 'error', text: a.body.message ?? 'That did not save. Try again.' });
            }
        } catch {
            setMessage({ tone: 'error', text: 'The device cannot reach the shop server. Try again.' });
        } finally {
            setBusy(false);
        }
    };

    const chosen = (queue?.items ?? []).filter((i) => selected.includes(i.item_id));
    const first = chosen[0];

    return (
        <div className="flex flex-col gap-5">
            <header className="flex flex-wrap items-end gap-4">
                <div className="flex flex-1 flex-col gap-1">
                    <h1 className="text-3xl font-bold">Needs cost</h1>
                    {queue !== null && (
                        <p className="text-lg text-text-secondary">
                            {plural(queue.total_items, 'item has', 'items have')} sales with no cost: {naira(queue.total_revenue_kobo)} of revenue
                            {queue.since !== null && ` since trading began on ${shortDate(queue.since)}`}. Highest revenue first.
                        </p>
                    )}
                </div>
                <button
                    type="button"
                    className="min-h-touch rounded-xl border-2 border-border px-5 text-lg font-semibold text-action-text"
                    onClick={() => {
                        setSeveral(!several);
                        setChoosing(!several);
                        setSelected([]);
                        setPreview(null);
                    }}
                >
                    {several ? 'One item at a time' : 'Select several'}
                </button>
            </header>

            {message !== null && (message.tone === 'error' ? <Notice tone="error">{message.text}</Notice> : <p role="status" className="rounded-xl bg-panel p-4 text-lg">{message.text}</p>)}

            <div className="flex flex-col gap-5 large:flex-row">
                <section aria-label="Items with no cost" className="flex flex-col gap-3 large:w-[420px] large:shrink-0">
                    <input
                        type="search"
                        aria-label="Search items"
                        placeholder="Search items"
                        value={search}
                        className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                        onChange={(e) => {
                            setSearch(e.target.value);
                        }}
                    />
                    {several && (
                        <p className="text-text-secondary">
                            Choose several items to give them one cost, for example a carton of pouches from one supplier. {selected.length} chosen.
                        </p>
                    )}
                    <ul className="flex flex-col">
                        {(queue?.items ?? []).map((i) => {
                            const on = selected.includes(i.item_id);
                            return (
                                <li key={i.item_id}>
                                    <button
                                        type="button"
                                        aria-pressed={on}
                                        className={`flex min-h-[72px] w-full items-center gap-3 border-b border-border px-3 py-2 text-left ${on ? 'rounded-xl bg-panel shadow-[inset_4px_0_0_var(--color-action)]' : ''}`}
                                        onClick={() => {
                                            if (several) {
                                                setSelected((s) => (on ? s.filter((x) => x !== i.item_id) : [...s, i.item_id]));
                                            } else {
                                                setSelected([i.item_id]);
                                                setDigits('');
                                                setPreview(null);
                                            }
                                        }}
                                    >
                                        {several && <input type="checkbox" readOnly checked={on} tabIndex={-1} className="h-6 w-6" aria-hidden="true" />}
                                        <span className="flex flex-col">
                                            <span className={`text-lg font-semibold ${on ? 'text-action-text' : ''}`}>{i.name}</span>
                                            <span className="text-text-secondary">
                                                {plural(i.sales, 'sale', 'sales')} · {naira(i.revenue_kobo)}
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                    {queue !== null && queue.items.length === 0 && <p className="text-lg text-text-secondary">Every item sold has a cost. Nothing waits here.</p>}
                    {several && selected.length > 0 && choosing && (
                        <button
                            type="button"
                            className="min-h-touch rounded-xl bg-action text-lg font-bold text-white"
                            onClick={() => {
                                setChoosing(false);
                            }}
                        >
                            Done choosing
                        </button>
                    )}
                </section>

                {selected.length > 0 && !choosing && first !== undefined && (
                    <section aria-labelledby="cost-h" className="flex min-w-0 flex-1 flex-col gap-5 rounded-2xl border-2 border-border p-5">
                        <div className="flex flex-col gap-1">
                            <h2 id="cost-h" className="text-2xl font-bold">
                                {several ? `One cost for ${plural(selected.length, 'item', 'items')}` : first.name}
                            </h2>
                            <p className="text-text-secondary">
                                {several
                                    ? `${plural(chosen.reduce((s, i) => s + i.sales, 0), 'sale', 'sales')} with no cost · ${naira(chosen.reduce((s, i) => s + i.revenue_kobo, 0))} · each item keeps its own sales`
                                    : `${plural(first.sales, 'sale', 'sales')} with no cost · ${naira(first.revenue_kobo)} · first on ${shortDate(first.first_at)}${detail?.usual_price_kobo != null ? ` · usually sold at ${naira(detail.usual_price_kobo)}` : ''}`}
                            </p>
                            {!several && detail !== null && detail.current_cost_kobo !== null && (
                                <p className="text-text-secondary">Its cost now: {naira(detail.current_cost_kobo)}, for sales from then on.</p>
                            )}
                        </div>

                        <div className="flex flex-wrap items-end gap-4">
                            <label className="flex flex-col gap-2 text-lg font-semibold">
                                Cost for one
                                <span className="flex min-h-[64px] w-[230px] items-center gap-1 rounded-xl border-2 border-action px-4 text-3xl font-bold">
                                    <span className="text-text-secondary">₦</span>
                                    <input
                                        inputMode="numeric"
                                        aria-label="Cost for one, in naira"
                                        value={digits}
                                        className="w-full outline-none"
                                        onChange={(e) => {
                                            setDigits(e.target.value.replace(/\D/g, '').slice(0, 9));
                                        }}
                                    />
                                </span>
                            </label>
                            <label className="flex flex-col gap-2 text-lg font-semibold">
                                Cost as of
                                <input
                                    type="date"
                                    value={asOf}
                                    max={today()}
                                    className="min-h-[64px] rounded-xl border-2 border-border px-4 text-lg"
                                    onChange={(e) => {
                                        setAsOf(e.target.value);
                                    }}
                                />
                            </label>
                        </div>
                        <p className="-mt-3 text-text-secondary">Each cost keeps its date, because naira prices move.</p>

                        <fieldset className="flex flex-col gap-1">
                            <legend className="pb-2 text-lg font-semibold">Use this cost for earlier sales</legend>
                            <label className={`flex min-h-touch items-center gap-3 rounded-xl px-3 text-lg ${basis === 'first_null' ? 'bg-panel' : ''}`}>
                                <input type="radio" name="basis" className="h-6 w-6" checked={basis === 'first_null'} onChange={() => { setBasis('first_null'); }} />
                                <span className="flex-1">
                                    <strong>{several ? 'Each item from its first sale with no cost' : `From the first sale with no cost, ${shortDate(first.first_at)}`}</strong>
                                </span>
                                <span className="text-text-secondary">Default</span>
                            </label>
                            <label className={`flex min-h-touch flex-wrap items-center gap-3 rounded-xl px-3 text-lg ${basis === 'from_date' ? 'bg-panel' : ''}`}>
                                <input type="radio" name="basis" className="h-6 w-6" checked={basis === 'from_date'} onChange={() => { setBasis('from_date'); }} />
                                <span>From a date you choose</span>
                                {basis === 'from_date' && (
                                    <input
                                        type="date"
                                        aria-label="From"
                                        value={from}
                                        className="min-h-12 rounded-lg border-2 border-border px-3"
                                        onChange={(e) => {
                                            setFrom(e.target.value);
                                        }}
                                    />
                                )}
                            </label>
                        </fieldset>

                        {preview !== null && cost !== null && (
                            <div role="region" aria-label="Before you save" className="flex flex-col gap-2 rounded-xl bg-panel p-4 text-lg">
                                <strong>Before you save</strong>
                                <p>
                                    {plural(preview.sales, 'sale', 'sales')} ({naira(preview.revenue_kobo)}) move from <strong>Waiting for a cost</strong> to <strong>Estimated</strong>, with{' '}
                                    {naira(preview.profit_kobo)} estimated profit.
                                </p>
                                {preview.below_cost.length === 0 ? (
                                    <p>No sale was below this cost.</p>
                                ) : (
                                    preview.below_cost.slice(0, 5).map((b) => (
                                        <p key={b.receipt_number + b.item} className="flex items-start gap-2">
                                            <WarningIcon className="mt-0.5 h-5 w-5 shrink-0 text-warning-text" />
                                            <span>
                                                {b.item} was sold below this cost: {naira(b.unit_price_kobo)} on {shortDate(b.recorded_at)} by {b.cashier} ({b.receipt_number}). It will be flagged.
                                            </span>
                                        </p>
                                    ))
                                )}
                                {several && preview.items.length > 0 && (
                                    <table className="w-full text-left">
                                        <thead className="text-text-secondary">
                                            <tr>
                                                <th className="font-normal">Item</th>
                                                <th className="text-right font-normal">Sales</th>
                                                <th className="text-right font-normal">Revenue</th>
                                                <th className="text-right font-normal">Estimated profit</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {preview.items.map((i) => (
                                                <tr key={i.item_id}>
                                                    <td>{i.name}</td>
                                                    <td className="text-right">{i.sales}</td>
                                                    <td className="text-right">{naira(i.revenue_kobo)}</td>
                                                    <td className="text-right">{naira(i.profit_kobo)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                                <p>
                                    Revenue with a cost: {preview.coverage_now}% now, {preview.coverage_after}% after this.
                                </p>
                                <p className="text-text-secondary">Sales stay exactly as they were. The estimate is stored beside them, and each change goes in the audit log.</p>
                            </div>
                        )}

                        <div className="flex flex-wrap justify-end gap-3">
                            <button
                                type="button"
                                className="min-h-touch rounded-xl border-2 border-border px-5 text-lg font-semibold text-action-text"
                                onClick={() => {
                                    setSelected([]);
                                    setPreview(null);
                                    setDigits('');
                                }}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={busy || cost === null || preview === null}
                                className="min-h-touch rounded-xl bg-action px-6 text-xl font-bold text-white disabled:opacity-60"
                                onClick={() => void save()}
                            >
                                {several ? `Save cost for ${plural(selected.length, 'item', 'items')}` : `Save cost for ${plural(preview?.sales ?? 0, 'sale', 'sales')}`}
                            </button>
                        </div>
                    </section>
                )}
            </div>
        </div>
    );
}
