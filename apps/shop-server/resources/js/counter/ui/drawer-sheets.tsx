import { useCallback, useEffect, useState } from 'react';
import { Keypad, padKeyFrom } from '@/components/keypad';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import type { Tile } from '@/components/sign-in-panel';
import { digitsToKobo, naira, typeDigits } from '../money';
import type { CounterApi } from '../use-api';
import { ApprovalPad } from './approval-pad';
import { newId } from './types';

/*
 * The go-live basics at the counter (spec section 9; D68): Returns and voids, cash out of or into the drawer
 * beside a sale, and closing the day. Each asks for a manager's PIN on the same screen when the server says
 * so, and keeps its request ID until it is done, so a retry after a lost answer is never recorded twice.
 */

type Approval = { action: string; subject_id: string; item_id: string | null; price_kobo: number | null };
type Answer<T> = { message?: string; approval?: Approval } & T;

/** The keyboard as a fallback for the on-screen keypad (spec section 4), ignoring typing in a text field. */
function useKeypadKeys(onKey: (key: string) => void) {
    useEffect(() => {
        const listen = (event: KeyboardEvent) => {
            if (event.target instanceof HTMLInputElement && event.target.type === 'text') return;
            const key = padKeyFrom(event.key);
            if (key !== null) {
                event.preventDefault();
                onKey(key);
            }
        };
        window.addEventListener('keydown', listen);
        return () => {
            window.removeEventListener('keydown', listen);
        };
    }, [onKey]);
}

const choice = (on: boolean) => `min-h-touch rounded-xl border-2 px-4 ${on ? 'border-action font-semibold' : 'border-border'}`;

/** Sends a request; when a manager must approve, shows the pad, then sends it again with the token. */
function useApproved<T>(api: CounterApi) {
    const [pending, setPending] = useState<{ approval: Approval; message: string; url: string; body: Record<string, unknown>; onDone: (answer: Answer<T>) => void } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const send = useCallback(
        async (url: string, body: Record<string, unknown>, onDone: (answer: Answer<T>) => void) => {
            setBusy(true);
            setError(null);
            try {
                const a = await api.post<Answer<T>>(url, body, 30_000);
                if (a.ok) {
                    setPending(null);
                    onDone(a.body);
                } else if (a.body.approval !== undefined) {
                    setPending({ approval: a.body.approval, message: a.body.message ?? 'A manager must approve this.', url, body, onDone });
                } else {
                    setError(a.body.message ?? 'That did not work. Try again.');
                }
            } catch {
                setError('The server did not answer. Try again: it is never recorded twice.');
            } finally {
                setBusy(false);
            }
        },
        [api],
    );
    // The same request again, with the manager's token, so it binds to exactly what was approved.
    const resend = useCallback(
        (token: string) => {
            if (pending !== null) void send(pending.url, { ...pending.body, approval_token: token }, pending.onDone);
        },
        [pending, send],
    );
    const approval = pending === null ? null : { approval: pending.approval, message: pending.message, resend };
    return { send, approval, error, setError, busy };
}

function ManagerPad({ people, api, asked }: { people: Tile[]; api: CounterApi; asked: { approval: Approval; message: string; resend: (token: string) => void } }) {
    return (
        <div className="flex flex-col gap-3 rounded-xl border-2 border-warning-text p-3">
            <Notice tone="warning">{asked.message}</Notice>
            <ApprovalPad
                people={people}
                api={api}
                action={asked.approval.action}
                subjectId={asked.approval.subject_id}
                itemId={asked.approval.item_id}
                priceKobo={asked.approval.price_kobo}
                onApproved={asked.resend}
            />
        </div>
    );
}

interface FoundSale {
    id: string;
    receipt_number: string;
    recorded_at: string;
    cashier: string;
    total_kobo: number;
    voided: boolean;
    can_void: boolean;
    why_not_void: string | null;
    within_window: boolean;
    lines: { line_id: string; item_id: string; name: string; qty: number; returned: number; returnable: number; unit_price_kobo: number }[];
    payments: { method: string; amount_kobo: number }[];
}

interface Reversal {
    receipt_number: string;
    total_kobo: number;
    kind: string;
}

const reasons = [
    ['defective', 'Defective'],
    ['wrong_item', 'Wrong item'],
    ['changed_mind', 'Changed mind'],
] as const;
const dispositions = [
    ['restock', 'Back on the shelf'],
    ['defective_writeoff', 'Write it off'],
    ['supplier_claim', 'Back to the supplier'],
] as const;
const refunds = [
    ['cash', 'Cash'],
    ['transfer', 'Transfer'],
    ['pos', 'POS'],
] as const;

/**
 * Returns and voids (D68): find the sale by its receipt number or a scan of its barcode, then void it whole
 * (in its shift, on this drawer) or return some of it. With no receipt, only the owner takes goods back.
 */
export function ReturnsSheet({
    api,
    people,
    initialReceipt,
    findItems,
    onClose,
    onDone,
}: {
    api: CounterApi;
    people: Tile[];
    initialReceipt: string | null;
    findItems: (query: string) => Promise<{ id: string; name: string }[]>;
    onClose: () => void;
    onDone: (done: Reversal) => void;
}) {
    const [number, setNumber] = useState(initialReceipt ?? '');
    const [sale, setSale] = useState<FoundSale | null>(null);
    const [noReceipt, setNoReceipt] = useState(false);
    const [qty, setQty] = useState<Record<string, number>>({});
    const [loose, setLoose] = useState<{ id: string; name: string; qty: number; digits: string }[]>([]);
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<{ id: string; name: string }[]>([]);
    const [reason, setReason] = useState<string>('defective');
    const [disposition, setDisposition] = useState<string>('restock');
    const [refund, setRefund] = useState<string>('cash');
    const [reference, setReference] = useState('');
    const [voidReason, setVoidReason] = useState('');
    const [voiding, setVoiding] = useState(false);
    const [requestId, setRequestId] = useState(newId);
    const { send, approval, error, setError, busy } = useApproved<{ sale: Reversal }>(api);

    // The lookup returns what it found; showing it is separate, so the scan's lookup can run from an effect.
    const lookup = useCallback(
        async (receipt: string): Promise<{ sale?: FoundSale; error?: string }> => {
            try {
                const a = await api.get<{ sale?: FoundSale; message?: string }>(`/api/counter/receipts/${encodeURIComponent(receipt.trim().toUpperCase())}`);
                return a.ok && a.body.sale !== undefined ? { sale: a.body.sale } : { error: a.body.message ?? 'That receipt was not found.' };
            } catch {
                return { error: 'The counter cannot reach the server. Try again.' };
            }
        },
        [api],
    );
    const show = useCallback(
        (found: { sale?: FoundSale; error?: string }) => {
            if (found.sale === undefined) {
                setError(found.error ?? null);
                return;
            }
            setError(null);
            setSale(found.sale);
            setQty({});
            setRequestId(newId());
            setRefund(found.sale.payments[0]?.method ?? 'cash');
        },
        [setError],
    );
    const find = (receipt: string) => {
        void lookup(receipt).then(show);
    };

    useEffect(() => {
        if (initialReceipt !== null) void lookup(initialReceipt).then(show);
    }, [initialReceipt, lookup, show]);

    useEffect(() => {
        if (!noReceipt || query.trim().length < 2) return;
        let live = true;
        void findItems(query).then((items) => {
            if (live) setFound(items.slice(0, 6));
        });
        return () => {
            live = false;
        };
    }, [noReceipt, query, findItems]);

    const chosen = sale === null ? [] : sale.lines.filter((l) => (qty[l.line_id] ?? 0) > 0);
    const refundKobo = noReceipt
        ? loose.reduce((s, l) => s + l.qty * digitsToKobo(l.digits), 0)
        : chosen.reduce((s, l) => s + (qty[l.line_id] ?? 0) * l.unit_price_kobo, 0);
    const ready = refundKobo > 0 && (refund !== 'transfer' || reference.trim() !== '') && (!noReceipt || loose.every((l) => digitsToKobo(l.digits) > 0));
    const done = (a: { sale: Reversal }) => {
        onDone(a.sale);
    };

    const submit = () => {
        void send(
            '/api/counter/returns',
            {
                request_id: requestId,
                receipt_number: noReceipt ? null : sale?.receipt_number,
                lines: noReceipt
                    ? loose.map((l) => ({ item_id: l.id, qty: l.qty, unit_price_kobo: digitsToKobo(l.digits) }))
                    : chosen.map((l) => ({ line_id: l.line_id, qty: qty[l.line_id] ?? 0 })),
                reason,
                disposition,
                refund_method: refund,
                refund_reference: refund === 'transfer' ? reference.trim() : null,
            },
            done,
        );
    };

    return (
        <Sheet title="Returns and voids" onClose={onClose}>
            {sale === null && !noReceipt && (
                <div className="flex flex-col gap-3">
                    <label className="flex flex-col gap-1 text-lg">
                        Receipt number, or scan its barcode
                        <input
                            type="text"
                            value={number}
                            maxLength={40}
                            placeholder="T01-E1-000123"
                            className="min-h-touch rounded-xl border-2 border-border px-4 text-xl uppercase"
                            onChange={(e) => {
                                setNumber(e.target.value);
                            }}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && number.trim() !== '') find(number);
                            }}
                        />
                    </label>
                    <button type="button" disabled={number.trim() === ''} className="min-h-touch rounded-xl bg-action text-lg font-bold text-white disabled:opacity-60" onClick={() => {
                            find(number);
                        }}>
                        Find the sale
                    </button>
                    <button
                        type="button"
                        className="min-h-touch self-start rounded-xl border-2 border-border px-4"
                        onClick={() => {
                            setNoReceipt(true);
                            setRequestId(newId());
                        }}
                    >
                        No receipt (the owner only)
                    </button>
                </div>
            )}

            {sale !== null && (
                <div className="flex flex-col gap-3">
                    <p className="text-lg">
                        <strong>{sale.receipt_number}</strong> · {naira(sale.total_kobo)} · {sale.cashier}
                    </p>
                    {sale.voided && <Notice tone="warning">This sale was voided.</Notice>}
                    {!sale.within_window && <Notice tone="warning">This sale is older than the shop&apos;s return window.</Notice>}
                    {sale.lines.map((l) => (
                        <div key={l.line_id} className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-border p-3">
                            <div className="flex flex-1 flex-col">
                                <span className="text-lg font-semibold">{l.name}</span>
                                <span className="text-text-secondary">
                                    {l.qty} sold at {naira(l.unit_price_kobo)}
                                    {l.returned > 0 ? ` · ${String(l.returned)} returned` : ''}
                                </span>
                            </div>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    aria-label={`One fewer ${l.name} back`}
                                    className="min-h-touch min-w-touch rounded-xl border-2 border-border text-2xl"
                                    onClick={() => {
                                        setQty((q) => ({ ...q, [l.line_id]: Math.max(0, (q[l.line_id] ?? 0) - 1) }));
                                    }}
                                >
                                    −
                                </button>
                                <span className="w-10 text-center text-xl font-semibold" aria-label={`${l.name} coming back`}>
                                    {qty[l.line_id] ?? 0}
                                </span>
                                <button
                                    type="button"
                                    aria-label={`One more ${l.name} back`}
                                    disabled={(qty[l.line_id] ?? 0) >= l.returnable}
                                    className="min-h-touch min-w-touch rounded-xl border-2 border-border text-2xl disabled:opacity-40"
                                    onClick={() => {
                                        setQty((q) => ({ ...q, [l.line_id]: Math.min(l.returnable, (q[l.line_id] ?? 0) + 1) }));
                                    }}
                                >
                                    +
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {noReceipt && (
                <div className="flex flex-col gap-3">
                    <Notice tone="warning">Goods with no receipt come back only with the owner&apos;s PIN, and the owner sees it in Flags.</Notice>
                    <input
                        type="text"
                        aria-label="Find the item"
                        placeholder="Find the item"
                        value={query}
                        className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                        onChange={(e) => {
                            setQuery(e.target.value);
                        }}
                    />
                    <div className="flex flex-wrap gap-2">
                        {found.map((f) => (
                            <button
                                key={f.id}
                                type="button"
                                className="min-h-touch rounded-xl border-2 border-border px-3"
                                onClick={() => {
                                    setLoose((l) => (l.some((x) => x.id === f.id) ? l : [...l, { ...f, qty: 1, digits: '' }]));
                                    setQuery('');
                                    setFound([]);
                                }}
                            >
                                {f.name}
                            </button>
                        ))}
                    </div>
                    {loose.map((l, i) => (
                        <label key={l.id} className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-border p-3">
                            <span className="flex-1 text-lg font-semibold">{l.name}</span>
                            Price paid
                            <input
                                type="text"
                                inputMode="numeric"
                                aria-label={`Price paid for ${l.name}`}
                                value={l.digits}
                                className="min-h-touch w-32 rounded-xl border-2 border-border px-3 text-lg"
                                onChange={(e) => {
                                    const digits = e.target.value.replace(/\D/g, '').slice(0, 9);
                                    setLoose((all) => all.map((x, j) => (j === i ? { ...x, digits } : x)));
                                }}
                            />
                        </label>
                    ))}
                </div>
            )}

            {(chosen.length > 0 || loose.length > 0) && (
                <div className="flex flex-col gap-3">
                    <p className="font-semibold">Why did it come back?</p>
                    <div className="flex flex-wrap gap-2">
                        {reasons.map(([value, label]) => (
                            <button key={value} type="button" aria-pressed={reason === value} className={choice(reason === value)} onClick={() => { setReason(value); }}>
                                {label}
                            </button>
                        ))}
                    </div>
                    <p className="font-semibold">Where does it go?</p>
                    <div className="flex flex-wrap gap-2">
                        {dispositions.map(([value, label]) => (
                            <button key={value} type="button" aria-pressed={disposition === value} className={choice(disposition === value)} onClick={() => { setDisposition(value); }}>
                                {label}
                            </button>
                        ))}
                    </div>
                    <p className="font-semibold">Refund {naira(refundKobo)} by</p>
                    <div className="flex flex-wrap gap-2">
                        {refunds.map(([value, label]) => (
                            <button key={value} type="button" aria-pressed={refund === value} className={choice(refund === value)} onClick={() => { setRefund(value); }}>
                                {label}
                            </button>
                        ))}
                    </div>
                    {refund === 'transfer' && (
                        <input
                            type="text"
                            aria-label="Transfer reference"
                            placeholder="Transfer reference"
                            value={reference}
                            maxLength={60}
                            className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                            onChange={(e) => {
                                setReference(e.target.value);
                            }}
                        />
                    )}
                    <button type="button" disabled={!ready || busy} className="min-h-touch rounded-xl bg-action text-lg font-bold text-white disabled:opacity-60" onClick={submit}>
                        {busy ? 'Recording…' : `Return and refund ${naira(refundKobo)}`}
                    </button>
                </div>
            )}

            {sale !== null && chosen.length === 0 && (
                <div className="flex flex-col gap-2 border-t border-border pt-3">
                    {sale.can_void ? (
                        voiding ? (
                            <>
                                <input
                                    type="text"
                                    aria-label="Why it is voided"
                                    placeholder="Why it is voided"
                                    value={voidReason}
                                    maxLength={200}
                                    className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                                    onChange={(e) => {
                                        setVoidReason(e.target.value);
                                    }}
                                />
                                <button
                                    type="button"
                                    disabled={voidReason.trim().length < 3 || busy}
                                    className="min-h-touch rounded-xl bg-destructive text-lg font-bold text-white disabled:opacity-60"
                                    onClick={() => {
                                        void send('/api/counter/voids', { sale_id: sale.id, reason: voidReason.trim(), request_id: requestId }, done);
                                    }}
                                >
                                    Void sale {sale.receipt_number}
                                </button>
                            </>
                        ) : (
                            <button
                                type="button"
                                className="min-h-touch self-start rounded-xl border-2 border-destructive px-4 text-lg font-semibold text-destructive"
                                onClick={() => {
                                    setVoiding(true);
                                }}
                            >
                                Void the whole sale
                            </button>
                        )
                    ) : (
                        sale.why_not_void !== null && !sale.voided && <p className="text-text-secondary">{sale.why_not_void}</p>
                    )}
                </div>
            )}

            {approval !== null && <ManagerPad people={people} api={api} asked={approval} />}
            {error !== null && <Notice tone="error">{error}</Notice>}
        </Sheet>
    );
}

const cashTypes = [
    ['paid_out', 'Paid-out', 'A small expense from the till'],
    ['drop', 'Drop to the safe', 'Cash taken out to the safe'],
    ['top_up', 'Top-up', 'Change put into the drawer'],
] as const;

/** Cash out of or into the drawer beside a sale: a paid-out, a drop or a top-up, each with a reason (D68). */
export function CashSheet({ api, people, onClose, onDone }: { api: CounterApi; people: Tile[]; onClose: () => void; onDone: (message: string) => void }) {
    const [type, setType] = useState<string>('paid_out');
    const [digits, setDigits] = useState('');
    const [reason, setReason] = useState('');
    const [requestId] = useState(newId);
    const { send, approval, error, busy } = useApproved<{ drawer: string; amount_kobo: number }>(api);
    const amount = digitsToKobo(digits);
    const label = cashTypes.find(([value]) => value === type)?.[1] ?? '';
    const typeKey = useCallback((key: string) => {
        setDigits((d) => typeDigits(d, key));
    }, []);
    useKeypadKeys(typeKey);

    return (
        <Sheet title="Cash out or in" onClose={onClose}>
            <div className="grid grid-cols-3 gap-2">
                {cashTypes.map(([value, name]) => (
                    <button key={value} type="button" aria-pressed={type === value} className={choice(type === value)} onClick={() => { setType(value); }}>
                        {name}
                    </button>
                ))}
            </div>
            <p className="text-text-secondary">{cashTypes.find(([value]) => value === type)?.[2]}</p>
            <output aria-live="polite" className="flex min-h-touch items-center rounded-xl border-2 border-action px-4 text-4xl font-bold">
                {digits === '' ? <span className="text-text-secondary">₦</span> : naira(amount)}
            </output>
            <input
                type="text"
                aria-label="What it is for"
                placeholder="What it is for"
                value={reason}
                maxLength={200}
                className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                onChange={(e) => {
                    setReason(e.target.value);
                }}
            />
            {approval !== null && <ManagerPad people={people} api={api} asked={approval} />}
            {error !== null && <Notice tone="error">{error}</Notice>}
            <Keypad
                onKey={(key) => {
                    setDigits((d) => typeDigits(d, key));
                }}
            />
            <button
                type="button"
                disabled={amount <= 0 || reason.trim().length < 3 || busy}
                className="min-h-touch rounded-xl bg-action text-lg font-bold text-white disabled:opacity-60"
                onClick={() => {
                    void send('/api/counter/drawer/cash', { type, amount_kobo: amount, reason: reason.trim(), request_id: requestId }, (a) => {
                        const drawer = a.drawer === 'opened' ? ' The drawer opened.' : a.drawer === 'not_opened' ? ' The drawer did not open: use the key.' : '';
                        onDone(`${label} of ${naira(a.amount_kobo)} recorded.${drawer}`);
                    });
                }}
            >
                {busy ? 'Recording…' : `Record the ${label.toLowerCase()}`}
            </button>
        </Sheet>
    );
}

interface Closed {
    shift: {
        drawer: string;
        business_day: string;
        expected_cash_kobo: number;
        counted_cash_kobo: number | null;
        variance_kobo: number | null;
        flagged: boolean;
        sales: { count: number; total_kobo: number };
    };
    printed: boolean;
    print_error: string | null;
}

/**
 * Closing the day (D68): a blind count, so the drawer's holder types what is in it before seeing what was
 * expected; then the difference, and the Z-report prints.
 */
export function CloseDaySheet({ api, onClose }: { api: CounterApi; onClose: () => void }) {
    const [state, setState] = useState<{ open: { business_day: string } | null; last_closed: string | null } | null>(null);
    const [digits, setDigits] = useState('');
    const [closed, setClosed] = useState<Closed | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const typeKey = useCallback(
        (key: string) => {
            if (closed === null) setDigits((d) => typeDigits(d, key));
        },
        [closed],
    );
    useKeypadKeys(typeKey);

    useEffect(() => {
        void api
            .get<{ open: { business_day: string } | null; last_closed: string | null }>('/api/counter/close')
            .then((a) => {
                if (a.ok) setState(a.body);
            })
            .catch(() => {
                setError('The counter cannot reach the server. Try again.');
            });
    }, [api]);

    const post = async (url: string, body: Record<string, unknown>) => {
        setBusy(true);
        setError(null);
        try {
            const a = await api.post<Closed & { message?: string }>(url, body, 30_000);
            if (a.ok) setClosed(a.body);
            else setError(a.body.message ?? 'That did not work. Try again.');
        } catch {
            setError('The server did not answer. Check whether the day closed before trying again.');
        } finally {
            setBusy(false);
        }
    };

    const v = closed?.shift.variance_kobo ?? null;
    return (
        <Sheet title="Close the day" onClose={onClose}>
            {closed === null && state?.open !== null && state !== null && (
                <div className="flex flex-col gap-3">
                    <p className="text-lg">Count all the cash in the drawer, then type it. You see what was expected after.</p>
                    <output aria-live="polite" aria-label="Cash counted" className="flex min-h-touch items-center rounded-xl border-2 border-action px-4 text-4xl font-bold">
                        {digits === '' ? <span className="text-text-secondary">₦</span> : naira(digitsToKobo(digits))}
                    </output>
                    <Keypad
                        onKey={(key) => {
                            setDigits((d) => typeDigits(d, key));
                        }}
                    />
                    <button
                        type="button"
                        disabled={digits === '' || busy}
                        className="min-h-touch rounded-xl bg-action text-lg font-bold text-white disabled:opacity-60"
                        onClick={() => void post('/api/counter/close', { counted_kobo: digitsToKobo(digits) })}
                    >
                        {busy ? 'Closing…' : 'Close the day'}
                    </button>
                </div>
            )}
            {closed === null && state !== null && state.open === null && (
                <div className="flex flex-col gap-3">
                    <p className="text-lg">There is no open shift on this counter. The next sale opens one.</p>
                    {state.last_closed !== null && (
                        <button type="button" disabled={busy} className="min-h-touch rounded-xl border-2 border-border text-lg" onClick={() => void post('/api/counter/close/print', {})}>
                            Print the last Z-report again
                        </button>
                    )}
                </div>
            )}
            {closed !== null && (
                <div className="flex flex-col gap-2 text-lg">
                    <p className="flex justify-between">
                        <span>Expected</span>
                        <strong>{naira(closed.shift.expected_cash_kobo)}</strong>
                    </p>
                    <p className="flex justify-between">
                        <span>Counted</span>
                        <strong>{naira(closed.shift.counted_cash_kobo ?? 0)}</strong>
                    </p>
                    <p className="flex justify-between">
                        <span>{v === 0 ? 'The drawer is right' : v !== null && v > 0 ? 'Over by' : 'Short by'}</span>
                        <strong>{v === 0 || v === null ? '' : naira(Math.abs(v))}</strong>
                    </p>
                    {closed.shift.flagged && <Notice tone="warning">The difference is more than the shop allows, so the owner sees it in Flags.</Notice>}
                    {closed.printed ? (
                        <p className="text-text-secondary">The Z-report is printing.</p>
                    ) : (
                        <>
                            <Notice tone="error">{closed.print_error ?? 'The Z-report did not print.'}</Notice>
                            <button type="button" disabled={busy} className="min-h-touch rounded-xl border-2 border-border text-lg" onClick={() => void post('/api/counter/close/print', {})}>
                                Print the Z-report again
                            </button>
                        </>
                    )}
                </div>
            )}
            {error !== null && <Notice tone="error">{error}</Notice>}
        </Sheet>
    );
}
