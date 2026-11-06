import { useMemo, useState } from 'react';
import { Keypad } from '@/components/keypad';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import type { Catalogue } from '../catalogue';
import { digitsToKobo, naira, typeDigits } from '../money';
import type { CategoryRow, FacetDefinition, ItemFacetRow, ItemPrices, ItemRow } from '../types';
import type { CounterApi } from '../use-api';

/**
 * New item at the counter (spec section 6, guardrails) [F11, F20, C2]: the category first, the facets from
 * pickers, a typed brand or phone model that prints as typed and waits for approval, "Not in the list" for
 * a product word, similar items offered first, and the price. A cashier's new item sells at once and waits
 * in the owner's review queue. Wireframe: NewItem.
 */
interface Created {
    item: ItemRow;
    facets: ItemFacetRow[];
    prices: ItemPrices[];
    priceKobo: number;
}

interface Props {
    cat: Catalogue;
    api: CounterApi;
    requestOnly: boolean;
    startCategoryId: string | null;
    onCreated: (created: Created) => void;
    onPickExisting: (itemId: string) => void;
    onClose: () => void;
}

interface CreateAnswer {
    item?: ItemRow;
    prices?: ItemPrices[];
    message?: string;
    similar?: { id: string; name: string }[];
}

const NO_BRAND = 'no brand';

export function NewItem({ cat, api, requestOnly, startCategoryId, onCreated, onPickExisting, onClose }: Props) {
    const start = startCategoryId === null ? undefined : cat.categories.get(startCategoryId);
    const [category, setCategory] = useState<CategoryRow | null>(start?.sellable === true ? start : null);
    const [browse, setBrowse] = useState<string | null>(start !== undefined && !start.sellable ? start.id : null);
    const [notInList, setNotInList] = useState(false);
    const [word, setWord] = useState('');
    const [brandId, setBrandId] = useState<string | null>(null);
    const [brandText, setBrandText] = useState('');
    const [facets, setFacets] = useState<Record<string, string>>({});
    const [phoneModelText, setPhoneModelText] = useState('');
    const [freeText, setFreeText] = useState('');
    const [more, setMore] = useState<string | null>(null);
    const [digits, setDigits] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [similar, setSimilar] = useState<{ id: string; name: string }[]>([]);

    const template = category === null ? undefined : cat.templates.get(category.id);
    const fields = (template?.facets ?? []).filter((f) => f.type !== 'brand');
    const hasBrand = template?.facets.some((f) => f.type === 'brand') === true;

    const brands = useMemo(() => {
        const all = [...cat.brands.values()].filter((b) => b.status !== 'merged');
        const code = category?.code ?? '';
        return all.sort((a, b) => Number(b.shown_first_in.includes(code)) - Number(a.shown_first_in.includes(code)) || a.name.localeCompare(b.name));
    }, [cat, category]);

    const typedBrandKnown = brandText.trim() !== '' && brands.some((b) => b.name.toLowerCase() === brandText.trim().toLowerCase());

    const preview = useMemo(() => {
        if (notInList) return [word, freeText].map((s) => s.trim()).filter((s) => s !== '').join(' ');
        if (category === null || template === undefined) return '';
        if (requestOnly) return [category.word ?? category.name, freeText.trim()].filter((s) => s !== '').join(' ');
        const parts: Record<string, string> = {};
        const brandName = brandId === null ? brandText.trim() : (cat.brands.get(brandId)?.name ?? '');
        if (brandName.toLowerCase() !== NO_BRAND) parts.brand = brandName;
        for (const f of fields) {
            const v = f.key === 'phone_model' && phoneModelText.trim() !== '' ? phoneModelText.trim() : facets[f.key];
            if (v === undefined || v === '') continue;
            parts[f.key] = f.type === 'picklist' ? (cat.picklist.get(v)?.name ?? v) : v;
        }
        const pieces = template.name_order.map((t) => (t === '{word}' ? (category.word ?? category.name) : (parts[t] ?? '')));
        pieces.push(freeText.trim());
        return pieces.filter((p) => p.trim() !== '').join(' ');
    }, [notInList, word, freeText, category, template, requestOnly, brandId, brandText, cat, fields, facets, phoneModelText]);

    const send = async (confirmNew: boolean) => {
        setBusy(true);
        setError(null);
        const facetValues: Record<string, string> = {};
        for (const [k, v] of Object.entries(facets)) if (v !== '') facetValues[k] = v;
        try {
            const answer = await api.post<CreateAnswer>('/api/counter/items', {
                category_id: notInList ? null : (category?.id ?? null),
                not_in_list_word: notInList ? word : null,
                facets: facetValues,
                brand_id: brandId,
                brand_text: brandId === null && brandText.trim() !== '' ? brandText.trim() : null,
                phone_model_text: phoneModelText.trim() === '' ? null : phoneModelText.trim(),
                free_text: freeText.trim() === '' ? null : freeText.trim(),
                confirm_new: confirmNew,
            });
            if (answer.ok && answer.body.item !== undefined) {
                const item = answer.body.item;
                onCreated({ item, facets: [], prices: answer.body.prices ?? [], priceKobo: digitsToKobo(digits) });
            } else {
                setSimilar(answer.body.similar ?? []);
                setError(answer.body.message ?? 'That item could not be made. Check it and try again.');
            }
        } catch {
            setError('The counter cannot reach the server. A new item needs the server; try again.');
        } finally {
            setBusy(false);
        }
    };

    const categories = [...cat.categories.values()]
        .filter((c) => c.parent_id === browse && !c.code.startsWith('_'))
        .sort((a, b) => a.position - b.position || a.name.localeCompare(b.name));

    return (
        <Sheet title="New item" onClose={onClose} wide>
            <p className="text-text-secondary">Sellable now; the owner reviews it later.</p>

            {category === null && !notInList && (
                <section aria-label="Category" className="flex flex-col gap-3">
                    <p className="text-lg font-semibold">Category</p>
                    {browse !== null && (
                        <button
                            type="button"
                            className="min-h-touch self-start rounded-xl border-2 border-border px-4"
                            onClick={() => {
                                setBrowse(cat.categories.get(browse)?.parent_id ?? null);
                            }}
                        >
                            Back
                        </button>
                    )}
                    <div className="grid grid-cols-2 gap-2 medium:grid-cols-3">
                        {categories.map((c) => (
                            <button
                                key={c.id}
                                type="button"
                                className="min-h-touch rounded-xl border-2 border-border px-3 py-2 text-left text-lg"
                                onClick={() => {
                                    const children = [...cat.categories.values()].some((x) => x.parent_id === c.id);
                                    if (children || !c.sellable) setBrowse(c.id);
                                    else setCategory(c);
                                }}
                            >
                                {c.name}
                            </button>
                        ))}
                        <button
                            type="button"
                            className="min-h-touch rounded-xl border-2 border-dashed border-border px-3 py-2 text-left text-lg"
                            onClick={() => {
                                setNotInList(true);
                            }}
                        >
                            Not in the list
                        </button>
                    </div>
                </section>
            )}

            {(category !== null || notInList) && (
                <p className="flex flex-wrap items-center gap-3 text-lg">
                    Category <strong>{notInList ? 'Not in the list' : category?.name}</strong>
                    <button
                        type="button"
                        className="min-h-touch rounded-xl border-2 border-border px-4"
                        onClick={() => {
                            setCategory(null);
                            setNotInList(false);
                            setFacets({});
                            setSimilar([]);
                        }}
                    >
                        Change
                    </button>
                </p>
            )}

            {notInList && (
                <label className="flex flex-col gap-1 text-lg">
                    What is it? Type the product word
                    <input
                        type="text"
                        value={word}
                        maxLength={40}
                        placeholder="Such as: Solar lamp"
                        className="min-h-touch rounded-xl border-2 border-border px-4"
                        onChange={(e) => {
                            setWord(e.target.value);
                        }}
                    />
                    <span className="text-text-secondary">It prints as you type it. The owner adds a category for it later.</span>
                </label>
            )}

            {category !== null && !requestOnly && hasBrand && (
                <section aria-label="Brand" className="flex flex-col gap-2">
                    <p className="text-lg font-semibold">Brand</p>
                    <div className="flex flex-wrap gap-2">
                        {(more === 'brand' ? brands : brands.slice(0, 8)).map((b) => (
                            <button
                                key={b.id}
                                type="button"
                                aria-pressed={brandId === b.id}
                                className={`min-h-touch rounded-xl border-2 px-4 ${brandId === b.id ? 'border-action font-semibold' : 'border-border'}`}
                                onClick={() => {
                                    setBrandId(brandId === b.id ? null : b.id);
                                    setBrandText('');
                                }}
                            >
                                {b.name}
                            </button>
                        ))}
                        {more !== 'brand' && brands.length > 8 && (
                            <button
                                type="button"
                                className="min-h-touch rounded-xl border-2 border-border px-4"
                                onClick={() => {
                                    setMore('brand');
                                }}
                            >
                                More
                            </button>
                        )}
                    </div>
                    <input
                        type="text"
                        aria-label="Or type the brand"
                        placeholder="Or type the brand"
                        value={brandText}
                        maxLength={60}
                        className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                        onChange={(e) => {
                            setBrandText(e.target.value);
                            setBrandId(null);
                        }}
                    />
                    {brandText.trim() !== '' && !typedBrandKnown && (
                        <p className="text-text-secondary">
                            {brandText.trim()} is not in the brand list. It prints as typed, and a manager or the owner adds it to the list later.
                        </p>
                    )}
                </section>
            )}

            {category !== null &&
                !requestOnly &&
                fields.map((f) => (
                    <FacetField
                        key={f.key}
                        cat={cat}
                        definition={f}
                        value={facets[f.key] ?? ''}
                        showAll={more === f.key}
                        typed={f.key === 'phone_model' ? phoneModelText : ''}
                        onShowAll={() => {
                            setMore(f.key);
                        }}
                        onChange={(v) => {
                            setFacets((x) => ({ ...x, [f.key]: v }));
                            if (f.key === 'phone_model') setPhoneModelText('');
                        }}
                        onTyped={(t) => {
                            setPhoneModelText(t);
                            setFacets((x) => ({ ...x, phone_model: '' }));
                        }}
                    />
                ))}

            {(category !== null || notInList) && (
                <label className="flex flex-col gap-1 text-lg">
                    {requestOnly ? 'Describe the item, in at least two words' : 'Anything else'} <span className="text-text-secondary">{requestOnly ? '' : '· optional'}</span>
                    <input
                        type="text"
                        value={freeText}
                        maxLength={80}
                        className="min-h-touch rounded-xl border-2 border-border px-4"
                        onChange={(e) => {
                            setFreeText(e.target.value);
                        }}
                    />
                </label>
            )}

            {preview !== '' && (
                <p className="rounded-xl bg-panel p-4 text-text-secondary">
                    Prints as <strong className="block text-xl text-text">{preview}</strong>
                </p>
            )}

            {(category !== null || notInList) && (
                <section aria-label="Price" className="flex flex-col gap-2">
                    <p className="text-lg font-semibold">Retail price</p>
                    <output className="flex min-h-touch items-center rounded-xl border-2 border-action px-4 text-3xl font-bold">
                        {digits === '' ? <span className="text-text-secondary">₦</span> : naira(digitsToKobo(digits))}
                    </output>
                    <Keypad
                        onKey={(key) => {
                            setDigits((d) => typeDigits(d, key));
                        }}
                    />
                </section>
            )}

            {error !== null && <Notice tone={similar.length > 0 ? 'warning' : 'error'}>{error}</Notice>}
            {similar.length > 0 && (
                <div className="flex flex-col gap-2">
                    {similar.map((s) => (
                        <button
                            key={s.id}
                            type="button"
                            className="min-h-touch rounded-xl border-2 border-border px-4 text-left text-lg font-semibold"
                            onClick={() => {
                                onPickExisting(s.id);
                            }}
                        >
                            Sell {s.name}
                        </button>
                    ))}
                    <button type="button" disabled={busy} className="min-h-touch rounded-xl border-2 border-action px-4 text-lg text-action-text" onClick={() => void send(true)}>
                        Create the new item anyway
                    </button>
                </div>
            )}

            {(category !== null || notInList) && similar.length === 0 && (
                <button
                    type="button"
                    disabled={busy || digits === ''}
                    className="min-h-touch rounded-xl bg-action text-lg font-bold text-white disabled:opacity-60"
                    onClick={() => void send(false)}
                >
                    {digits === '' ? 'Type the price' : `Add at ${naira(digitsToKobo(digits))}`}
                </button>
            )}
        </Sheet>
    );
}

interface FieldProps {
    cat: Catalogue;
    definition: FacetDefinition;
    value: string;
    typed: string;
    showAll: boolean;
    onShowAll: () => void;
    onChange: (value: string) => void;
    onTyped: (text: string) => void;
}

function FacetField({ cat, definition, value, typed, showAll, onShowAll, onChange, onTyped }: FieldProps) {
    if (definition.type === 'text') {
        return (
            <label className="flex flex-col gap-1 text-lg">
                {definition.label} <span className="text-text-secondary">· optional</span>
                <input
                    type="text"
                    value={value}
                    maxLength={80}
                    className="min-h-touch rounded-xl border-2 border-border px-4"
                    onChange={(e) => {
                        onChange(e.target.value);
                    }}
                />
            </label>
        );
    }
    const values = [...cat.picklist.values()]
        .filter((v) => v.list === definition.list && v.status !== 'archived')
        .sort((a, b) => a.position - b.position || a.name.localeCompare(b.name));
    // A long list (phone models) narrows as the cashier types.
    const needle = typed.trim().toLowerCase();
    const shown = needle !== '' ? values.filter((v) => v.name.toLowerCase().includes(needle)).slice(0, 10) : showAll ? values : values.slice(0, 5);
    return (
        <section aria-label={definition.label} className="flex flex-col gap-2">
            <p className="text-lg font-semibold">{definition.label}</p>
            <div className="flex flex-wrap gap-2">
                {shown.map((v) => (
                    <button
                        key={v.id}
                        type="button"
                        aria-pressed={value === v.id}
                        className={`min-h-touch rounded-xl border-2 px-4 ${value === v.id ? 'border-action font-semibold' : 'border-border'}`}
                        onClick={() => {
                            onChange(value === v.id ? '' : v.id);
                        }}
                    >
                        {v.name}
                    </button>
                ))}
                {!showAll && needle === '' && values.length > 5 && (
                    <button type="button" className="min-h-touch rounded-xl border-2 border-border px-4" onClick={onShowAll}>
                        More
                    </button>
                )}
            </div>
            {definition.allow_typed === true && (
                <input
                    type="text"
                    aria-label={`Or type the ${definition.label.toLowerCase()}`}
                    placeholder={`Or type the ${definition.label.toLowerCase()}`}
                    value={typed}
                    maxLength={60}
                    className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                    onChange={(e) => {
                        onTyped(e.target.value);
                    }}
                />
            )}
        </section>
    );
}
