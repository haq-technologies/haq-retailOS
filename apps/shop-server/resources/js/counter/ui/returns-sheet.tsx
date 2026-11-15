import { useCallback, useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import type { Tile } from '@/components/sign-in-panel';
import { digitsToKobo, naira } from '../money';
import type { CounterApi } from '../use-api';
import { ManagerPad, choice, useApproved } from './drawer-shared';
import { newId } from './types';

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

    // A scanned receipt, when the sheet opens or while it is open: the field shows the whole number, not the
    // first keys of the scan that reached it as typing.
    useEffect(() => {
        if (initialReceipt === null) return;
        void lookup(initialReceipt).then((found) => {
            setNumber(initialReceipt);
            show(found);
        });
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
