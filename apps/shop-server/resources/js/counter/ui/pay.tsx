import { useCallback, useEffect, useState } from 'react';
import { Keypad, padKeyFrom } from '@/components/keypad';
import { Notice } from '@/components/notice';
import type { Tile } from '@/components/sign-in-panel';
import { WarningIcon } from '@/components/icons';
import { finishPayload, markChecked, paymentState, totalKobo, type Cart, type Payment } from '../cart';
import { digitsToKobo, koboToDigits, naira, quickCash, typeDigits } from '../money';
import type { CounterSettings } from '../types';
import type { CounterApi } from '../use-api';
import { ApprovalPad } from './approval-pad';
import { newId, type FinishAnswer, type SaleSummary } from './types';

/**
 * Pay (spec section 7) [D11, F12, S2]: cash with the amount received and change, transfer with the
 * bank-alert tick and a reference, POS with nothing typed, or a split. Finish sends the sale once; a lost
 * answer is safe to retry, because the idempotency key stays the same. Wireframes: PayCash, PayTransfer,
 * PaySplit.
 */
type Method = 'cash' | 'transfer' | 'pos' | 'split';

interface SplitRow {
    method: 'cash' | 'transfer' | 'pos';
    digits: string;
    alertSeen: boolean;
    reference: string;
}

type Problem =
    | { kind: 'message'; message: string }
    | { kind: 'offline' }
    | { kind: 'confirm'; lineId: string; message: string }
    | { kind: 'retype'; lineId: string; message: string; digits: string }
    | { kind: 'approval'; message: string; approval: NonNullable<FinishAnswer['approval']> };

interface Props {
    cart: Cart;
    setCart: (next: Cart) => void;
    settings: CounterSettings;
    people: Tile[];
    api: CounterApi;
    onlyTransfer: boolean;
    onFinished: (sale: SaleSummary) => void;
    onAwaiting: (payment: Payment, label: string) => Promise<string | null>;
    onBack: () => void;
    /** After a restore, Finish waits for the counter's journal report (D44); true once it is in. */
    reportJournal: () => Promise<boolean>;
}

export function Pay({ cart, setCart, settings, people, api, onlyTransfer, onFinished, onAwaiting, onBack, reportJournal }: Props) {
    const total = totalKobo(cart);
    const [method, setMethod] = useState<Method>(onlyTransfer ? 'transfer' : 'cash');
    const [cash, setCash] = useState('');
    const [alertSeen, setAlertSeen] = useState(false);
    const [reference, setReference] = useState('');
    const [split, setSplit] = useState<SplitRow[]>([
        { method: 'cash', digits: '', alertSeen: false, reference: '' },
        { method: 'transfer', digits: koboToDigits(total), alertSeen: false, reference: '' },
    ]);
    const [selected, setSelected] = useState(0);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<Problem | null>(null);

    const payments = useCallback((): Payment[] | null => {
        const tick = (seen: boolean) => seen || !settings.transferAlertTick;
        if (method === 'cash') {
            const tendered = digitsToKobo(cash);
            return tendered >= total ? [{ method: 'cash', amountKobo: total, tenderedKobo: tendered }] : null;
        }
        if (method === 'transfer') {
            return tick(alertSeen) ? [{ method: 'transfer', amountKobo: total, reference, alertSeen }] : null;
        }
        if (method === 'pos') {
            return [{ method: 'pos', amountKobo: total }];
        }
        const rows = split.filter((r) => digitsToKobo(r.digits) > 0);
        const list: Payment[] = rows.map((r) =>
            r.method === 'cash'
                ? { method: 'cash', amountKobo: digitsToKobo(r.digits), tenderedKobo: digitsToKobo(r.digits) }
                : r.method === 'transfer'
                  ? { method: 'transfer', amountKobo: digitsToKobo(r.digits), reference: r.reference, alertSeen: r.alertSeen }
                  : { method: 'pos', amountKobo: digitsToKobo(r.digits) },
        );
        const state = paymentState(total, list);
        const ticked = rows.every((r) => r.method !== 'transfer' || tick(r.alertSeen));
        return state.paid === total && ticked ? list : null;
    }, [method, cash, total, alertSeen, reference, split, settings.transferAlertTick]);

    const ready = payments() !== null && total > 0;

    const finish = useCallback(
        async (forCart: Cart) => {
            const list = payments();
            if (list === null || busy) return;
            const { payload, cart: keyed } = finishPayload(forCart, list, newId);
            setCart(keyed);
            setBusy(true);
            setProblem(null);
            try {
                let answer = await api.post<FinishAnswer>('/api/counter/sales', payload, 30_000);
                // The server was restored: the journal goes first, then the same sale with the same key.
                if (!answer.ok && answer.body.journal_needed === true && (await reportJournal())) {
                    answer = await api.post<FinishAnswer>('/api/counter/sales', payload, 30_000);
                }
                if (answer.ok && answer.body.sale !== undefined) {
                    onFinished(answer.body.sale);
                    return;
                }
                const message = answer.body.message ?? 'The sale did not finish. Try again.';
                if (answer.body.check !== undefined) {
                    const lineId = answer.body.check.client_line_id;
                    setProblem(answer.body.check.kind === 'retype' ? { kind: 'retype', lineId, message, digits: '' } : { kind: 'confirm', lineId, message });
                } else if (answer.body.approval !== undefined) {
                    setProblem({ kind: 'approval', message, approval: answer.body.approval });
                } else {
                    setProblem({ kind: 'message', message });
                }
            } catch {
                setProblem({ kind: 'offline' });
            } finally {
                setBusy(false);
            }
        },
        [payments, busy, setCart, api, onFinished, reportJournal],
    );

    const typeKey = useCallback(
        (key: string) => {
            if (problem?.kind === 'retype') {
                setProblem({ ...problem, digits: typeDigits(problem.digits, key) });
            } else if (method === 'cash') {
                setCash((d) => typeDigits(d, key));
            } else if (method === 'split') {
                setSplit((rows) => rows.map((r, i) => (i === selected ? { ...r, digits: typeDigits(r.digits, key) } : r)));
            }
        },
        [problem, method, selected, setCash, setSplit, setProblem],
    );

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.target instanceof HTMLInputElement && event.target.type === 'text') return;
            if (event.key === 'Escape') {
                event.preventDefault();
                onBack();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                if (ready) void finish(cart);
            } else {
                const key = padKeyFrom(event.key);
                if (key !== null) {
                    event.preventDefault();
                    typeKey(key);
                }
            }
        };
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('keydown', onKey);
        };
    }, [ready, finish, cart, onBack, typeKey]);

    const tabs: Method[] = onlyTransfer ? ['transfer'] : ['cash', 'transfer', 'pos', 'split'];
    const tendered = digitsToKobo(cash);
    const splitState = paymentState(total, payments() ?? split.map((r) => ({ method: 'pos' as const, amountKobo: digitsToKobo(r.digits) })));

    return (
        <section aria-label="Pay" className="flex flex-col gap-4">
            <header className="flex flex-wrap items-center justify-between gap-3">
                <button type="button" className="min-h-touch rounded-xl border-2 border-border px-4 text-lg" onClick={onBack}>
                    Back to sale
                </button>
                <h2 className="text-3xl font-bold">Pay {naira(total)}</h2>
            </header>

            <div role="tablist" aria-label="Payment method" className="grid grid-cols-4 gap-2">
                {tabs.map((m) => (
                    <button
                        key={m}
                        type="button"
                        role="tab"
                        aria-selected={method === m}
                        className={`min-h-touch rounded-xl border-2 text-lg font-semibold ${method === m ? 'border-action bg-action text-white' : 'border-border'}`}
                        onClick={() => {
                            setMethod(m);
                            setProblem(null);
                        }}
                    >
                        {m === 'pos' ? 'POS' : m.charAt(0).toUpperCase() + m.slice(1)}
                    </button>
                ))}
            </div>

            {method === 'cash' && (
                <div className="flex flex-col gap-3">
                    <span className="text-lg font-semibold">Cash received</span>
                    <output aria-live="polite" className="flex min-h-touch items-center rounded-xl border-2 border-action px-4 text-4xl font-bold">
                        {cash === '' ? <span className="text-text-secondary">₦</span> : naira(tendered)}
                    </output>
                    <div className="flex flex-wrap gap-2">
                        {[total, ...quickCash(total)].map((amount, i) => (
                            <button
                                key={amount}
                                type="button"
                                className="min-h-touch rounded-xl border-2 border-border px-4 text-lg font-semibold"
                                onClick={() => {
                                    setCash(koboToDigits(amount));
                                }}
                            >
                                {i === 0 ? 'Exact' : naira(amount)}
                            </button>
                        ))}
                    </div>
                    <p className="flex items-baseline justify-between text-2xl">
                        <span>Change</span>
                        <strong>{tendered >= total ? naira(tendered - total) : '—'}</strong>
                    </p>
                </div>
            )}

            {method === 'transfer' && (
                <div className="flex flex-col gap-3">
                    <p className="flex items-baseline justify-between text-xl">
                        <span>Transfer amount</span>
                        <strong>{naira(total)}</strong>
                    </p>
                    <label className="flex min-h-touch items-center gap-3 rounded-xl border-2 border-border px-4 text-lg">
                        <input
                            type="checkbox"
                            className="h-7 w-7"
                            checked={alertSeen}
                            onChange={(e) => {
                                setAlertSeen(e.target.checked);
                            }}
                        />
                        I have seen the bank alert for {naira(total)}
                    </label>
                    <label className="flex flex-col gap-1 text-lg">
                        Transfer reference
                        <input
                            type="text"
                            value={reference}
                            maxLength={60}
                            className="min-h-touch rounded-xl border-2 border-border px-4"
                            onChange={(e) => {
                                setReference(e.target.value);
                            }}
                        />
                        <span className="text-text-secondary">A sale with no reference still goes through, but the owner sees it flagged.</span>
                    </label>
                    {!onlyTransfer && (
                        <div className="flex flex-col gap-2 rounded-xl bg-panel p-4">
                            <p className="font-semibold">Alert not here yet?</p>
                            <p className="text-text-secondary">
                                Tap Awaiting transfer. The sale waits in Pending transfers on every counter, with a timer, and you can serve the next customer. Whoever sees
                                the alert confirms it there, and the receipt prints. One still waiting after 30 minutes is flagged.
                            </p>
                            <button
                                type="button"
                                disabled={busy}
                                className="min-h-touch self-start rounded-xl border-2 border-warning-text px-4 text-lg font-semibold text-warning-text"
                                onClick={() => {
                                    setBusy(true);
                                    void onAwaiting({ method: 'transfer', amountKobo: total, reference, alertSeen: false }, reference).then((error) => {
                                        setBusy(false);
                                        if (error !== null) setProblem({ kind: 'message', message: error });
                                    });
                                }}
                            >
                                Awaiting transfer
                            </button>
                        </div>
                    )}
                    {settings.transferAlertTick && !alertSeen && <p className="text-text-secondary">Tick the bank alert to finish.</p>}
                </div>
            )}

            {method === 'pos' && (
                <p className="flex items-baseline justify-between rounded-xl bg-panel p-4 text-xl">
                    <span>POS payment</span>
                    <strong>{naira(total)}</strong>
                </p>
            )}

            {method === 'split' && (
                <div className="flex flex-col gap-3">
                    {split.map((row, i) => (
                        <div key={i} className={`flex flex-col gap-2 rounded-xl border-2 p-3 ${i === selected ? 'border-action' : 'border-border'}`}>
                            <div className="flex flex-wrap items-center gap-2">
                                {(['cash', 'transfer', 'pos'] as const).map((m) => (
                                    <button
                                        key={m}
                                        type="button"
                                        aria-pressed={row.method === m}
                                        className={`min-h-touch rounded-xl border-2 px-3 ${row.method === m ? 'border-action font-semibold' : 'border-border'}`}
                                        onClick={() => {
                                            setSplit((rows) => rows.map((r, j) => (j === i ? { ...r, method: m } : r)));
                                            setSelected(i);
                                        }}
                                    >
                                        {m === 'pos' ? 'POS' : m.charAt(0).toUpperCase() + m.slice(1)}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    className="ml-auto min-h-touch rounded-xl px-3 text-2xl font-bold"
                                    onClick={() => {
                                        setSelected(i);
                                    }}
                                >
                                    {row.digits === '' ? '₦0' : naira(digitsToKobo(row.digits))}
                                </button>
                            </div>
                            {row.method === 'transfer' && (
                                <div className="flex flex-wrap items-center gap-3">
                                    <label className="flex min-h-touch items-center gap-2">
                                        <input
                                            type="checkbox"
                                            className="h-6 w-6"
                                            checked={row.alertSeen}
                                            onChange={(e) => {
                                                setSplit((rows) => rows.map((r, j) => (j === i ? { ...r, alertSeen: e.target.checked } : r)));
                                            }}
                                        />
                                        Bank alert seen
                                    </label>
                                    <input
                                        type="text"
                                        placeholder="Transfer reference"
                                        aria-label="Transfer reference"
                                        value={row.reference}
                                        maxLength={60}
                                        className="min-h-touch flex-1 rounded-xl border-2 border-border px-3"
                                        onChange={(e) => {
                                            setSplit((rows) => rows.map((r, j) => (j === i ? { ...r, reference: e.target.value } : r)));
                                        }}
                                    />
                                </div>
                            )}
                        </div>
                    ))}
                    <button
                        type="button"
                        className="min-h-touch self-start rounded-xl border-2 border-border px-4 text-lg"
                        onClick={() => {
                            const left = Math.max(0, total - split.reduce((s, r) => s + digitsToKobo(r.digits), 0));
                            setSplit((rows) => [...rows, { method: 'pos', digits: koboToDigits(left), alertSeen: false, reference: '' }]);
                            setSelected(split.length);
                        }}
                    >
                        Add a payment
                    </button>
                    <p className="text-lg">
                        Paid {naira(splitState.paid)} of {naira(total)} · {splitState.left === 0 ? 'Nothing left to pay' : `${naira(splitState.left)} left`}
                    </p>
                    <p className="text-text-secondary">Typing changes the selected payment: {split[selected]?.method === 'pos' ? 'POS' : (split[selected]?.method ?? '')}</p>
                </div>
            )}

            {problem !== null && (
                <div className="flex flex-col gap-3">
                    {problem.kind === 'offline' ? (
                        <Notice tone="warning">The server did not answer. The sale is kept; tap Finish again. It will not be sold twice.</Notice>
                    ) : (
                        <Notice tone={problem.kind === 'message' ? 'error' : 'warning'}>{problem.message}</Notice>
                    )}
                    {problem.kind === 'confirm' && (
                        <button
                            type="button"
                            className="min-h-touch rounded-xl bg-warning text-lg font-bold text-text"
                            onClick={() => {
                                const next = markChecked(cart, problem.lineId, { confirmedOutsideRange: true });
                                setCart(next);
                                void finish(next);
                            }}
                        >
                            <span className="inline-flex items-center gap-2">
                                <WarningIcon /> Confirm the price and finish
                            </span>
                        </button>
                    )}
                    {problem.kind === 'retype' && (
                        <div className="flex flex-col gap-2">
                            <output className="flex min-h-touch items-center rounded-xl border-2 border-action px-4 text-3xl font-bold">
                                {problem.digits === '' ? <span className="text-text-secondary">Type the price again</span> : naira(digitsToKobo(problem.digits))}
                            </output>
                            <button
                                type="button"
                                disabled={cart.lines.find((l) => l.clientLineId === problem.lineId)?.unitPriceKobo !== digitsToKobo(problem.digits)}
                                className="min-h-touch rounded-xl bg-action text-lg font-bold text-white disabled:opacity-60"
                                onClick={() => {
                                    const next = markChecked(cart, problem.lineId, { confirmedOutsideRange: true, retyped: true });
                                    setCart(next);
                                    void finish(next);
                                }}
                            >
                                Confirm and finish
                            </button>
                        </div>
                    )}
                    {problem.kind === 'approval' && (
                        <ApprovalPad
                            people={people}
                            api={api}
                            action={problem.approval.action}
                            subjectId={problem.approval.subject_id}
                            itemId={problem.approval.item_id}
                            priceKobo={problem.approval.price_kobo}
                            onApproved={(token) => {
                                const next = markChecked(cart, problem.approval.subject_id, { approvalToken: token });
                                setCart(next);
                                void finish(next);
                            }}
                        />
                    )}
                </div>
            )}

            {(method === 'cash' || method === 'split' || problem?.kind === 'retype') && <Keypad onKey={typeKey} />}

            <button
                type="button"
                disabled={!ready || busy}
                className="min-h-[72px] rounded-xl bg-pay text-2xl font-bold text-white disabled:opacity-60"
                onClick={() => void finish(cart)}
            >
                {busy ? 'Finishing…' : 'Finish'}
            </button>
        </section>
    );
}
