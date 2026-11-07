import { useCallback, useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import type { CounterApi } from '@/counter/use-api';
import { clock, naira, whenInline } from './format';
import { styles } from './pickers';

/**
 * Prices (spec sections 3 and 7) [S4; wireframe FirstSalePrices]: the day's first sales, where one tap
 * makes a price the set price, and setting an item's retail and wholesale prices. The owner and managers
 * set prices. A price set reaches every counter at once and is kept in the price history; Undo puts back
 * the one before (or no set price), as a new entry.
 */
interface FirstSale {
    item_id: string;
    name: string;
    customer_type: 'retail' | 'wholesale';
    waiting_category: boolean;
    sales: { price_kobo: number; qty: number; cashier: string; at: string }[];
    prices: number[];
    set: { price_kobo: number; by: string | null; at: string; today: boolean } | null;
}

interface TypePrices {
    set_price: number | null;
    prefill: number | null;
    prefill_source: string | null;
    range: { low: number; high: number; source: string } | null;
    history: { price_kobo: number | null; at: string; by: string | null }[];
}

interface ItemPrices {
    item_id: string;
    name: string;
    prices: { retail: TypePrices; wholesale: TypePrices };
}

const { button, primary } = styles;
const typeName = { retail: 'Retail', wholesale: 'Wholesale' };

export function Prices({ api, onChanged }: { api: CounterApi; onChanged: () => void }) {
    const [tab, setTab] = useState<'first' | 'set'>('first');
    const [first, setFirst] = useState<FirstSale[] | null>(null);
    const [message, setMessage] = useState<{ tone: 'error' | 'ok'; text: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const [other, setOther] = useState<{ itemId: string; name: string; type: 'retail' | 'wholesale' } | null>(null);
    const [chosen, setChosen] = useState<string | null>(null);

    const load = useCallback(() => {
        void api
            .get<{ items: FirstSale[] }>('/api/back-office/prices/first-sales')
            .then((a) => {
                if (a.ok) setFirst(a.body.items);
            })
            .catch(() => {
                setMessage({ tone: 'error', text: 'The prices could not load. Check the connection.' });
            });
    }, [api]);

    useEffect(() => {
        load();
    }, [load]);

    const send = async (url: string, body: unknown, done: string) => {
        setBusy(true);
        setMessage(null);
        try {
            const a = await api.post<{ message?: string }>(url, body);
            if (a.ok) {
                setMessage({ tone: 'ok', text: done });
                setOther(null);
            } else setMessage({ tone: 'error', text: a.body.message ?? 'That did not save. Try again.' });
        } catch {
            setMessage({ tone: 'error', text: 'The device cannot reach the shop server. Try again.' });
        } finally {
            setBusy(false);
            load();
            onChanged();
        }
    };

    const setPrice = (itemId: string, name: string, type: 'retail' | 'wholesale', price: number) =>
        send('/api/back-office/prices', { item_id: itemId, customer_type: type, price_kobo: price }, `${name} now fills in at ${naira(price)} (${typeName[type].toLowerCase()}).`);
    const undo = (itemId: string, name: string, type: 'retail' | 'wholesale') =>
        send('/api/back-office/prices/undo', { item_id: itemId, customer_type: type }, `The ${typeName[type].toLowerCase()} set price of ${name} is undone.`);

    const waiting = (first ?? []).filter((f) => f.set === null).length;

    return (
        <div className="flex flex-col gap-5">
            <header className="flex flex-col gap-1">
                <h1 className="text-3xl font-bold">Prices</h1>
                <p className="text-lg text-text-secondary">The set price is what the counter fills in. The owner and managers set prices.</p>
            </header>

            {message !== null && (message.tone === 'error' ? <Notice tone="error">{message.text}</Notice> : <p role="status" className="rounded-xl bg-panel p-4 text-lg">{message.text}</p>)}

            <div role="tablist" aria-label="Prices" className="flex flex-wrap gap-2 border-b border-border">
                {(
                    [
                        ['first', `First sales today · ${String(waiting)}`],
                        ['set', 'Set prices'],
                    ] as const
                ).map(([id, label]) => (
                    <button
                        key={id}
                        type="button"
                        role="tab"
                        aria-selected={tab === id}
                        className={`min-h-touch px-4 text-lg ${tab === id ? 'font-bold text-action-text shadow-[inset_0_-3px_0_var(--color-action)]' : 'font-medium'}`}
                        onClick={() => {
                            setTab(id);
                        }}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {tab === 'first' && (
                <div role="tabpanel" aria-label="First sales today" className="flex flex-col gap-2">
                    <p className="text-lg">These items had no usual price to check against, so the counter took the price the cashier typed. Tap a price to make it the set price. The next sale fills it in, and the price checks start from it.</p>
                    {first === null && <p className="text-text-secondary">Loading…</p>}
                    {first !== null && first.length === 0 && <p className="text-text-secondary">No first sales today.</p>}
                    <ul className="flex flex-col">
                        {(first ?? []).map((f) => (
                            <li key={`${f.item_id}|${f.customer_type}`} className="flex flex-col gap-3 border-b border-border py-4 large:flex-row large:items-center">
                                <span className="flex min-w-0 flex-1 flex-col gap-1">
                                    <span className="text-lg font-semibold">{f.name}</span>
                                    <span className="text-text-secondary">
                                        {typeName[f.customer_type]} · {f.sales.map((s) => `${s.qty > 1 ? `${String(s.qty)} × ` : ''}${naira(s.price_kobo)} by ${s.cashier} ${clock(s.at)}`).join(', ')}
                                        {f.waiting_category && ' · a new product word, waiting in Review for a category'}
                                    </span>
                                    {f.set !== null && (
                                        <span>
                                            Set at <strong>{naira(f.set.price_kobo)}</strong> by {f.set.by ?? 'someone'} {whenInline(f.set.at)}
                                        </span>
                                    )}
                                </span>
                                <span className="flex flex-wrap gap-2">
                                    {f.set === null ? (
                                        <>
                                            {f.prices.slice(0, 3).map((p, i) => (
                                                <button key={p} type="button" disabled={busy} className={i === 0 ? primary : button} onClick={() => void setPrice(f.item_id, f.name, f.customer_type, p)}>
                                                    Set {naira(p)}
                                                </button>
                                            ))}
                                            <button type="button" className={button} onClick={() => { setOther({ itemId: f.item_id, name: f.name, type: f.customer_type }); }}>
                                                Other price
                                            </button>
                                        </>
                                    ) : (
                                        f.set.today && (
                                            <button type="button" disabled={busy} className={button} onClick={() => void undo(f.item_id, f.name, f.customer_type)}>
                                                Undo
                                            </button>
                                        )
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p className="text-text-secondary">Retail and wholesale prices are set separately. A price you set reaches every counter at once and is kept in the price history.</p>
                </div>
            )}

            {tab === 'set' && (
                <div role="tabpanel" aria-label="Set prices" className="flex flex-col gap-4">
                    <ItemSearch api={api} onPick={setChosen} />
                    {chosen !== null && <ItemPriceCard key={chosen} api={api} itemId={chosen} busy={busy} onSet={setPrice} onUndo={undo} />}
                </div>
            )}

            {other !== null && (
                <Sheet
                    title={`${typeName[other.type]} price for ${other.name}`}
                    onClose={() => {
                        setOther(null);
                    }}
                >
                    <PriceInput busy={busy} label="Set price" onSave={(p) => void setPrice(other.itemId, other.name, other.type, p)} />
                </Sheet>
            )}
        </div>
    );
}

/** Naira typed as whole digits, saved as kobo. */
function PriceInput({ busy, label, onSave }: { busy: boolean; label: string; onSave: (kobo: number) => void }) {
    const [digits, setDigits] = useState('');
    const kobo = digits === '' ? 0 : Number.parseInt(digits, 10) * 100;
    return (
        <div className="flex flex-wrap items-end gap-3">
            <label className="flex flex-col gap-2 text-lg font-semibold">
                {label}
                <span className="flex min-h-[64px] w-[230px] items-center gap-1 rounded-xl border-2 border-action px-4 text-3xl font-bold">
                    <span className="text-text-secondary">₦</span>
                    <input
                        inputMode="numeric"
                        aria-label={`${label}, in naira`}
                        value={digits}
                        className="w-full outline-none"
                        onChange={(e) => {
                            setDigits(e.target.value.replace(/\D/g, '').slice(0, 9));
                        }}
                    />
                </span>
            </label>
            <button
                type="button"
                disabled={busy || kobo <= 0}
                className={primary}
                onClick={() => {
                    onSave(kobo);
                    setDigits('');
                }}
            >
                Save {kobo > 0 ? naira(kobo) : ''}
            </button>
        </div>
    );
}

function ItemSearch({ api, onPick }: { api: CounterApi; onPick: (id: string) => void }) {
    const [q, setQ] = useState('');
    const [found, setFound] = useState<{ id: string; name: string; code: string; category: string }[]>([]);
    useEffect(() => {
        if (q.trim().length < 2) return;
        let live = true;
        const timer = window.setTimeout(() => {
            void api.get<{ id: string; name: string; code: string; category: string }[]>(`/api/back-office/catalogue/search?q=${encodeURIComponent(q)}`).then((a) => {
                if (live && a.ok) setFound(a.body);
            });
        }, 250);
        return () => {
            live = false;
            window.clearTimeout(timer);
        };
    }, [api, q]);
    const shown = q.trim().length < 2 ? [] : found;
    return (
        <div className="flex flex-col gap-2">
            <input
                type="search"
                aria-label="Find an item"
                placeholder="Find an item by name or code"
                value={q}
                className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                onChange={(e) => {
                    setQ(e.target.value);
                }}
            />
            {shown.length > 0 && (
                <ul className="flex max-h-80 flex-col overflow-y-auto rounded-xl border-2 border-border">
                    {shown.map((f) => (
                        <li key={f.id}>
                            <button
                                type="button"
                                className="flex min-h-touch w-full flex-col items-start justify-center border-b border-border px-3 py-2 text-left"
                                onClick={() => {
                                    onPick(f.id);
                                    setQ('');
                                }}
                            >
                                <span className="text-lg font-semibold">{f.name}</span>
                                <span className="text-text-secondary">
                                    {f.category} · {f.code}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function ItemPriceCard({ api, itemId, busy, onSet, onUndo }: {
    api: CounterApi;
    itemId: string;
    busy: boolean;
    onSet: (itemId: string, name: string, type: 'retail' | 'wholesale', price: number) => Promise<void>;
    onUndo: (itemId: string, name: string, type: 'retail' | 'wholesale') => Promise<void>;
}) {
    const [item, setItem] = useState<ItemPrices | null>(null);
    const load = useCallback(() => {
        void api.get<ItemPrices>(`/api/back-office/prices/items/${itemId}`).then((a) => {
            if (a.ok) setItem(a.body);
        });
    }, [api, itemId]);
    useEffect(() => {
        load();
    }, [load]);

    if (item === null) return <p className="text-text-secondary">Loading…</p>;
    return (
        <section aria-label={`Prices for ${item.name}`} className="flex flex-col gap-4 rounded-2xl border-2 border-border p-5">
            <h2 className="text-2xl font-bold">{item.name}</h2>
            <div className="grid gap-5 large:grid-cols-2">
                {(['retail', 'wholesale'] as const).map((type) => {
                    const p = item.prices[type];
                    const source = p.prefill_source === 'set_price' ? 'the set price' : p.prefill_source === 'median' ? 'the middle of recent sales' : 'the last price sold';
                    return (
                        <div key={type} className="flex flex-col gap-3 rounded-xl bg-panel p-4">
                            <h3 className="text-xl font-semibold">{typeName[type]}</h3>
                            <p className="text-lg">
                                Set price: <strong>{p.set_price === null ? 'none' : naira(p.set_price)}</strong>
                            </p>
                            <p className="text-text-secondary">
                                {p.prefill === null ? 'The counter fills in nothing yet.' : `The counter fills in ${naira(p.prefill)}, ${source}.`}
                                {p.range !== null && ` Usual range ${p.range.low === p.range.high ? naira(p.range.low) : `${naira(p.range.low)} to ${naira(p.range.high)}`}.`}
                            </p>
                            <PriceInput
                                busy={busy}
                                label="New set price"
                                onSave={(kobo) => {
                                    void onSet(item.item_id, item.name, type, kobo).then(load);
                                }}
                            />
                            {p.history.length > 0 && (
                                <>
                                    <ul className="flex flex-col gap-1 text-text-secondary">
                                        {p.history.map((h, i) => (
                                            <li key={i}>
                                                {h.price_kobo === null ? 'No set price' : naira(h.price_kobo)} · {h.by ?? 'someone'} {whenInline(h.at)}
                                            </li>
                                        ))}
                                    </ul>
                                    {p.set_price !== null && (
                                        <button
                                            type="button"
                                            disabled={busy}
                                            className={`${button} self-start`}
                                            onClick={() => {
                                                void onUndo(item.item_id, item.name, type).then(load);
                                            }}
                                        >
                                            Undo the last change
                                        </button>
                                    )}
                                </>
                            )}
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
