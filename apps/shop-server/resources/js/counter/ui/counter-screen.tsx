import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { DotIcon, PrinterIcon, WarningIcon } from '@/components/icons';
import { Notice } from '@/components/notice';
import type { SignedIn, Tile as Person } from '@/components/sign-in-panel';
import { addLine, emptyCart, removeLine, restoreLine, setColour, setCustomerType, setPrice, setQty, totalKobo, type AddedBy, type Cart, type CartLine, type Payment } from '../cart';
import type { Catalogue } from '../catalogue';
import { addToJournal } from '../db';
import { drill, type DrillState, type Tile } from '../drill';
import { naira } from '../money';
import { isReceiptNumber, ScanDetector } from '../scanner';
import type { SearchClient } from '../search-client';
import type { CatalogueSync } from '../sync';
import type { CounterSettings, CustomerType, ItemPrices, ItemFacetRow, ItemRow, PriceRange } from '../types';
import { useCounterApi } from '../use-api';
import { AfterPay, PaidSummary } from './after-pay';
import { CartPanel } from './cart-panel';
import { NewItem } from './new-item';
import { Pay } from './pay';
import { PricePad, type PriceChoice } from './price-pad';
import { HoldSheet, NoSaleSheet, PickerSheet, ShiftSheet } from './small-sheets';
import { newId, type ParkedEntry, type SaleSummary } from './types';
import { WaitingSales } from './waiting';

/**
 * The counter (spec sections 6, 7 and 15; wireframes CounterLarge, CounterTablet and CounterPhone). Every
 * tap runs on the local copy of the catalogue and the local cart; only Finish, item creation and the
 * parked sales call the server [F25].
 */
export interface FullSettings extends CounterSettings {
    cashierItemCreation: 'on' | 'request_only';
}

interface Props {
    session: SignedIn;
    setToken: (token: string) => void;
    terminal: { code: string; name: string };
    people: Person[];
    settings: FullSettings;
    cat: Catalogue;
    catVersion: number;
    online: boolean;
    search: SearchClient | null;
    sync: CatalogueSync | null;
    cart: Cart;
    update: (fn: (cart: Cart) => Cart) => void;
    onLock: () => void;
}

interface AddContext {
    addedBy: AddedBy;
    barcode?: string | null;
    searchTerm?: string | null;
}

type SheetState =
    | { kind: 'price'; itemId: string; name: string; crumbs: string[]; lineId: string; prefill: number | null; source: 'set_price' | 'last_price' | 'median' | null; range: PriceRange | null; ctx: AddContext; editing: boolean }
    | { kind: 'waiting' }
    | { kind: 'new-item' }
    | { kind: 'picker'; code: string; options: { id: string; name: string }[] }
    | { kind: 'hold' }
    | { kind: 'no-sale' }
    | { kind: 'shift' }
    | { kind: 'cart' };

const initials = (name: string) =>
    name
        .split(/\s+/)
        .map((w) => w.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase();

function junk(code: string, linked: number): boolean {
    return code.length < 6 || /^(.)\1+$/u.test(code) || linked > 5;
}

export function CounterScreen({ session, setToken, terminal, people, settings, cat, catVersion, online, search, sync, cart, update, onLock }: Props) {
    const api = useCounterApi(session.token, setToken);
    const [view, setView] = useState<'sale' | 'pay' | 'after'>('sale');
    const [onlyTransfer, setOnlyTransfer] = useState(false);
    const [afterSale, setAfterSale] = useState<SaleSummary | null>(null);
    const [state, setState] = useState<DrillState>({ categoryId: null, choices: [] });
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<string[]>([]);
    const [learnCode, setLearnCode] = useState<string | null>(null);
    const [sheet, setSheet] = useState<SheetState | null>(null);
    const [sheetError, setSheetError] = useState<string | null>(null);
    const [notice, setNotice] = useState<{ tone: 'error' | 'warning' | 'info'; text: string } | null>(null);
    const [undo, setUndo] = useState<{ line: CartLine; index: number } | null>(null);
    const [colourFor, setColourFor] = useState<string | null>(null);
    const [printer, setPrinter] = useState('ready');
    const [pending, setPending] = useState<{ id: string; receipt_number: string }[]>([]);
    const [parked, setParked] = useState<ParkedEntry[]>([]);
    const searchRef = useRef<HTMLInputElement>(null);
    const detector = useRef(new ScanDetector());
    const busy = useRef(false);

    // The catalogue changes under us as the feed polls; catVersion tells React to read it again.
    const view_ = useMemo(() => drill(cat, state, cart.customerType), [cat, state, cart.customerType, catVersion]); // eslint-disable-line react-hooks/exhaustive-deps

    const refreshParked = useCallback(() => {
        void api
            .get<{ parked: ParkedEntry[] }>('/api/counter/parked')
            .then((a) => {
                if (a.ok) setParked(a.body.parked);
            })
            .catch(() => undefined);
    }, [api]);

    // Background checks: waiting sales every 3 s, receipts that did not print every 5 s, the printer every 15 s.
    useEffect(() => {
        let tick = 0;
        const run = () => {
            refreshParked();
            if (tick % 5 === 0) {
                void api
                    .get<{ print_jobs: { id: string; receipt_number: string; kind: string }[] }>('/api/counter/print-jobs')
                    .then((a) => {
                        if (a.ok) setPending(a.body.print_jobs);
                    })
                    .catch(() => undefined);
            }
            if (tick % 15 === 0) {
                void api
                    .get<{ status: string }>('/api/counter/printer')
                    .then((a) => {
                        if (a.ok) setPrinter(a.body.status);
                    })
                    .catch(() => {
                        setPrinter('unknown');
                    });
            }
            tick += 3;
        };
        run();
        const timer = window.setInterval(run, 3000);
        return () => {
            window.clearInterval(timer);
        };
    }, [api, refreshParked]);

    // A shift opens with its counted float; skipping it never blocks a sale [F20].
    useEffect(() => {
        void api
            .get<{ shift: unknown }>('/api/counter/shift')
            .then((a) => {
                if (a.ok && a.body.shift === null) setSheet((s) => s ?? { kind: 'shift' });
            })
            .catch(() => undefined);
    }, [api]);

    // Search runs in the worker; the newest answer wins.
    useEffect(() => {
        if (search === null || query.trim() === '') return;
        let current = true;
        void search.query(query).then((ids) => {
            if (current) setResults(ids);
        });
        return () => {
            current = false;
        };
    }, [query, search, catVersion]);

    const say = useCallback((tone: 'error' | 'warning' | 'info', text: string) => {
        setNotice({ tone, text });
    }, []);

    const priceInfo = useCallback(
        async (itemId: string): Promise<ItemPrices | undefined> => {
            const known = cat.prices.get(itemId);
            if (known !== undefined) return known;
            try {
                const a = await api.post<{ prices: ItemPrices[] }>('/api/counter/prices', { item_ids: [itemId] }, 1500);
                if (a.ok) {
                    cat.setPrices(a.body.prices);
                    return a.body.prices[0];
                }
            } catch {
                // Offline: the pad opens empty, and the server checks the price at Finish.
            }
            return undefined;
        },
        [cat, api],
    );

    const putLine = useCallback(
        (itemId: string, priceKobo: number, source: CartLine['priceSource'], ctx: AddContext, checks: Partial<PriceChoice>, lineId?: string) => {
            const item = cat.items.get(itemId);
            if (item === undefined) return;
            const asksColour = cat.asksColour(item.category_id);
            const id = lineId ?? newId();
            const result = addLine(
                cart,
                {
                    itemId,
                    name: item.display_name,
                    categoryId: item.category_id,
                    unitPriceKobo: priceKobo,
                    colour: null,
                    asksColour,
                    addedBy: ctx.addedBy,
                    priceSource: source,
                    barcode: ctx.barcode ?? null,
                    searchTerm: ctx.searchTerm ?? null,
                    confirmedOutsideRange: checks.confirmedOutsideRange ?? false,
                    retyped: checks.retyped ?? false,
                    approvalToken: checks.approvalToken ?? null,
                },
                () => id,
                () => new Date().toISOString(),
            );
            update(() => result.cart);
            const added = result.clientLineId;
            if (asksColour) setColourFor(added);
            setQuery('');
            setResults([]);
            setState({ categoryId: null, choices: [] });
            setLearnCode(null);
        },
        [cat, cart, update],
    );

    const addItem = useCallback(
        async (id: string, ctx: AddContext, crumbs: string[] = []) => {
            if (busy.current) return;
            busy.current = true;
            try {
                let itemId = id;
                if (id.startsWith('shape:')) {
                    const shape = cat.shapes.find((s) => s.id === id);
                    if (shape === undefined) return;
                    try {
                        const a = await api.post<{ item: ItemRow; facets: ItemFacetRow[]; prices: ItemPrices[] }>('/api/counter/shapes', { category_id: shape.category_id, facets: shape.facets });
                        if (!a.ok) {
                            say('error', 'That item could not be added. Try again.');
                            return;
                        }
                        sync?.absorb({ items: [a.body.item], item_facets: a.body.facets }, a.body.prices);
                        cat.apply({ items: [a.body.item], item_facets: a.body.facets });
                        itemId = a.body.item.id;
                    } catch {
                        say('warning', 'This item is sold for the first time and needs the server. Reconnecting; try again in a moment.');
                        return;
                    }
                }
                const item = cat.items.get(itemId);
                if (item === undefined) return;
                if (ctx.addedBy === 'learn' && ctx.barcode != null) {
                    void api.post('/api/counter/barcodes', { code: ctx.barcode, item_id: itemId }).catch(() => undefined);
                }
                const info = (await priceInfo(itemId))?.[cart.customerType];
                if (info?.set_price != null) {
                    putLine(itemId, info.set_price, 'set_price', ctx, {});
                    return;
                }
                setSheet({
                    kind: 'price',
                    itemId,
                    name: item.display_name,
                    crumbs,
                    lineId: newId(),
                    prefill: info?.prefill ?? null,
                    source: info?.prefill_source ?? null,
                    range: info?.range ?? null,
                    ctx,
                    editing: false,
                });
            } finally {
                busy.current = false;
            }
        },
        [cat, api, sync, say, priceInfo, cart.customerType, putLine],
    );

    const handleScan = useCallback(
        (code: string) => {
            if (view === 'after') setView('sale');
            if (sheet !== null || view === 'pay') {
                say('warning', 'Finish what is open first, then scan.');
                return;
            }
            if (isReceiptNumber(code)) {
                say('info', `Receipt ${code}. Reprints are on the screen after Pay; returns come with the next release.`);
                return;
            }
            const all = [...cat.barcodes.values()].filter((b) => b.code === code && b.status !== 'disabled');
            const candidates = cat.barcodeCandidates(code);
            if (candidates.length === 1 && candidates[0] !== undefined) {
                void addItem(candidates[0].item_id, { addedBy: 'scan', barcode: code });
            } else if (candidates.length > 1) {
                setSheet({ kind: 'picker', code, options: candidates.map((b) => ({ id: b.item_id, name: cat.items.get(b.item_id)?.display_name ?? '' })) });
            } else if (junk(code, all.length)) {
                setQuery(code);
                say('info', `Code ${code} is not one the counter learns. Find the item by search or the tiles.`);
            } else {
                setLearnCode(code);
                setQuery('');
                searchRef.current?.focus();
            }
        },
        [view, sheet, cat, addItem, say],
    );

    const pay = useCallback(() => {
        if (cart.lines.length === 0 || sheet !== null) return;
        setOnlyTransfer(false);
        setView('pay');
    }, [cart.lines.length, sheet]);

    // The scanner, wherever focus is, and the counter's keys: F8 Pay, F4 Hold, F2 New item. Never bare Enter.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const scan = detector.current.key(event.key, event.timeStamp);
            if (scan.kind === 'burst') {
                event.preventDefault();
                event.stopImmediatePropagation();
                if (scan.first && document.activeElement === searchRef.current) setQuery((q) => (q.endsWith(scan.leaked) ? q.slice(0, -scan.leaked.length) : q));
                return;
            }
            if (scan.kind === 'scan') {
                event.preventDefault();
                event.stopImmediatePropagation();
                handleScan(scan.code);
                return;
            }
            if (view !== 'sale' || sheet !== null) return;
            if (event.key === 'F8') {
                event.preventDefault();
                pay();
            } else if (event.key === 'F4') {
                event.preventDefault();
                if (cart.lines.length > 0) setSheet({ kind: 'hold' });
            } else if (event.key === 'F2') {
                event.preventDefault();
                setSheet({ kind: 'new-item' });
            }
        };
        window.addEventListener('keydown', onKey, { capture: true });
        return () => {
            window.removeEventListener('keydown', onKey, { capture: true });
        };
    }, [view, sheet, cart.lines.length, handleScan, pay]);

    useEffect(() => {
        if (undo === null) return;
        const timer = window.setTimeout(() => {
            setUndo(null);
        }, 8000);
        return () => {
            window.clearTimeout(timer);
        };
    }, [undo]);

    const tapTile = (tile: Tile) => {
        if (tile.kind === 'category') setState({ categoryId: tile.id, choices: [] });
        else if (tile.kind === 'value' && tile.itemId !== null) void addItem(tile.itemId, { addedBy: learnCode === null ? 'tile' : 'learn', barcode: learnCode }, [...view_.crumbs, tile.label]);
        else if (tile.kind === 'value') setState((s) => ({ ...s, choices: [...s.choices, { key: tile.key, value: tile.value }] }));
        else if (tile.kind === 'skip') setState((s) => ({ ...s, choices: [...s.choices, { key: tile.key, value: null }] }));
        else void addItem(tile.id, { addedBy: learnCode === null ? 'tile' : 'learn', barcode: learnCode }, view_.crumbs);
    };

    const back = () => {
        setState((s) => {
            if (s.choices.length > 0) return { ...s, choices: s.choices.slice(0, -1) };
            const parent = s.categoryId === null ? null : (cat.categories.get(s.categoryId)?.parent_id ?? null);
            return { categoryId: parent, choices: [] };
        });
    };

    const changeQty = (lineId: string, qty: number) => {
        if (qty <= 0) {
            const { cart: next, removed } = removeLine(cart, lineId);
            update(() => next);
            setUndo(removed);
        } else {
            update((c) => setQty(c, lineId, qty));
        }
    };

    const flipType = (type: CustomerType) => {
        update((c) =>
            setCustomerType(c, type, (itemId, t) => {
                const p = cat.priceFor(itemId, t);
                if (p.setPrice !== null) return { price: p.setPrice, source: 'set_price' };
                if (p.prefill !== null && p.source !== null) return { price: p.prefill, source: p.source };
                return null;
            }),
        );
    };

    const hold = async (label: string) => {
        setSheetError(null);
        try {
            const a = await api.post<{ id?: string; message?: string }>('/api/counter/parked', {
                kind: 'hold',
                label: label.trim() === '' ? null : label.trim(),
                payload: { cart: { ...cart, finishKey: null, parkedSaleId: null } },
                amount_kobo: totalKobo(cart),
            });
            if (!a.ok) {
                setSheetError(a.body.message ?? 'That did not hold. Try again.');
                return;
            }
            update((c) => emptyCart(c.customerType));
            setSheet(null);
            refreshParked();
            say('info', 'Held. Every counter in this branch can resume it.');
        } catch {
            setSheetError('The counter cannot reach the server, so the sale stays here. Try again.');
        }
    };

    const awaiting = async (payment: Payment, label: string): Promise<string | null> => {
        try {
            const a = await api.post<{ message?: string }>('/api/counter/parked', {
                kind: 'awaiting_transfer',
                label: label.trim() === '' ? null : label.trim(),
                payload: { cart: { ...cart, finishKey: null, parkedSaleId: null }, payment },
                amount_kobo: totalKobo(cart),
            });
            if (!a.ok) return a.body.message ?? 'That did not park. Try again.';
            update((c) => emptyCart(c.customerType));
            setView('sale');
            refreshParked();
            say('info', 'Waiting for the transfer. It shows in Pending transfers on every counter.');
            return null;
        } catch {
            return 'The counter cannot reach the server. Try again.';
        }
    };

    const resume = async (entry: ParkedEntry) => {
        try {
            const a = await api.post<{ payload?: { cart?: Cart }; message?: string }>(`/api/counter/parked/${entry.id}/resume`, {});
            const resumed = a.body.payload?.cart;
            if (!a.ok || resumed === undefined) {
                say('error', a.body.message ?? 'That sale could not be resumed.');
                refreshParked();
                return;
            }
            update(() => ({ ...resumed, parkedSaleId: entry.id, finishKey: null }));
            setSheet(null);
            refreshParked();
            if (entry.kind === 'awaiting_transfer') {
                setOnlyTransfer(true);
                setView('pay');
            }
        } catch {
            say('warning', 'The counter cannot reach the server. Try again.');
        }
    };

    const finished = (sale: SaleSummary) => {
        void addToJournal({
            receipt_number: sale.receipt_number,
            at: sale.recorded_at,
            total_kobo: sale.total_kobo,
            lines: sale.lines.map((l) => ({ name: l.name, qty: l.qty, unit_price_kobo: l.unit_price_kobo })),
            payments: sale.payments.map((p) => ({ method: p.method, amount_kobo: p.amount_kobo })),
        }).catch(() => undefined);
        update((c) => emptyCart(c.customerType));
        setAfterSale(sale);
        setView('after');
        setColourFor(null);
        refreshParked();
    };

    const awaitingCount = parked.filter((p) => p.kind === 'awaiting_transfer').length;
    const flaggedCount = parked.filter((p) => p.flagged).length;
    const heldCount = parked.filter((p) => p.kind === 'hold').length;
    const searching = query.trim() !== '';
    const resultTiles: Tile[] = results
        .map((id): Tile | null => {
            const item = cat.items.get(id);
            if (item !== undefined) {
                const p = cat.priceFor(id, cart.customerType);
                return { kind: 'item', id, label: item.display_name, priceKobo: p.setPrice ?? p.prefill, shape: null };
            }
            const shape = cat.shapes.find((s) => s.id === id);
            return shape === undefined ? null : { kind: 'item', id, label: shape.display_name, priceKobo: null, shape };
        })
        .filter((t): t is Tile => t !== null);
    const tiles = searching ? resultTiles : view_.tiles;

    const printerLabel = printer === 'ready' ? 'Printer ready' : printer === 'not_set' ? 'No printer set' : printer === 'offline' ? 'Printer off' : 'Printer: check it';

    const header = (
        <header className="flex min-h-[72px] flex-wrap items-center gap-3 border-b border-border px-4">
            <span className="hidden text-lg font-bold large:inline">HAQ Retail OS</span>
            <button type="button" className="flex min-h-touch items-center gap-2 rounded-full border-2 border-border px-2 pr-4" onClick={onLock} title="Switch person">
                <span className="flex h-10 w-10 items-center justify-center rounded-full bg-action font-bold text-white">{initials(session.user.name)}</span>
                <span className="text-lg font-semibold">{session.user.name}</span>
            </button>
            <div role="radiogroup" aria-label="Customer type" className="flex rounded-xl border-2 border-border p-1">
                {(['retail', 'wholesale'] as const).map((t) => (
                    <button
                        key={t}
                        type="button"
                        role="radio"
                        aria-checked={cart.customerType === t}
                        className={`min-h-12 rounded-lg px-4 text-lg font-semibold ${cart.customerType === t ? 'bg-action text-white' : ''}`}
                        onClick={() => {
                            flipType(t);
                        }}
                    >
                        {t === 'retail' ? 'Retail' : 'Wholesale'}
                    </button>
                ))}
            </div>
            {awaitingCount > 0 && (
                <button
                    type="button"
                    className="flex min-h-12 items-center gap-2 rounded-full bg-warning px-4 font-semibold text-text"
                    onClick={() => {
                        setSheet({ kind: 'waiting' });
                    }}
                >
                    <WarningIcon className="h-5 w-5" /> {awaitingCount} awaiting transfer{flaggedCount > 0 ? `, ${String(flaggedCount)} over 30 min` : ''}
                </button>
            )}
            <span className="ml-auto flex items-center gap-2 text-lg">
                {online ? (
                    <>
                        <DotIcon className="h-3 w-3 text-pay" /> Online
                    </>
                ) : (
                    <span className="flex items-center gap-2 font-semibold text-warning-text">
                        <WarningIcon className="h-5 w-5" /> Reconnecting
                    </span>
                )}
            </span>
            <span className={`flex items-center gap-2 text-lg ${printer === 'ready' ? '' : 'font-semibold text-warning-text'}`}>
                {printer === 'ready' ? <PrinterIcon className="h-5 w-5" /> : <WarningIcon className="h-5 w-5" />} {printerLabel}
            </span>
        </header>
    );

    const panel = (
        <CartPanel
            cart={cart}
            colours={settings.colours}
            colourFor={colourFor}
            disabled={view !== 'sale'}
            onQty={changeQty}
            onColour={(lineId, colour) => {
                update((c) => setColour(c, lineId, colour));
                setColourFor(null);
            }}
            onEditPrice={(lineId) => {
                const line = cart.lines.find((l) => l.clientLineId === lineId);
                if (line === undefined) return;
                const info = cat.prices.get(line.itemId)?.[cart.customerType];
                setSheet({
                    kind: 'price',
                    itemId: line.itemId,
                    name: line.name,
                    crumbs: [],
                    lineId,
                    prefill: line.unitPriceKobo,
                    source: line.priceSource === 'typed' ? null : line.priceSource,
                    range: info?.range ?? null,
                    ctx: { addedBy: line.addedBy },
                    editing: true,
                });
            }}
            onHold={() => {
                setSheet({ kind: 'hold' });
            }}
            onPay={pay}
            undo={undo === null ? null : { name: undo.line.name }}
            onUndo={() => {
                if (undo !== null) update((c) => restoreLine(c, undo));
                setUndo(null);
            }}
        />
    );

    return (
        <div className="flex h-screen flex-col overflow-hidden">
            {header}
            <div className="grid min-h-0 flex-1 grid-cols-1 medium:grid-cols-[1fr_360px] large:grid-cols-[1fr_440px]">
                <main className="flex min-h-0 flex-col gap-4 overflow-y-auto p-4 pb-28 medium:pb-4">
                    {notice !== null &&
                        (notice.tone === 'info' ? (
                            <p role="status" className="flex items-center justify-between gap-3 rounded-xl bg-panel px-4 py-3 text-lg">
                                {notice.text}
                                <button
                                    type="button"
                                    className="min-h-touch rounded-xl border-2 border-border px-3"
                                    onClick={() => {
                                        setNotice(null);
                                    }}
                                >
                                    OK
                                </button>
                            </p>
                        ) : (
                            <Notice tone={notice.tone}>{notice.text}</Notice>
                        ))}

                    {view === 'pay' && (
                        <Pay
                            cart={cart}
                            setCart={(next) => {
                                update(() => next);
                            }}
                            settings={settings}
                            people={people}
                            api={api}
                            onlyTransfer={onlyTransfer}
                            onFinished={finished}
                            onAwaiting={awaiting}
                            onBack={() => {
                                setView('sale');
                            }}
                        />
                    )}

                    {view === 'after' && afterSale !== null && (
                        <AfterPay
                            sale={afterSale}
                            api={api}
                            onNext={() => {
                                setView('sale');
                                setNotice(null);
                                searchRef.current?.focus();
                            }}
                        />
                    )}

                    {view === 'sale' && (
                        <>
                            {pending.slice(0, 1).map((job) => (
                                <div key={job.id} role="alert" className="flex flex-col gap-2 rounded-xl border-2 border-warning bg-warning p-4 text-text">
                                    <p className="flex items-center gap-2 text-xl font-semibold">
                                        <WarningIcon /> Receipt {job.receipt_number} did not print
                                    </p>
                                    <p>The sale is safe; only its receipt is missing.</p>
                                    <button
                                        type="button"
                                        className="min-h-touch self-start rounded-xl border-2 border-text bg-background px-4 text-lg font-semibold"
                                        onClick={() => {
                                            void api.post(`/api/counter/print-jobs/${job.id}/retry`, {}).then(() => {
                                                setPending((list) => list.filter((j) => j.id !== job.id));
                                            });
                                        }}
                                    >
                                        Print pending receipt {job.receipt_number}
                                    </button>
                                    <p className="text-sm">This stays here until the receipt prints.</p>
                                </div>
                            ))}

                            {learnCode !== null && (
                                <div role="status" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border-2 border-action p-4 text-lg">
                                    <span>
                                        New code <strong>{learnCode}</strong>. Find its item by search or the tiles; the counter remembers it.
                                    </span>
                                    <button
                                        type="button"
                                        className="min-h-touch rounded-xl border-2 border-border px-4"
                                        onClick={() => {
                                            setLearnCode(null);
                                        }}
                                    >
                                        Cancel
                                    </button>
                                </div>
                            )}

                            <label className="flex flex-col gap-1">
                                <span className="sr-only">Search or scan</span>
                                <input
                                    ref={searchRef}
                                    type="search"
                                    value={query}
                                    placeholder="Search or scan"
                                    autoComplete="off"
                                    className="min-h-touch rounded-xl border-2 border-border px-4 text-xl"
                                    onChange={(e) => {
                                        setQuery(e.target.value);
                                        if (e.target.value.trim() === '') setResults([]);
                                    }}
                                    onKeyDown={(e) => {
                                        const first = resultTiles[0];
                                        if (e.key === 'Enter' && first !== undefined && first.kind === 'item') {
                                            e.preventDefault();
                                            void addItem(first.id, { addedBy: learnCode === null ? 'search' : 'learn', barcode: learnCode, searchTerm: query });
                                        } else if (e.key === 'Escape') {
                                            setQuery('');
                                            setResults([]);
                                        }
                                    }}
                                />
                                <span className="text-sm text-text-secondary">Scanner ready</span>
                            </label>

                            {!searching && (
                                <nav aria-label="Where you are" className="flex flex-wrap items-center gap-2 text-lg">
                                    {(state.categoryId !== null || state.choices.length > 0) && (
                                        <button type="button" className="min-h-touch rounded-xl border-2 border-border px-4" onClick={back}>
                                            Back
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        className="min-h-touch px-2 text-action-text underline"
                                        onClick={() => {
                                            setState({ categoryId: null, choices: [] });
                                        }}
                                    >
                                        All
                                    </button>
                                    {view_.crumbs.map((c, i) => (
                                        <span key={`${c}-${String(i)}`} className="text-text-secondary">
                                            › {c}
                                        </span>
                                    ))}
                                </nav>
                            )}
                            <p className="text-xl font-semibold">{searching ? (tiles.length === 0 ? 'Nothing matches. Try fewer letters, or New item.' : 'Results') : view_.prompt}</p>

                            <div className="grid grid-cols-2 gap-3 medium:grid-cols-3 large:grid-cols-4">
                                {tiles.map((tile) => (
                                    <button
                                        key={tile.kind === 'value' ? `${tile.key}:${tile.value}` : tile.kind === 'skip' ? `skip:${tile.key}` : tile.id}
                                        type="button"
                                        className={`flex min-h-[104px] flex-col items-start justify-between rounded-xl border-2 p-3 text-left ${tile.kind === 'skip' ? 'border-dashed border-border' : 'border-border'}`}
                                        onClick={() => {
                                            if (searching && tile.kind === 'item') {
                                                void addItem(tile.id, { addedBy: learnCode === null ? 'search' : 'learn', barcode: learnCode, searchTerm: query });
                                            } else {
                                                tapTile(tile);
                                            }
                                        }}
                                    >
                                        <span className="text-lg font-semibold">{tile.label}</span>
                                        {tile.kind === 'skip' && <span className="text-text-secondary">{tile.hint}</span>}
                                        {tile.kind === 'item' && <span className="text-lg text-text-secondary">{tile.priceKobo === null ? 'Type the price' : naira(tile.priceKobo)}</span>}
                                        {tile.kind === 'value' && tile.itemId !== null && tile.priceKobo !== null && <span className="text-lg text-text-secondary">{naira(tile.priceKobo)}</span>}
                                    </button>
                                ))}
                            </div>

                            <div className="mt-auto flex flex-wrap gap-3">
                                <button
                                    type="button"
                                    className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                                    onClick={() => {
                                        setSheet({ kind: 'new-item' });
                                    }}
                                >
                                    New item
                                </button>
                                <button
                                    type="button"
                                    className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                                    onClick={() => {
                                        setSheet({ kind: 'waiting' });
                                    }}
                                >
                                    Held sales ({heldCount})
                                </button>
                                <button
                                    type="button"
                                    className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                                    onClick={() => {
                                        setSheetError(null);
                                        setSheet({ kind: 'no-sale' });
                                    }}
                                >
                                    No sale
                                </button>
                            </div>
                        </>
                    )}
                </main>
                <div className="hidden min-h-0 medium:block">{view === 'after' && afterSale !== null && cart.lines.length === 0 ? <PaidSummary sale={afterSale} /> : panel}</div>
            </div>

            {/* Compact: the sale is a bar at the bottom; tap it to see the lines (wireframe CounterPhone). */}
            <div className="fixed inset-x-0 bottom-0 z-30 flex items-center gap-3 border-t border-border bg-panel p-3 medium:hidden">
                <button
                    type="button"
                    className="flex min-h-touch flex-1 items-center justify-between rounded-xl bg-background px-4 text-lg"
                    onClick={() => {
                        setSheet({ kind: 'cart' });
                    }}
                >
                    <span>Sale · {cart.lines.reduce((s, l) => s + l.qty, 0)} items</span>
                    <strong>{naira(totalKobo(cart))}</strong>
                </button>
                <button type="button" disabled={cart.lines.length === 0 || view !== 'sale'} className="min-h-touch rounded-xl bg-pay px-6 text-xl font-bold text-white disabled:opacity-60" onClick={pay}>
                    Pay
                </button>
            </div>

            {sheet?.kind === 'cart' && (
                <div className="fixed inset-0 z-40 flex flex-col bg-background medium:hidden">
                    <button
                        type="button"
                        className="min-h-touch border-b border-border text-lg"
                        onClick={() => {
                            setSheet(null);
                        }}
                    >
                        Close
                    </button>
                    <div className="min-h-0 flex-1">{panel}</div>
                </div>
            )}

            {sheet?.kind === 'price' && (
                <PricePad
                    name={sheet.name}
                    crumbs={sheet.crumbs}
                    customerType={cart.customerType}
                    prefill={sheet.prefill}
                    prefillSource={sheet.source}
                    range={sheet.range}
                    settings={settings}
                    people={people}
                    api={api}
                    lineId={sheet.lineId}
                    itemId={sheet.itemId}
                    onCancel={() => {
                        setSheet(null);
                    }}
                    onAdd={(choice) => {
                        const source = choice.typed ? 'typed' : (sheet.source ?? 'typed');
                        if (sheet.editing) {
                            update((c) => setPrice(c, sheet.lineId, choice.priceKobo, source, choice));
                        } else {
                            putLine(sheet.itemId, choice.priceKobo, source, sheet.ctx, choice, sheet.lineId);
                        }
                        setSheet(null);
                    }}
                />
            )}

            {sheet?.kind === 'waiting' && (
                <WaitingSales
                    parked={parked}
                    api={api}
                    people={people}
                    canResume={cart.lines.length === 0}
                    onResume={(entry) => void resume(entry)}
                    onChanged={refreshParked}
                    onClose={() => {
                        setSheet(null);
                    }}
                />
            )}

            {sheet?.kind === 'new-item' && (
                <NewItem
                    cat={cat}
                    api={api}
                    requestOnly={settings.cashierItemCreation === 'request_only' && session.user.role === 'cashier'}
                    startCategoryId={state.categoryId}
                    onClose={() => {
                        setSheet(null);
                    }}
                    onPickExisting={(itemId) => {
                        setSheet(null);
                        void addItem(itemId, { addedBy: 'search' });
                    }}
                    onCreated={({ item, prices, priceKobo }) => {
                        sync?.absorb({ items: [item] }, prices);
                        cat.apply({ items: [item] });
                        setSheet(null);
                        putLine(item.id, priceKobo, 'typed', { addedBy: 'new' }, {});
                    }}
                />
            )}

            {sheet?.kind === 'picker' && (
                <PickerSheet
                    code={sheet.code}
                    options={sheet.options}
                    onClose={() => {
                        setSheet(null);
                    }}
                    onPick={(id) => {
                        const code = sheet.code;
                        setSheet(null);
                        void addItem(id, { addedBy: 'scan', barcode: code });
                    }}
                />
            )}

            {sheet?.kind === 'hold' && (
                <HoldSheet
                    error={sheetError}
                    onClose={() => {
                        setSheet(null);
                        setSheetError(null);
                    }}
                    onHold={(label) => void hold(label)}
                />
            )}

            {sheet?.kind === 'no-sale' && (
                <NoSaleSheet
                    error={sheetError}
                    onClose={() => {
                        setSheet(null);
                    }}
                    onOpen={(reason) => {
                        void api
                            .post<{ message?: string }>('/api/counter/drawer/no-sale', { reason })
                            .then((a) => {
                                if (a.ok) {
                                    setSheet(null);
                                    say('info', 'The drawer opened, and the opening is logged.');
                                } else {
                                    setSheetError(a.body.message ?? 'The drawer did not open.');
                                }
                            })
                            .catch(() => {
                                setSheetError('The counter cannot reach the server. Try again.');
                            });
                    }}
                />
            )}

            {sheet?.kind === 'shift' && (
                <ShiftSheet
                    error={sheetError}
                    onSkip={() => {
                        setSheet(null);
                    }}
                    onOpen={(floatKobo) => {
                        void api
                            .post<{ message?: string }>('/api/counter/shift', { opening_float_kobo: floatKobo })
                            .then((a) => {
                                if (a.ok || a.status === 409) {
                                    setSheet(null);
                                    say('info', `The shift is open with ${naira(floatKobo)} in the drawer.`);
                                } else {
                                    setSheetError(a.body.message ?? 'The shift did not open.');
                                }
                            })
                            .catch(() => {
                                setSheetError('The counter cannot reach the server. Try again.');
                            });
                    }}
                />
            )}

            <span className="sr-only" aria-live="polite">
                {terminal.code}
            </span>
        </div>
    );
}
