import { useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import type { CounterApi } from '@/counter/use-api';

/**
 * The sheets Review uses: choose an item (to merge into), choose a listed name (a brand or a phone model),
 * and edit an item's parts with the same pick-lists the counter uses. Its printed name always comes from
 * the server's name routine.
 */
interface Found {
    id: string;
    name: string;
    code?: string;
    category?: string;
}

const button = 'min-h-touch rounded-xl border-2 border-border px-5 text-lg font-semibold text-action-text disabled:opacity-60';
const primary = 'min-h-touch rounded-xl bg-action px-6 text-lg font-bold text-white disabled:opacity-60';

/** Search the catalogue and choose one item. */
export function ItemPicker({ api, title, except, onPick, onClose }: { api: CounterApi; title: string; except?: string; onPick: (item: Found) => void; onClose: () => void }) {
    const [q, setQ] = useState('');
    const [found, setFound] = useState<Found[]>([]);

    useEffect(() => {
        if (q.trim().length < 2) return;
        let live = true;
        const timer = window.setTimeout(() => {
            void api.get<Found[]>(`/api/back-office/catalogue/search?q=${encodeURIComponent(q)}${except === undefined ? '' : `&except=${except}`}`).then((a) => {
                if (live && a.ok) setFound(a.body);
            });
        }, 250);
        return () => {
            live = false;
            window.clearTimeout(timer);
        };
    }, [api, q, except]);

    const shown = q.trim().length < 2 ? [] : found;

    return (
        <Sheet title={title} onClose={onClose}>
            <input
                type="search"
                aria-label="Search items"
                placeholder="Type part of the name or the item code"
                value={q}
                autoFocus
                className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                onChange={(e) => {
                    setQ(e.target.value);
                }}
            />
            <ul className="flex flex-col">
                {shown.map((f) => (
                    <li key={f.id}>
                        <button
                            type="button"
                            className="flex min-h-touch w-full flex-col items-start justify-center border-b border-border px-2 py-2 text-left"
                            onClick={() => {
                                onPick(f);
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
            {q.trim().length >= 2 && shown.length === 0 && <p className="text-text-secondary">No item matches.</p>}
        </Sheet>
    );
}

/** Choose one listed brand, or one value of a pick-list such as the phone models. */
export function NamePicker({ api, title, url, onPick, onClose }: { api: CounterApi; title: string; url: string; onPick: (name: Found) => void; onClose: () => void }) {
    const [names, setNames] = useState<Found[]>([]);
    const [q, setQ] = useState('');

    useEffect(() => {
        void api.get<Found[]>(url).then((a) => {
            if (a.ok) setNames(a.body);
        });
    }, [api, url]);

    const shown = names.filter((n) => n.name.toLowerCase().includes(q.trim().toLowerCase())).slice(0, 60);

    return (
        <Sheet title={title} onClose={onClose}>
            <input
                type="search"
                aria-label="Filter"
                placeholder="Type to narrow the list"
                value={q}
                autoFocus
                className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                onChange={(e) => {
                    setQ(e.target.value);
                }}
            />
            <ul className="grid gap-2 medium:grid-cols-2">
                {shown.map((n) => (
                    <li key={n.id}>
                        <button
                            type="button"
                            className="min-h-touch w-full rounded-xl border-2 border-border px-3 text-left text-lg"
                            onClick={() => {
                                onPick(n);
                            }}
                        >
                            {n.name}
                        </button>
                    </li>
                ))}
            </ul>
        </Sheet>
    );
}

interface Facet {
    key: string;
    label: string;
    type: 'brand' | 'picklist' | 'text';
    options: { id: string; name: string }[] | null;
}

interface ItemDetail {
    id: string;
    name: string;
    category_id: string;
    brand_id: string | null;
    free_text: string | null;
    facets: Record<string, string>;
}

/**
 * Edit an item's category, brand, facets and free text. "Make it an item" and "Move to a category" open
 * the same editor. A change that makes it the same as another item offers the merge instead.
 */
export function ItemEditor({ api, itemId, title, onDone, onMerge, onClose }: { api: CounterApi; itemId: string; title: string; onDone: (message: string) => void; onMerge: (into: Found) => void; onClose: () => void }) {
    const [item, setItem] = useState<ItemDetail | null>(null);
    const [categories, setCategories] = useState<{ id: string; name: string; group: string | null }[]>([]);
    const [categoryId, setCategoryId] = useState('');
    const [form, setForm] = useState<{ category: string; facets: Facet[] } | null>(null);
    const [values, setValues] = useState<Record<string, string>>({});
    const [freeText, setFreeText] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [same, setSame] = useState<Found | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        void Promise.all([api.get<ItemDetail>(`/api/back-office/catalogue/items/${itemId}`), api.get<{ id: string; name: string; group: string | null }[]>('/api/back-office/catalogue/categories')]).then(([i, c]) => {
            if (c.ok) setCategories(c.body);
            if (i.ok) {
                setItem(i.body);
                setValues({ ...i.body.facets, ...(i.body.brand_id === null ? {} : { brand: i.body.brand_id }) });
                setFreeText(i.body.free_text ?? '');
                setCategoryId(c.ok && c.body.some((x) => x.id === i.body.category_id) ? i.body.category_id : '');
            }
        });
    }, [api, itemId]);

    useEffect(() => {
        if (categoryId === '' || item === null) return;
        let live = true;
        const keep = [item.brand_id, ...Object.values(item.facets)].filter((v): v is string => v !== null && /^[0-9a-z]{26}$/.test(v)).join(',');
        void api.get<{ facets: Facet[] }>(`/api/back-office/catalogue/categories/${categoryId}/form?keep=${keep}`).then((a) => {
            if (live && a.ok) setForm({ category: categoryId, facets: a.body.facets });
        });
        return () => {
            live = false;
        };
    }, [api, categoryId, item]);

    const facets = form !== null && form.category === categoryId ? form.facets : [];

    const save = async () => {
        setBusy(true);
        setError(null);
        setSame(null);
        try {
            const sent: Record<string, string> = {};
            for (const f of facets) if (f.type !== 'brand' && (values[f.key] ?? '') !== '') sent[f.key] = values[f.key] ?? '';
            const a = await api.post<{ ok?: boolean; name?: string; message?: string; same?: Found }>(`/api/back-office/catalogue/items/${itemId}`, {
                category_id: categoryId,
                brand_id: values.brand !== undefined && values.brand !== '' ? values.brand : null,
                facets: sent,
                free_text: freeText.trim() === '' ? null : freeText,
            });
            if (a.ok) onDone(`Saved as ${a.body.name ?? 'the new name'}.`);
            else {
                setError(a.body.message ?? 'That did not save. Try again.');
                if (a.body.same !== undefined) setSame(a.body.same);
            }
        } catch {
            setError('The device cannot reach the shop server. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Sheet title={title} onClose={onClose} wide>
            {item === null && <p className="text-lg text-text-secondary">Loading…</p>}
            {item !== null && (
                <div className="flex flex-col gap-4">
                    <p className="text-lg">
                        Now prints as <strong>{item.name}</strong>. Sales keep the name they printed.
                    </p>
                    <label className="flex flex-col gap-2 text-lg font-semibold">
                        Category
                        <select
                            value={categoryId}
                            className="min-h-touch rounded-xl border-2 border-border bg-background px-3 text-lg font-normal"
                            onChange={(e) => {
                                setCategoryId(e.target.value);
                            }}
                        >
                            <option value="">Choose the category</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.group === null ? c.name : `${c.group}: ${c.name}`}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="grid gap-4 medium:grid-cols-2">
                        {facets.map((f) => (
                            <label key={f.key} className="flex flex-col gap-2 text-lg font-semibold">
                                {f.label}
                                {f.options === null ? (
                                    <input
                                        value={values[f.key] ?? ''}
                                        maxLength={80}
                                        className="min-h-touch rounded-xl border-2 border-border px-3 text-lg font-normal"
                                        onChange={(e) => {
                                            setValues({ ...values, [f.key]: e.target.value });
                                        }}
                                    />
                                ) : (
                                    <select
                                        value={values[f.key] ?? ''}
                                        className="min-h-touch rounded-xl border-2 border-border bg-background px-3 text-lg font-normal"
                                        onChange={(e) => {
                                            setValues({ ...values, [f.key]: e.target.value });
                                        }}
                                    >
                                        <option value="">None</option>
                                        {f.options.map((o) => (
                                            <option key={o.id} value={o.id}>
                                                {o.name}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </label>
                        ))}
                    </div>
                    <label className="flex flex-col gap-2 text-lg font-semibold">
                        Anything else on the name (optional)
                        <input
                            value={freeText}
                            maxLength={80}
                            className="min-h-touch rounded-xl border-2 border-border px-3 text-lg font-normal"
                            onChange={(e) => {
                                setFreeText(e.target.value);
                            }}
                        />
                    </label>
                    {error !== null && <Notice tone="error">{error}</Notice>}
                    <div className="flex flex-wrap justify-end gap-3">
                        {same !== null && (
                            <button
                                type="button"
                                className={button}
                                onClick={() => {
                                    onMerge(same);
                                }}
                            >
                                Merge into {same.name}
                            </button>
                        )}
                        <button type="button" disabled={busy || categoryId === ''} className={primary} onClick={() => void save()}>
                            Save
                        </button>
                    </div>
                </div>
            )}
        </Sheet>
    );
}

/** Confirm a merge, saying what it does. */
export function MergeConfirm({ from, into, busy, onConfirm, onClose }: { from: string; into: string; busy: boolean; onConfirm: () => void; onClose: () => void }) {
    return (
        <Sheet title="Merge these items?" onClose={onClose}>
            <p className="text-lg">
                <strong>{from}</strong> will count under <strong>{into}</strong>.
            </p>
            <p className="text-lg text-text-secondary">
                No sale changes, and receipts keep what they printed. Reports count both items' sales together, and scanning either barcode adds {into}. You can undo it later from Duplicates.
            </p>
            <div className="flex justify-end">
                <button type="button" disabled={busy} className={primary} onClick={onConfirm}>
                    Merge into {into}
                </button>
            </div>
        </Sheet>
    );
}

export const styles = { button, primary };
