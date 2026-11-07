import { useCallback, useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import type { CounterApi } from '@/counter/use-api';
import { clock, naira, plural, shortDate, when } from './format';
import { ItemPicker, styles } from './pickers';

/**
 * Sales to re-enter after a restore (spec section 9; wireframe LostWindow) [F1, D44]. Each counter keeps a
 * journal of its sales; after a restore it reports the ones the restored data lacks, and customers may
 * hold receipts for them. A manager or the owner re-enters each as a back entry: it keeps the receipt
 * number the customer holds and its original time, prints nothing and is flagged as back-dated.
 */
interface LostLine {
    name: string;
    colour: string | null;
    qty: number;
    unit_price_kobo: number;
    item_found: boolean;
}

interface Lost {
    id: string;
    receipt_number: string;
    terminal: string;
    terminal_name: string;
    sold_at: string;
    business_day: string;
    cashier: string | null;
    customer_type: string;
    total_kobo: number;
    lines: LostLine[];
    payments: { method: string; amount_kobo: number; reference: string | null }[];
    entered: { sale_id: string; at: string; by: string } | null;
}

interface View {
    restore: { at: string; reason: string; restored_to: string | null; epochs: Record<string, number> } | null;
    counters: { code: string; name: string; epoch: number | null; reported_at: string | null; missing: number | null; raised_to: number | null }[];
    lost: Lost[];
    waiting: number;
}

const { button, primary } = styles;
const methodName: Record<string, string> = { cash: 'Cash', transfer: 'Transfer', pos: 'POS' };
const reasonName: Record<string, string> = { restore: 'restored from its backup', replacement: 'replaced and restored', reinstall: 'reinstalled' };

function paidBy(l: Lost): string {
    return [...new Set(l.payments.map((p) => methodName[p.method] ?? p.method))].join(' + ');
}

export function LostWindow({ api, onChanged }: { api: CounterApi; onChanged: () => void }) {
    const [view, setView] = useState<View | null>(null);
    const [open, setOpen] = useState<Lost | null>(null);
    const [message, setMessage] = useState<{ tone: 'error' | 'ok'; text: string } | null>(null);

    const load = useCallback(() => {
        void api
            .get<View>('/api/back-office/recovery')
            .then((a) => {
                if (a.ok) setView(a.body);
            })
            .catch(() => {
                setMessage({ tone: 'error', text: 'The list could not load. Check the connection.' });
            });
    }, [api]);

    useEffect(() => {
        load();
    }, [load]);

    if (view === null) return <p className="text-lg text-text-secondary">Loading…</p>;

    const waiting = view.lost.filter((l) => l.entered === null);
    const done = view.lost.filter((l) => l.entered !== null);
    const notReported = view.restore === null ? [] : view.counters.filter((c) => c.reported_at === null);

    return (
        <div className="flex flex-col gap-5">
            <header className="flex flex-col gap-1">
                <h1 className="text-3xl font-bold">Sales to re-enter after the restore</h1>
                {view.restore !== null && (
                    <p className="text-lg text-text-secondary">
                        Lost-window check · the server was {reasonName[view.restore.reason] ?? 'restored'} {when(view.restore.at)}
                    </p>
                )}
            </header>

            {message !== null && (message.tone === 'error' ? <Notice tone="error">{message.text}</Notice> : <p role="status" className="rounded-xl bg-panel p-4 text-lg">{message.text}</p>)}

            <div role="status" className="flex items-start gap-3 rounded-2xl bg-panel p-4">
                <svg aria-hidden="true" viewBox="0 0 24 24" className="mt-0.5 h-6 w-6 shrink-0" fill="none" stroke="currentColor" strokeWidth="2">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 11v5M12 8h.01" strokeLinecap="round" />
                </svg>
                <p className="text-lg leading-normal">
                    {view.restore === null
                        ? 'Each counter keeps its own journal of sales. These sales are in a counter journal but missing from the server, and customers may hold receipts for them, so re-enter each one.'
                        : `${view.restore.restored_to === null ? 'The restored data holds no sales.' : `The restored data holds sales up to ${when(view.restore.restored_to)}.`} Each counter keeps its own journal of sales and reports it when it reconnects. ${
                              waiting.length === 0
                                  ? 'No sale is waiting to be re-entered.'
                                  : `${plural(waiting.length, 'sale is', 'sales are')} in a counter journal but missing from the restored data, and customers may hold receipts for them, so re-enter each one.`
                          }`}
                </p>
            </div>

            {notReported.length > 0 && (
                <Notice tone="warning">
                    Not reported yet: {notReported.map((c) => c.name).join(', ')}. A counter reports its journal as soon as it is switched on and reaches the server; it sells again only once it has. Switch it on, or check its cable.
                </Notice>
            )}

            {view.restore !== null && (
                <section aria-labelledby="counters-heading" className="flex flex-col gap-2">
                    <h2 id="counters-heading" className="text-xl font-bold">
                        Counters
                    </h2>
                    <ul className="grid gap-2 medium:grid-cols-2 large:grid-cols-3">
                        {view.counters.map((c) => (
                            <li key={c.code} className="flex flex-col gap-1 rounded-xl border-2 border-border p-3">
                                <span className="text-lg font-semibold">
                                    {c.name} · {c.code}
                                </span>
                                <span className="text-base">
                                    {c.reported_at === null
                                        ? 'Journal not reported yet'
                                        : `Journal reported ${when(c.reported_at)}${c.missing === 0 ? ', nothing missing' : `, ${plural(c.missing ?? 0, 'sale', 'sales')} missing`}`}
                                </span>
                                {c.epoch !== null && <span className="text-base text-text-secondary">New receipts start {c.code}-E{c.epoch}-</span>}
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {waiting.length > 0 && (
                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-lg tabular-nums">
                        <thead>
                            <tr className="text-left text-base text-text-secondary">
                                <th className="border-b border-border px-3 py-2 font-semibold">Receipt</th>
                                <th className="border-b border-border px-3 py-2 font-semibold">Counter</th>
                                <th className="border-b border-border px-3 py-2 font-semibold">Time</th>
                                <th className="border-b border-border px-3 py-2 font-semibold">Cashier</th>
                                <th className="border-b border-border px-3 py-2 font-semibold">Paid by</th>
                                <th className="border-b border-border px-3 py-2 text-right font-semibold">Total</th>
                                <th className="border-b border-border px-3 py-2">
                                    <span className="sr-only">Action</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {waiting.map((l) => (
                                <tr key={l.id}>
                                    <td className="border-b border-border px-3 py-2 font-semibold">{l.receipt_number}</td>
                                    <td className="border-b border-border px-3 py-2">{l.terminal_name}</td>
                                    <td className="border-b border-border px-3 py-2">
                                        {shortDate(l.sold_at)} {clock(l.sold_at)}
                                    </td>
                                    <td className="border-b border-border px-3 py-2">{l.cashier ?? ''}</td>
                                    <td className="border-b border-border px-3 py-2">{paidBy(l)}</td>
                                    <td className="border-b border-border px-3 py-2 text-right font-bold">{naira(l.total_kobo)}</td>
                                    <td className="border-b border-border px-3 py-2 text-right">
                                        <button
                                            type="button"
                                            className={primary}
                                            onClick={() => {
                                                setMessage(null);
                                                setOpen(l);
                                            }}
                                        >
                                            Re-enter
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {done.length > 0 && (
                <section aria-labelledby="done-heading" className="flex flex-col gap-2">
                    <h2 id="done-heading" className="text-xl font-bold">
                        Re-entered
                    </h2>
                    <ul className="flex flex-col">
                        {done.map((l) => (
                            <li key={l.id} className="flex flex-wrap items-baseline justify-between gap-2 border-b border-border py-2 text-lg">
                                <span className="font-semibold">{l.receipt_number}</span>
                                <span>{naira(l.total_kobo)}</span>
                                <span className="text-text-secondary">{l.entered === null ? '' : `by ${l.entered.by}, ${when(l.entered.at)}`}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <p className="text-base leading-normal text-text-secondary">
                Re-entering keeps the original receipt number, so a return or a reprint finds it, and marks the sale as back-dated. Nothing prints: the customer already has the receipt. New sales on each counter start a new epoch, so no receipt number ever repeats.
            </p>

            {open !== null && (
                <ReEnter
                    api={api}
                    lost={open}
                    onClose={() => {
                        setOpen(null);
                    }}
                    onDone={(text) => {
                        setOpen(null);
                        setMessage({ tone: 'ok', text });
                        load();
                        onChanged();
                    }}
                />
            )}
        </div>
    );
}

/** One lost sale, as the journal holds it, with a confirm; an item the restored data lacks is chosen first. */
function ReEnter({ api, lost, onClose, onDone }: { api: CounterApi; lost: Lost; onClose: () => void; onDone: (message: string) => void }) {
    const [chosen, setChosen] = useState<Record<number, { id: string; name: string }>>({});
    const [picking, setPicking] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    const needs = lost.lines.map((l, i) => (l.item_found ? null : i + 1)).filter((n): n is number => n !== null);
    const ready = needs.every((n) => chosen[n] !== undefined);

    const confirm = async () => {
        setBusy(true);
        setProblem(null);
        try {
            const items = Object.fromEntries(Object.entries(chosen).map(([line, item]) => [line, item.id]));
            const a = await api.post<{ message?: string; receipt_number?: string; already?: boolean }>(`/api/back-office/recovery/lost/${lost.id}/re-enter`, { items });
            if (a.ok) onDone(a.body.already === true ? `${lost.receipt_number} was already re-entered.` : `${lost.receipt_number} is re-entered, under its original receipt number.`);
            else setProblem(a.body.message ?? 'That did not save. Try again.');
        } catch {
            setProblem('The device cannot reach the shop server. Try again.');
        } finally {
            setBusy(false);
        }
    };

    if (picking !== null) {
        return (
            <ItemPicker
                api={api}
                title={`Line ${String(picking)}: choose the item`}
                onPick={(item) => {
                    setChosen((c) => ({ ...c, [picking]: { id: item.id, name: item.name } }));
                    setPicking(null);
                }}
                onClose={() => {
                    setPicking(null);
                }}
            />
        );
    }

    return (
        <Sheet title={`Re-enter ${lost.receipt_number}`} onClose={onClose} wide>
            <p className="text-lg">
                {lost.terminal_name} · {shortDate(lost.sold_at)} {clock(lost.sold_at)} · {lost.cashier ?? 'cashier not known'} · {lost.customer_type === 'wholesale' ? 'Wholesale' : 'Retail'}
            </p>
            <ul className="flex flex-col">
                {lost.lines.map((l, i) => (
                    <li key={i} className="flex flex-col gap-2 border-b border-border py-2">
                        <div className="flex flex-wrap items-baseline justify-between gap-2 text-lg">
                            <span>
                                {l.qty > 1 ? `${String(l.qty)} × ` : ''}
                                {l.name}
                                {l.colour === null ? '' : ` · ${l.colour}`}
                            </span>
                            <span className="font-semibold tabular-nums">{naira(l.qty * l.unit_price_kobo)}</span>
                        </div>
                        {!l.item_found && (
                            <div className="flex flex-wrap items-center gap-3">
                                <span className="text-base">{chosen[i + 1] === undefined ? 'This item is not in the restored data.' : `Recorded under ${chosen[i + 1]?.name ?? ''}; the line keeps its printed name.`}</span>
                                <button
                                    type="button"
                                    className={button}
                                    onClick={() => {
                                        setPicking(i + 1);
                                    }}
                                >
                                    {chosen[i + 1] === undefined ? 'Choose the item' : 'Change'}
                                </button>
                            </div>
                        )}
                    </li>
                ))}
            </ul>
            <p className="text-lg">
                Paid {lost.payments.map((p) => `${methodName[p.method] ?? p.method} ${naira(p.amount_kobo)}${p.reference === null ? '' : ` (ref ${p.reference})`}`).join(' + ')} · total{' '}
                <span className="font-bold">{naira(lost.total_kobo)}</span>
            </p>
            {problem !== null && <Notice tone="error">{problem}</Notice>}
            <div className="flex flex-wrap justify-end gap-3">
                <button type="button" className={button} onClick={onClose}>
                    Cancel
                </button>
                <button type="button" className={primary} disabled={busy || !ready} onClick={() => void confirm()}>
                    Re-enter sale
                </button>
            </div>
        </Sheet>
    );
}
