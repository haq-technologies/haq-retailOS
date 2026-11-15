import { useCallback, useEffect, useState } from 'react';
import { WarningIcon } from '@/components/icons';
import { Notice } from '@/components/notice';
import type { CounterApi } from '@/counter/use-api';
import { naira, plural, shortDate, whenInline } from './format';
import { ItemEditor, ItemPicker, MergeConfirm, NamePicker, styles } from './pickers';
import { NewCategory, Pair } from './review-parts';
import type { Open, Queue, Tab } from './review-types';
import type { Can } from './types';

/**
 * Review (spec sections 3 and 6; wireframes ReviewNames, ReviewNewItems, ReviewBarcodes and MergeItems):
 * what cashiers added while selling. Nothing here held up a sale, and no answer changes a sale: receipts
 * keep what they printed. Managers and the owner approve brands and phone models; only the owner creates a
 * category; managers, the storekeeper and the owner do the rest.
 */
const row = 'flex flex-col gap-3 border-b border-border py-4 large:flex-row large:items-center';
const { button, primary } = styles;

/** "on 3 sales", or "with no sales yet". */
function onSales(n: number): string {
    return n === 0 ? 'with no sales yet' : `on ${plural(n, 'sale', 'sales')}`;
}

function prices(list: number[]): string {
    if (list.length === 0) return '';
    const sorted = [...list].sort((a, b) => a - b);
    const low = sorted[0] ?? 0;
    const high = sorted[sorted.length - 1] ?? 0;
    return low === high ? ` at ${naira(low)}` : ` at ${naira(low)} to ${naira(high)}`;
}

export function Review({ api, can, onChanged }: { api: CounterApi; can: Can; onChanged: () => void }) {
    const [tab, setTab] = useState<Tab>('names');
    const [queue, setQueue] = useState<Queue | null>(null);
    const [message, setMessage] = useState<{ tone: 'error' | 'ok'; text: string } | null>(null);
    const [open, setOpen] = useState<Open>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        void api
            .get<Queue>('/api/back-office/review')
            .then((a) => {
                if (a.ok) setQueue(a.body);
            })
            .catch(() => {
                setMessage({ tone: 'error', text: 'Review could not load. Check the connection.' });
            });
    }, [api]);

    useEffect(() => {
        load();
    }, [load]);

    /** Sends one answer, then refreshes the list and the menu's counts. */
    const answer = async (url: string, body: unknown, done: string) => {
        setBusy(true);
        setMessage(null);
        try {
            const a = await api.post<{ message?: string }>(url, body);
            if (a.ok) {
                setMessage({ tone: 'ok', text: done });
                setOpen(null);
            } else {
                setMessage({ tone: 'error', text: a.body.message ?? 'That did not save. Try again.' });
            }
        } catch {
            setMessage({ tone: 'error', text: 'The device cannot reach the shop server. Try again.' });
        } finally {
            setBusy(false);
            load();
            onChanged();
        }
    };

    const tabs: { id: Tab; label: string; count: number }[] = [
        { id: 'names', label: 'Brands and words', count: queue?.counts.names ?? 0 },
        { id: 'items', label: 'New items', count: queue?.counts.items ?? 0 },
        { id: 'barcodes', label: 'Barcodes', count: queue?.counts.barcodes ?? 0 },
        { id: 'duplicates', label: 'Duplicates', count: queue?.counts.duplicates ?? 0 },
    ];

    return (
        <div className="flex flex-col gap-5">
            <header className="flex flex-col gap-1">
                <h1 className="text-3xl font-bold">Review</h1>
                <p className="text-lg text-text-secondary">What cashiers added while selling. Nothing here held up a sale, and approving changes no sale: receipts keep what they printed.</p>
            </header>

            {message !== null && (message.tone === 'error' ? <Notice tone="error">{message.text}</Notice> : <p role="status" className="rounded-xl bg-panel p-4 text-lg">{message.text}</p>)}

            <div role="tablist" aria-label="Review" className="flex flex-wrap gap-2 border-b border-border">
                {tabs.map((t) => (
                    <button
                        key={t.id}
                        type="button"
                        role="tab"
                        aria-selected={tab === t.id}
                        className={`min-h-touch px-4 text-lg ${tab === t.id ? 'font-bold text-action-text shadow-[inset_0_-3px_0_var(--color-action)]' : 'font-medium'}`}
                        onClick={() => {
                            setTab(t.id);
                        }}
                    >
                        {t.label} · {t.count}
                    </button>
                ))}
            </div>

            {queue === null && <p className="text-lg text-text-secondary">Loading…</p>}

            {queue !== null && tab === 'names' && (
                <div role="tabpanel" aria-label="Brands and words" className="flex flex-col gap-6">
                    <section aria-labelledby="rv-brands" className="flex flex-col">
                        <h2 id="rv-brands" className="text-xl font-bold">
                            Brands typed at the counter
                        </h2>
                        {queue.brands.length === 0 && <p className="py-3 text-text-secondary">None waiting.</p>}
                        {queue.brands.map((b) => (
                            <div key={b.id} className={row}>
                                <span className="flex min-w-0 flex-1 flex-col gap-1">
                                    <span className="text-lg font-semibold">{b.name}</span>
                                    <span className="text-text-secondary">
                                        Typed by {b.typed_by ?? 'a cashier'} {onSales(b.sales)}
                                        {b.items !== '' && `: ${b.items}`}
                                        {b.first_sold !== null && ` · first on ${shortDate(b.first_sold)}`}
                                        {b.looks_like !== null && (
                                            <>
                                                {' · looks like '}
                                                <strong className="text-text">{b.looks_like.name}</strong>
                                            </>
                                        )}
                                    </span>
                                </span>
                                {can.names && (
                                    <span className="flex flex-wrap gap-2">
                                        {b.looks_like === null ? (
                                            <>
                                                <button type="button" className={button} onClick={() => { setOpen({ kind: 'pick-brand', brand: b }); }}>
                                                    Same as a listed brand
                                                </button>
                                                <button type="button" disabled={busy} className={primary} onClick={() => void answer(`/api/back-office/review/brands/${b.id}/approve`, {}, `${b.name} is now a listed brand.`)}>
                                                    Add to brands
                                                </button>
                                            </>
                                        ) : (
                                            <>
                                                <button type="button" disabled={busy} className={button} onClick={() => void answer(`/api/back-office/review/brands/${b.id}/approve`, {}, `${b.name} is now a listed brand.`)}>
                                                    Add as a new brand
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={busy}
                                                    className={primary}
                                                    onClick={() => {
                                                        const into = b.looks_like;
                                                        if (into !== null) void answer(`/api/back-office/review/brands/${b.id}/merge`, { into_id: into.id }, `${b.name} now counts as ${into.name}.`);
                                                    }}
                                                >
                                                    Merge into {b.looks_like.name}
                                                </button>
                                            </>
                                        )}
                                    </span>
                                )}
                            </div>
                        ))}
                    </section>

                    <section aria-labelledby="rv-models" className="flex flex-col">
                        <h2 id="rv-models" className="text-xl font-bold">
                            Phone models typed at the counter
                        </h2>
                        {queue.models.length === 0 && <p className="py-3 text-text-secondary">None waiting.</p>}
                        {queue.models.map((m) => (
                            <div key={m.id} className={row}>
                                <span className="flex min-w-0 flex-1 flex-col gap-1">
                                    <span className="text-lg font-semibold">{m.name}</span>
                                    <span className="text-text-secondary">
                                        Typed by {m.typed_by ?? 'a cashier'} {onSales(m.sales)}
                                        {m.items !== '' && `: ${m.items}`}
                                        {m.family !== null && ` · family: ${m.family}`}
                                        {m.looks_like !== null && ` · looks like ${m.looks_like.name}`}
                                    </span>
                                </span>
                                {can.names && (
                                    <span className="flex flex-wrap gap-2">
                                        {m.looks_like !== null ? (
                                            <button
                                                type="button"
                                                disabled={busy}
                                                className={button}
                                                onClick={() => {
                                                    const into = m.looks_like;
                                                    if (into !== null) void answer(`/api/back-office/review/models/${m.id}/merge`, { into_id: into.id }, `${m.name} now counts as ${into.name}.`);
                                                }}
                                            >
                                                Same as {m.looks_like.name}
                                            </button>
                                        ) : (
                                            <button type="button" className={button} onClick={() => { setOpen({ kind: 'pick-model', model: m }); }}>
                                                Same as a listed model
                                            </button>
                                        )}
                                        <button type="button" disabled={busy} className={primary} onClick={() => void answer(`/api/back-office/review/models/${m.id}/approve`, {}, `${m.name} is now on the list.`)}>
                                            {m.family === null ? 'Add to the list' : `Add to ${m.family} models`}
                                        </button>
                                    </span>
                                )}
                            </div>
                        ))}
                    </section>

                    <section aria-labelledby="rv-words" className="flex flex-col">
                        <h2 id="rv-words" className="text-xl font-bold">
                            Products not in the list
                        </h2>
                        {queue.words.length === 0 && <p className="py-3 text-text-secondary">None waiting.</p>}
                        {queue.words.map((w) => (
                            <div key={w.word} className={row}>
                                <span className="flex min-w-0 flex-1 flex-col gap-1">
                                    <span className="text-lg font-semibold">{w.word}</span>
                                    <span className="text-text-secondary">
                                        Typed by {w.typed_by ?? 'a cashier'} {onSales(w.sales)}
                                        {w.low_kobo !== null && prices([w.low_kobo, w.high_kobo ?? w.low_kobo])} · the receipt printed “{w.names}”
                                    </span>
                                </span>
                                <span className="flex flex-wrap gap-2">
                                    {w.item_ids[0] !== undefined && (
                                        <button
                                            type="button"
                                            className={button}
                                            onClick={() => {
                                                const id = w.item_ids[0];
                                                if (id !== undefined) setOpen({ kind: 'edit', itemId: id, title: `Move “${w.word}” to a category` });
                                            }}
                                        >
                                            Move to a category
                                        </button>
                                    )}
                                    {can.categories && (
                                        <button type="button" className={primary} onClick={() => { setOpen({ kind: 'category', word: w.word }); }}>
                                            Create a category
                                        </button>
                                    )}
                                </span>
                            </div>
                        ))}
                    </section>
                    <p className="text-text-secondary">Managers can approve brands and phone models; only the owner creates categories. Until then, a typed name stays on its own items and prints as typed. Every approval goes in the audit log.</p>
                </div>
            )}

            {queue !== null && tab === 'items' && (
                <div role="tabpanel" aria-label="New items" className="flex flex-col gap-2">
                    <p className="text-lg">Items created at the counter could be sold at once. Check that each one looks right.</p>
                    {queue.items.length === 0 && <p className="text-text-secondary">None waiting.</p>}
                    <ul className="flex flex-col">
                        {queue.items.map((i) => (
                            <li key={i.id} className={row}>
                                <span className="flex min-w-0 flex-1 flex-col gap-1">
                                    <span className="text-lg font-semibold">
                                        {i.name}
                                        {i.typed_line && <span className="font-medium text-text-secondary"> · typed line</span>}
                                    </span>
                                    <span className="text-text-secondary">
                                        {i.typed_line ? 'Typed' : 'Created'} by {i.created_by ?? 'a cashier'} {whenInline(i.created_at)} · {i.category}
                                        {i.parts !== '' && `, ${i.parts}`}
                                        {i.brand_waiting !== null && ` · the brand ${i.brand_waiting} is waiting in Brands and words`} · {i.sales === 0 ? 'no sales yet' : `${plural(i.sales, 'sale', 'sales')}${prices(i.prices)}`}
                                    </span>
                                </span>
                                <span className="flex flex-wrap gap-2">
                                    {i.typed_line ? (
                                        can.promote && (
                                            <button type="button" className={primary} onClick={() => { setOpen({ kind: 'edit', itemId: i.id, title: `Make “${i.name}” an item` }); }}>
                                                Make it an item
                                            </button>
                                        )
                                    ) : (
                                        <>
                                            <button type="button" className={button} onClick={() => { setOpen({ kind: 'edit', itemId: i.id, title: `Edit ${i.name}` }); }}>
                                                Edit
                                            </button>
                                            <button type="button" className={button} onClick={() => { setOpen({ kind: 'pick-item', from: i }); }}>
                                                Merge into
                                            </button>
                                            <button type="button" disabled={busy} className={primary} onClick={() => void answer(`/api/back-office/review/items/${i.id}/approve`, {}, `${i.name} looks right.`)}>
                                                Looks right
                                            </button>
                                        </>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p className="text-text-secondary">Looks right takes the item off this list and changes no sale. Making a typed line into an item picks its brand and facets, and only the owner or the storekeeper does it. The dashboard counts the items each cashier creates.</p>
                </div>
            )}

            {queue !== null && tab === 'barcodes' && (
                <div role="tabpanel" aria-label="Barcodes" className="flex flex-col gap-6">
                    <section aria-labelledby="bc-learned" className="flex flex-col">
                        <h2 id="bc-learned" className="text-xl font-bold">
                            Learned barcodes, not yet confirmed
                        </h2>
                        <p className="py-1 text-text-secondary">A learned barcode works at once. It is confirmed after two more sales choose the same item. Undoing a link changes no sale.</p>
                        {queue.learned.length === 0 && <p className="py-3 text-text-secondary">None waiting.</p>}
                        {queue.learned.map((l) => (
                            <div key={l.id} className={row}>
                                <span className="w-52 shrink-0 font-mono text-lg">{l.code}</span>
                                <span className="flex min-w-0 flex-1 flex-col gap-1">
                                    <span className="text-lg font-semibold">{l.name}</span>
                                    <span className="text-text-secondary">
                                        Learned from {l.learned_by ?? 'a cashier'}'s sale {whenInline(l.learned_at)} · {l.confirmations} of 2 more sales so far
                                    </span>
                                </span>
                                <button type="button" disabled={busy} className={button} onClick={() => void answer(`/api/back-office/review/barcodes/${l.id}/undo`, {}, `The link from ${l.code} to ${l.name} is undone.`)}>
                                    Undo link
                                </button>
                            </div>
                        ))}
                    </section>

                    {queue.conflicts.length > 0 && (
                        <section aria-labelledby="bc-conflict" className="flex flex-col">
                            <h2 id="bc-conflict" className="flex items-center gap-2 text-xl font-bold">
                                <WarningIcon className="h-6 w-6 text-warning-text" />
                                Conflict: cashiers chose different items
                            </h2>
                            {queue.conflicts.map((c) => (
                                <div key={c.code} className="flex flex-col gap-3 border-b border-border py-4">
                                    <p className="flex flex-col gap-1 text-lg medium:flex-row medium:gap-4">
                                        <span className="w-52 shrink-0 font-mono">{c.code}</span>
                                        <span>{c.links.map((l) => `${l.chosen_by ?? 'A cashier'} chose ${l.name} on ${plural(l.sales, 'sale', 'sales')}.`).join(' ')}</span>
                                    </p>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {c.links.map((l) => (
                                            <button key={l.item_id} type="button" disabled={busy} className={button} onClick={() => void answer('/api/back-office/review/barcodes/settle', { code: c.code, keep_item_id: l.item_id }, `${c.code} now adds ${l.name}.`)}>
                                                Keep {l.name}
                                            </button>
                                        ))}
                                        <button type="button" disabled={busy} className={button} onClick={() => void answer('/api/back-office/review/barcodes/settle', { code: c.code, keep_item_id: null }, `${c.code} keeps both; the counter asks which one.`)}>
                                            Keep both
                                        </button>
                                        <span className="text-text-secondary">With both, the counter asks which one each time.</span>
                                    </div>
                                </div>
                            ))}
                        </section>
                    )}

                    <section aria-labelledby="bc-words" className="flex flex-col">
                        <h2 id="bc-words" className="text-xl font-bold">
                            Search words
                        </h2>
                        <p className="py-1 text-text-secondary">When a search leads to the same item three times, the word starts finding that item. Remove any that are wrong; a removed word never comes back by itself.</p>
                        {queue.aliases.length === 0 && <p className="py-3 text-text-secondary">None yet.</p>}
                        {queue.aliases.map((a) => (
                            <div key={a.id} className={row}>
                                <span className="w-52 shrink-0 text-lg font-semibold">“{a.term}”</span>
                                <span className="flex-1 text-lg">
                                    {a.name} <span className="text-text-secondary">· {a.active ? 'in use' : `${String(a.hits)} of ${String(a.needed)}, not in use yet`}</span>
                                </span>
                                <button type="button" disabled={busy} className={button} onClick={() => void answer(`/api/back-office/review/aliases/${a.id}/remove`, {}, `“${a.term}” no longer finds ${a.name}.`)}>
                                    Remove
                                </button>
                            </div>
                        ))}
                    </section>
                    <p className="text-text-secondary">Codes that are too short or repeat one digit, such as 000000, never learn; scanning one opens search instead.</p>
                </div>
            )}

            {queue !== null && tab === 'duplicates' && (
                <div role="tabpanel" aria-label="Duplicates" className="flex flex-col gap-5">
                    {queue.duplicates.length === 0 && <p className="text-lg text-text-secondary">No items look alike.</p>}
                    {queue.duplicates.map((d) => (
                        <Pair key={`${d.keep.id}|${d.merge.id}`} pair={d} busy={busy} answer={answer} />
                    ))}
                    {queue.merges.length > 0 && (
                        <section aria-labelledby="merged-h" className="flex flex-col">
                            <h2 id="merged-h" className="text-xl font-bold">
                                Merged recently
                            </h2>
                            {queue.merges.map((m) => (
                                <div key={m.id} className={row}>
                                    <span className="flex min-w-0 flex-1 flex-col gap-1">
                                        <span className="text-lg font-semibold">
                                            {m.name} into {m.into_name}
                                        </span>
                                        <span className="text-text-secondary">
                                            By {m.by} {whenInline(m.at)} · {plural(m.sales, 'sale', 'sales')} now counted together
                                        </span>
                                    </span>
                                    <button type="button" disabled={busy} className={button} onClick={() => void answer(`/api/back-office/review/merges/${m.id}/undo`, {}, `${m.name} is sold on its own again.`)}>
                                        Undo merge
                                    </button>
                                </div>
                            ))}
                        </section>
                    )}
                </div>
            )}

            {open?.kind === 'edit' && (
                <ItemEditor
                    api={api}
                    itemId={open.itemId}
                    title={open.title}
                    onClose={() => {
                        setOpen(null);
                    }}
                    onDone={(text) => {
                        setOpen(null);
                        setMessage({ tone: 'ok', text });
                        load();
                        onChanged();
                    }}
                    onMerge={(into) => {
                        const id = open.itemId;
                        const name = queue?.items.find((i) => i.id === id)?.name ?? queue?.words.find((w) => w.item_ids.includes(id))?.names ?? 'This item';
                        setOpen({ kind: 'confirm-merge', from: { id, name }, into });
                    }}
                />
            )}
            {open?.kind === 'pick-item' && (
                <ItemPicker
                    api={api}
                    title={`Merge ${open.from.name} into`}
                    except={open.from.id}
                    onClose={() => {
                        setOpen(null);
                    }}
                    onPick={(into) => {
                        setOpen({ kind: 'confirm-merge', from: open.from, into });
                    }}
                />
            )}
            {open?.kind === 'confirm-merge' && (
                <MergeConfirm
                    from={open.from.name}
                    into={open.into.name}
                    busy={busy}
                    onClose={() => {
                        setOpen(null);
                    }}
                    onConfirm={() => void answer(`/api/back-office/review/items/${open.from.id}/merge`, { into_id: open.into.id }, `${open.from.name} now counts under ${open.into.name}.`)}
                />
            )}
            {open?.kind === 'pick-brand' && (
                <NamePicker
                    api={api}
                    title={`${open.brand.name} is the same as`}
                    url="/api/back-office/catalogue/names/brands"
                    onClose={() => {
                        setOpen(null);
                    }}
                    onPick={(into) => void answer(`/api/back-office/review/brands/${open.brand.id}/merge`, { into_id: into.id }, `${open.brand.name} now counts as ${into.name}.`)}
                />
            )}
            {open?.kind === 'pick-model' && (
                <NamePicker
                    api={api}
                    title={`${open.model.name} is the same as`}
                    url={`/api/back-office/catalogue/names/values?list=${encodeURIComponent(open.model.list)}`}
                    onClose={() => {
                        setOpen(null);
                    }}
                    onPick={(into) => void answer(`/api/back-office/review/models/${open.model.id}/merge`, { into_id: into.id }, `${open.model.name} now counts as ${into.name}.`)}
                />
            )}
            {open?.kind === 'category' && (
                <NewCategory
                    api={api}
                    word={open.word}
                    busy={busy}
                    onClose={() => {
                        setOpen(null);
                    }}
                    onCreate={(body) => void answer('/api/back-office/review/categories', body, `${body.name} is a new category; its items moved there.`)}
                />
            )}
        </div>
    );
}

/** Two items that look the same: keep one, merge the other into it, or say they are not the same. */
