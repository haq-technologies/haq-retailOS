import { useCallback, useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import type { CounterApi } from '@/counter/use-api';
import type { ApiResult } from '@/lib/api';
import { clock, longDay, naira, nairaToKobo } from './format';
import { field, methodName, verdicts, Mark, difference, button, primary } from './pilot-parts';
import type { MethodRow, Day } from './pilot-types';
import { SaleSheet } from './sale-sheet';

export function DaySheet({ api, day, onClose, onChanged }: { api: CounterApi; day: string; onClose: () => void; onChanged: () => void }) {
    const [data, setData] = useState<Day | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [amounts, setAmounts] = useState<Record<string, string>>({ cash: '', transfer: '', pos: '' });
    const [tracing, setTracing] = useState<MethodRow | null>(null);
    const [sale, setSale] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const fetchDay = useCallback(() => api.get<Day>(`/api/back-office/pilot/days/${day}`), [api, day]);
    const show = useCallback((a: ApiResult<Day>) => {
        if (!a.ok) return;
        setData(a.body);
        setAmounts(Object.fromEntries(a.body.methods.map((m) => [m.method, m.notebook_kobo === null ? '' : String(m.notebook_kobo / 100)])));
    }, []);

    useEffect(() => {
        void fetchDay()
            .then(show)
            .catch(() => {
                setError('The day could not load. Check the connection.');
            });
    }, [fetchDay, show]);

    const send = async (url: string, body: unknown): Promise<boolean> => {
        setBusy(true);
        setError(null);
        try {
            const a = await api.post<{ message?: string }>(url, body);
            if (!a.ok) {
                setError(a.body.message ?? 'That did not work. Try again.');
                return false;
            }
            // Shown before the form closes, so no button from before the change can be tapped.
            show(await fetchDay());
            onChanged();
            return true;
        } catch {
            setError('The device cannot reach the shop server. Try again.');
            return false;
        } finally {
            setBusy(false);
        }
    };

    const saveNotebook = () => {
        const kobo = Object.fromEntries(Object.entries(amounts).map(([m, text]) => [m, nairaToKobo(text)]));
        if (Object.values(kobo).some((k) => k === null)) {
            setError('Type each total in naira, such as 12500; type 0 for none.');
            return;
        }
        void send('/api/back-office/pilot/notebook', { day, cash_kobo: kobo.cash, transfer_kobo: kobo.transfer, pos_kobo: kobo.pos });
    };

    const title = data?.n !== null && data?.n !== undefined ? `Day ${String(data.n)} · ${longDay(day)}` : longDay(day);
    if (sale !== null) return <SaleSheet api={api} saleIds={[sale]} onClose={() => { setSale(null); }} />;

    return (
        <Sheet title={title} onClose={onClose} wide>
            {data === null ? (
                error === null ? <p className="text-lg text-text-secondary">Loading…</p> : <Notice tone="error">{error}</Notice>
            ) : (
                <div className="flex flex-col gap-5">
                    <p className="text-lg">
                        <Mark tone={verdicts[data.verdict]?.tone ?? 'wait'} label={verdicts[data.verdict]?.label ?? data.verdict} />
                        {data.today && <span className="text-text-secondary"> · still trading today</span>}
                        {data.entered !== null && <span className="text-text-secondary"> · notebook typed by {data.entered.by} at {clock(data.entered.at)}</span>}
                    </p>
                    {error !== null && <Notice tone="error">{error}</Notice>}

                    <div className="overflow-x-auto rounded-2xl border-2 border-border">
                        <table className="w-full min-w-[600px] text-left text-lg tabular-nums">
                            <thead className="text-base text-text-secondary">
                                <tr className="border-b border-border">
                                    <th scope="col" className="p-3 font-semibold">Payment</th>
                                    <th scope="col" className="p-3 text-right font-semibold">App</th>
                                    <th scope="col" className="p-3 text-right font-semibold">Notebook</th>
                                    <th scope="col" className="p-3 font-semibold">Difference</th>
                                    <th scope="col" className="p-3"><span className="sr-only">Trace</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.methods.map((m) => (
                                    <tr key={m.method} className="border-b border-border last:border-b-0">
                                        <td className="p-3 font-semibold">{methodName[m.method] ?? m.method}</td>
                                        <td className="p-3 text-right">{naira(m.app_kobo)}</td>
                                        <td className="p-3 text-right">
                                            {data.open ? (
                                                <label>
                                                    <span className="sr-only">Notebook {methodName[m.method]}</span>
                                                    <input
                                                        inputMode="decimal"
                                                        value={amounts[m.method] ?? ''}
                                                        placeholder="₦"
                                                        className={`${field} w-36 text-right`}
                                                        onChange={(e) => { setAmounts({ ...amounts, [m.method]: e.target.value }); }}
                                                    />
                                                </label>
                                            ) : m.notebook_kobo === null ? '–' : naira(m.notebook_kobo)}
                                        </td>
                                        <td className="p-3">
                                            {m.difference_kobo === null ? '–' : difference(m.difference_kobo)}
                                            {m.left_kobo !== null && m.left_kobo !== 0 && m.left_kobo !== m.difference_kobo && <span className="block text-base text-text-secondary">{naira(Math.abs(m.left_kobo))} left to trace</span>}
                                        </td>
                                        <td className="p-3 text-right">
                                            {data.open && m.left_kobo !== null && m.left_kobo !== 0 && (
                                                <button type="button" className={button} onClick={() => { setTracing(m); }}>
                                                    Trace
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {data.open && (
                        <div className="flex flex-wrap items-center gap-3">
                            <button type="button" disabled={busy} className={primary} onClick={saveNotebook}>
                                {data.notebook_kobo === null ? 'Save the notebook’s totals' : 'Correct the notebook’s totals'}
                            </button>
                            <span className="text-text-secondary">Type them as the notebook shows them, before looking at the app&apos;s.</span>
                        </div>
                    )}

                    {tracing !== null && (
                        <TraceForm
                            row={tracing}
                            busy={busy}
                            onCancel={() => { setTracing(null); }}
                            onSave={async (body) => {
                                if (await send('/api/back-office/pilot/traces', { day, method: tracing.method, ...body })) setTracing(null);
                            }}
                        />
                    )}

                    {data.traces.length > 0 && (
                        <section aria-labelledby="pilot-traces" className="flex flex-col gap-2">
                            <h3 id="pilot-traces" className="text-lg font-bold">
                                Traced and written down
                            </h3>
                            <ul className="flex flex-col gap-2">
                                {data.traces.map((t) => (
                                    <li key={t.id} className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-border px-4 py-3">
                                        <span className="flex-1">
                                            <strong>
                                                {methodName[t.method]} {naira(Math.abs(t.amount_kobo))}
                                            </strong>{' '}
                                            · {t.side === 'notebook' ? 'the notebook was wrong' : 'the app was wrong'} · {t.what}
                                            {t.receipt_number !== null && ` · ${t.receipt_number}`}
                                            <span className="block text-base text-text-secondary">
                                                {t.by}, {clock(t.at)}
                                            </span>
                                        </span>
                                        {data.open && (
                                            <button type="button" disabled={busy} className={button} onClick={() => void send(`/api/back-office/pilot/traces/${t.id}/withdraw`, {})}>
                                                Withdraw
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <section aria-labelledby="pilot-sales" className="flex flex-col gap-2">
                        <h3 id="pilot-sales" className="text-lg font-bold">
                            The day&apos;s sales ({data.sales.length})
                        </h3>
                        <p className="text-text-secondary">Tick them against the notebook to find a difference. Open one to see its items.</p>
                        {data.sales.length > 0 && (
                            <div className="max-h-96 overflow-auto rounded-2xl border-2 border-border">
                                <table className="w-full min-w-[600px] text-left text-base tabular-nums">
                                    <thead className="sticky top-0 bg-background text-text-secondary">
                                        <tr className="border-b border-border">
                                            <th scope="col" className="p-2 font-semibold">Receipt</th>
                                            <th scope="col" className="p-2 font-semibold">Time</th>
                                            <th scope="col" className="p-2 font-semibold">Cashier</th>
                                            <th scope="col" className="p-2 text-right font-semibold">Cash</th>
                                            <th scope="col" className="p-2 text-right font-semibold">Transfer</th>
                                            <th scope="col" className="p-2 text-right font-semibold">POS</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {data.sales.map((s) => (
                                            <tr key={s.id} className="border-b border-border last:border-b-0">
                                                <td className="p-2">
                                                    <button type="button" className="min-h-touch font-semibold text-action-text" onClick={() => { setSale(s.id); }}>
                                                        {s.receipt_number}
                                                    </button>
                                                    {s.back_entry && <span className="text-text-secondary"> · re-entered</span>}
                                                </td>
                                                <td className="p-2">{clock(s.at)}</td>
                                                <td className="p-2">{s.cashier}</td>
                                                <td className="p-2 text-right">{s.cash_kobo === 0 ? '' : naira(s.cash_kobo)}</td>
                                                <td className="p-2 text-right">{s.transfer_kobo === 0 ? '' : naira(s.transfer_kobo)}</td>
                                                <td className="p-2 text-right">{s.pos_kobo === 0 ? '' : naira(s.pos_kobo)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                </div>
            )}
        </Sheet>
    );
}

function TraceForm({ row, busy, onCancel, onSave }: { row: MethodRow; busy: boolean; onCancel: () => void; onSave: (body: { amount_kobo: number; side: string; what: string; receipt_number: string | null }) => Promise<void> }) {
    const left = row.left_kobo ?? 0;
    const [amount, setAmount] = useState(String(Math.abs(left) / 100));
    const [side, setSide] = useState<'notebook' | 'app' | null>(null);
    const [what, setWhat] = useState('');
    const [receipt, setReceipt] = useState('');
    const [error, setError] = useState<string | null>(null);

    const save = () => {
        const kobo = nairaToKobo(amount);
        if (kobo === null || kobo <= 0) {
            setError('Type the amount this explains, in naira.');
            return;
        }
        if (side === null) {
            setError('Choose whose record was wrong.');
            return;
        }
        if (what.trim().length < 3) {
            setError('Write down what happened, in a few words.');
            return;
        }
        setError(null);
        void onSave({ amount_kobo: kobo, side, what: what.trim(), receipt_number: receipt.trim() === '' ? null : receipt.trim() });
    };

    return (
        <section aria-labelledby="pilot-trace" className="flex flex-col gap-3 rounded-2xl border-2 border-action p-4">
            <h3 id="pilot-trace" className="text-lg font-bold">
                Trace {methodName[row.method]}: {difference(left)}
            </h3>
            {error !== null && <Notice tone="error">{error}</Notice>}
            <fieldset className="flex flex-col gap-2">
                <legend className="mb-1 text-lg font-semibold">Whose record was wrong?</legend>
                {(
                    [
                        ['notebook', 'The notebook', 'A sale never written down, written wrongly, or added up wrongly. The day can still match.'],
                        ['app', 'The app', 'A sale never rung, rung twice, at the wrong price or as the wrong payment. The day does not match.'],
                    ] as const
                ).map(([value, label, hint]) => (
                    <label key={value} className={`flex min-h-touch cursor-pointer items-start gap-3 rounded-xl border-2 px-4 py-3 ${side === value ? 'border-action' : 'border-border'}`}>
                        <input type="radio" name="side" className="mt-1 h-5 w-5" checked={side === value} onChange={() => { setSide(value); }} />
                        <span>
                            <span className="block font-semibold">{label}</span>
                            <span className="text-text-secondary">{hint}</span>
                        </span>
                    </label>
                ))}
            </fieldset>
            <div className="flex flex-wrap gap-3">
                <label className="flex flex-col gap-1 text-lg font-semibold">
                    Amount it explains
                    <input inputMode="decimal" value={amount} className={`${field} w-40`} onChange={(e) => { setAmount(e.target.value); }} />
                </label>
                <label className="flex flex-col gap-1 text-lg font-semibold">
                    Receipt number (if one sale)
                    <input value={receipt} maxLength={20} placeholder="T01-E1-000123" className={`${field} w-56`} onChange={(e) => { setReceipt(e.target.value); }} />
                </label>
            </div>
            <label className="flex flex-col gap-1 text-lg font-semibold">
                What happened
                <input value={what} maxLength={200} className={field} onChange={(e) => { setWhat(e.target.value); }} />
            </label>
            <div className="flex flex-wrap gap-3">
                <button type="button" disabled={busy} className={primary} onClick={save}>
                    Write it down
                </button>
                <button type="button" className={button} onClick={onCancel}>
                    Cancel
                </button>
            </div>
        </section>
    );
}
