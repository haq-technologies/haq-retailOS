import { useMemo, useState } from 'react';
import { Keypad } from '@/components/keypad';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import type { Catalogue } from '../catalogue';
import { digitsToKobo, naira, typeDigits } from '../money';
import type { BrandRow, CategoryRow, CustomerType, FacetDefinition, ItemFacetRow, ItemPrices, ItemRow, ProductModelRow } from '../types';
import type { CounterApi } from '../use-api';

/**
 * New item at the counter (spec section 6, guardrails) [F11, F20, C2]: the category first, the facets from
 * pickers, a typed brand or phone model that prints as typed and waits for approval, "Not in the list" for
 * a product word, similar items offered first, and the price. A cashier's new item sells at once and waits
 * in the owner's review queue. Wireframe: NewItem.
 *
 * The layout follows the screen (D80). The category is its own first screen. Then, from 600px, two panes:
 * the details on the left, scrolling by themselves, and on the right the printed name, the price, the
 * keypad and Add, always in view, so nothing is reached by a long scroll. Under 600px the same two parts
 * are two steps, Details then Price.
 *
 * What it asks follows the catalogue (D93). The brand step takes the template's own label ("Made by" on
 * pouches, protectors and batteries) and its default (No brand there). It shows the category's own brands
 * first, then Other brands, and typing finds any listed brand. "No brand" is offered only where it is the
 * default; elsewhere choosing none is the same answer. Phone models are chosen by family, and the seeded
 * product models are offered under Model. A code scanned before New item is learned for the new item.
 * Changing the category clears the details, and closing with something chosen asks first.
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
    customerType: CustomerType;
    startCategoryId: string | null;
    /** From the tiles' New tile: the brand and the choices made there, already filled in (D94). */
    startBrandId?: string | null;
    startFacets?: Record<string, string>;
    /** A code scanned before New item opened: the server learns it for the new item (D93). */
    learnCode: string | null;
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
const FIRST_BRANDS = 8;

export function NewItem({ cat, api, requestOnly, customerType, startCategoryId, startBrandId = null, startFacets = {}, learnCode, onCreated, onPickExisting, onClose }: Props) {
    const start = startCategoryId === null ? undefined : cat.categories.get(startCategoryId);
    const defaultBrand = (c: CategoryRow | null): string | null => (c === null ? null : (cat.brandDefaults.get(c.id) ?? null));
    const startCategory = start?.sellable === true ? start : null;
    const [category, setCategory] = useState<CategoryRow | null>(startCategory);
    const [browse, setBrowse] = useState<string | null>(start !== undefined && !start.sellable ? start.id : null);
    const [notInList, setNotInList] = useState(false);
    const [word, setWord] = useState('');
    // What the form starts from: the category's default brand, or what the tiles already know (D94).
    const startBrand = startCategory === null ? null : (startBrandId ?? defaultBrand(startCategory));
    const startDetails = startCategory === null ? {} : startFacets;
    const [brandId, setBrandId] = useState<string | null>(startBrand);
    const [brandText, setBrandText] = useState('');
    const [facets, setFacets] = useState<Record<string, string>>(startDetails);
    const [families, setFamilies] = useState<Record<string, string>>({});
    const [phoneModelText, setPhoneModelText] = useState('');
    const [freeText, setFreeText] = useState('');
    const [more, setMore] = useState<string | null>(null);
    const [otherBrands, setOtherBrands] = useState(false);
    const [digits, setDigits] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [similar, setSimilar] = useState<{ id: string; name: string }[]>([]);
    const [confirmClose, setConfirmClose] = useState(false);
    // A phone shows the form in two steps; a counter or a tablet shows both at once, side by side (D80).
    const [step, setStep] = useState<'details' | 'price'>('details');

    const template = category === null ? undefined : cat.templates.get(category.id);
    const fields = (template?.facets ?? []).filter((f) => f.type !== 'brand');
    const brandFacet = template?.facets.find((f) => f.type === 'brand');
    const brandLabel = brandFacet?.label ?? 'Brand';
    const noBrandOffered = category !== null && defaultBrand(category) !== null && cat.noBrandId !== null;

    /** Everything chosen or typed goes with a new category, and its default brand comes back (D93). */
    const resetDetails = (next: CategoryRow | null) => {
        setBrandId(defaultBrand(next));
        setBrandText('');
        setFacets({});
        setFamilies({});
        setPhoneModelText('');
        setFreeText('');
        setWord('');
        setMore(null);
        setOtherBrands(false);
        setError(null);
        setSimilar([]);
    };

    // The listed brands, A to Z, without "No brand", which is offered only where it is the default.
    const listed = useMemo(() => [...cat.brands.values()].filter((b) => b.status !== 'merged' && b.id !== cat.noBrandId).sort((a, b) => a.name.localeCompare(b.name)), [cat]);
    const own = useMemo(() => (category === null ? new Set<string>() : cat.brandsOf(category.id)), [cat, category]);
    const ownBrands = listed.filter((b) => own.has(b.id));
    const otherList = listed.filter((b) => !own.has(b.id));
    // A category with no brands of its own shows the whole list, as the shop visit agreed.
    const firstList = ownBrands.length > 0 ? ownBrands : otherList;
    const restList = ownBrands.length > 0 ? otherList : [];
    const needle = brandText.trim().toLowerCase();
    const matching = needle === '' ? [] : listed.filter((b) => b.name.toLowerCase().includes(needle) || b.aliases.some((a) => a.toLowerCase().includes(needle))).sort((a, b) => Number(own.has(b.id)) - Number(own.has(a.id))).slice(0, FIRST_BRANDS);
    const typedBrandKnown = needle !== '' && listed.some((b) => b.name.toLowerCase() === needle);

    // The seeded product models for this category, of the brand chosen, or with no brand of their own.
    const models = useMemo(
        () =>
            category === null
                ? []
                : [...cat.models.values()]
                      .filter((m) => m.status === 'active' && m.categories.includes(category.code) && (brandId === null || m.brand_id === null || m.brand_id === brandId))
                      .sort((a, b) => a.name.localeCompare(b.name)),
        [cat, category, brandId],
    );

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

    // Changed from where the form started: the category's defaults, or what the tiles filled in.
    const atStart = category !== null && category.id === startCategory?.id;
    const details = (x: Record<string, string>) => JSON.stringify(Object.entries(x).filter(([, v]) => v !== '').sort(([a], [b]) => a.localeCompare(b)));
    const dirty =
        word.trim() !== '' ||
        brandText.trim() !== '' ||
        brandId !== (atStart ? startBrand : defaultBrand(category)) ||
        details(facets) !== details(atStart ? startDetails : {}) ||
        phoneModelText.trim() !== '' ||
        freeText.trim() !== '' ||
        digits !== '';
    // Cancel or Esc with something chosen asks first; Esc on that question goes back to the form (D93).
    const requestClose = () => {
        if (dirty) setConfirmClose(true);
        else onClose();
    };

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
                barcode: learnCode,
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

    const chosen = category !== null || notInList;
    const printsAs = preview !== '' && (
        <p className="rounded-xl bg-panel p-4 text-text-secondary">
            Prints as <strong className="block text-xl text-text">{preview}</strong>
        </p>
    );

    const brandChip = (b: BrandRow) => (
        <button
            key={b.id}
            type="button"
            aria-pressed={brandId === b.id}
            className={`min-h-touch rounded-xl border-2 px-4 ${brandId === b.id ? 'border-action font-semibold' : 'border-border'}`}
            onClick={() => {
                setBrandId(brandId === b.id ? defaultBrand(category) : b.id);
                setBrandText('');
            }}
        >
            {b.name}
        </button>
    );
    const shownFirst = more === 'brand' ? firstList : firstList.slice(0, FIRST_BRANDS);
    // The brand chosen stays in view, wherever it is in the list.
    const chosenBrand = brandId === null || brandId === cat.noBrandId ? undefined : cat.brands.get(brandId);
    const chosenHidden = chosenBrand !== undefined && !shownFirst.some((b) => b.id === chosenBrand.id) && !(otherBrands && restList.some((b) => b.id === chosenBrand.id));

    return (
        <Sheet title="New item" onClose={confirmClose ? () => { setConfirmClose(false); } : requestClose} wide={!chosen} full={chosen} ownScroll={chosen}>
            {confirmClose && (
                <section aria-label="Discard the new item" className="flex flex-col gap-4 px-4 pb-4 medium:px-6 medium:pb-6">
                    <p className="text-xl font-semibold">Discard this new item?</p>
                    <p className="text-text-secondary">What you chose and typed for it is lost. The sale stays as it is.</p>
                    <div className="flex flex-wrap gap-3">
                        <button
                            type="button"
                            autoFocus
                            className="min-h-touch rounded-xl bg-action px-6 text-lg font-bold text-white"
                            onClick={() => {
                                setConfirmClose(false);
                            }}
                        >
                            Keep editing
                        </button>
                        <button type="button" className="min-h-touch rounded-xl border-2 border-destructive px-6 text-lg font-semibold text-destructive" onClick={onClose}>
                            Discard
                        </button>
                    </div>
                </section>
            )}

            {!confirmClose && !chosen && (
                <>
                    <p className="text-text-secondary">Sellable now; the owner reviews it later.</p>
                    {learnCode !== null && <LearnNote code={learnCode} />}
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
                        <div className="grid grid-cols-2 gap-2 medium:grid-cols-3 large:grid-cols-4">
                            {categories.map((c) => (
                                <button
                                    key={c.id}
                                    type="button"
                                    className="min-h-touch rounded-xl border-2 border-border px-3 py-2 text-left text-lg"
                                    onClick={() => {
                                        const children = [...cat.categories.values()].some((x) => x.parent_id === c.id && !x.code.startsWith('_'));
                                        if (children || !c.sellable) {
                                            setBrowse(c.id);
                                        } else {
                                            resetDetails(c);
                                            setCategory(c);
                                        }
                                    }}
                                >
                                    {c.name}
                                </button>
                            ))}
                            <button
                                type="button"
                                className="min-h-touch rounded-xl border-2 border-dashed border-border px-3 py-2 text-left text-lg"
                                onClick={() => {
                                    resetDetails(null);
                                    setNotInList(true);
                                }}
                            >
                                Not in the list
                            </button>
                        </div>
                    </section>
                </>
            )}

            {!confirmClose && chosen && (
                <div className="flex min-h-0 flex-1 flex-col medium:grid medium:grid-cols-[minmax(0,1fr)_340px] medium:grid-rows-[minmax(0,1fr)] large:grid-cols-[minmax(0,1fr)_400px]">
                    {/* The details: on a counter the left pane, scrolling by itself; on a phone the first step. */}
                    <section
                        aria-label="Details"
                        className={`${step === 'details' ? 'flex' : 'hidden'} min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pb-4 medium:flex medium:px-6 medium:pb-6`}
                    >
                        <p className="text-text-secondary">Sellable now; the owner reviews it later.</p>
                        {learnCode !== null && <LearnNote code={learnCode} />}
                        <p className="flex flex-wrap items-center gap-3 text-lg">
                            Category <strong>{notInList ? 'Not in the list' : category?.name}</strong>
                            <button
                                type="button"
                                className="min-h-touch rounded-xl border-2 border-border px-4"
                                onClick={() => {
                                    resetDetails(null);
                                    setCategory(null);
                                    setNotInList(false);
                                    setStep('details');
                                }}
                            >
                                Change
                            </button>
                        </p>

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

                        {category !== null && !requestOnly && brandFacet !== undefined && (
                            <section aria-label={brandLabel} className="flex flex-col gap-2">
                                <p className="text-lg font-semibold">{brandLabel}</p>
                                <div className="flex flex-wrap gap-2">
                                    {needle !== '' ? (
                                        matching.map(brandChip)
                                    ) : (
                                        <>
                                            {noBrandOffered && cat.noBrandId !== null && (
                                                <button
                                                    type="button"
                                                    aria-pressed={brandId === cat.noBrandId}
                                                    className={`min-h-touch rounded-xl border-2 px-4 ${brandId === cat.noBrandId ? 'border-action font-semibold' : 'border-border'}`}
                                                    onClick={() => {
                                                        setBrandId(cat.noBrandId);
                                                        setBrandText('');
                                                    }}
                                                >
                                                    No brand
                                                </button>
                                            )}
                                            {chosenHidden && brandChip(chosenBrand)}
                                            {shownFirst.map(brandChip)}
                                            {more !== 'brand' && firstList.length > FIRST_BRANDS && (
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
                                            {restList.length > 0 && !otherBrands && (
                                                <button
                                                    type="button"
                                                    className="min-h-touch rounded-xl border-2 border-dashed border-border px-4"
                                                    onClick={() => {
                                                        setOtherBrands(true);
                                                    }}
                                                >
                                                    Other brands
                                                </button>
                                            )}
                                        </>
                                    )}
                                </div>
                                {needle === '' && otherBrands && restList.length > 0 && (
                                    <div role="group" aria-label="Other brands" className="flex flex-col gap-2">
                                        <p className="text-text-secondary">Other brands</p>
                                        <div className="flex flex-wrap gap-2">{restList.map(brandChip)}</div>
                                    </div>
                                )}
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
                                {needle !== '' && !typedBrandKnown && (
                                    <p className="text-text-secondary">
                                        {matching.length > 0 ? 'Tap the brand if it is one of these. Otherwise ' : ''}
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
                                    family={families[f.key] ?? null}
                                    suggestions={f.key === 'model' ? models : []}
                                    onShowAll={() => {
                                        setMore(f.key);
                                    }}
                                    onFamily={(family) => {
                                        setFamilies((x) => ({ ...x, [f.key]: family ?? '' }));
                                    }}
                                    onChange={(v) => {
                                        setFacets((x) => ({ ...x, [f.key]: v }));
                                        if (f.key === 'phone_model') setPhoneModelText('');
                                    }}
                                    onTyped={(t) => {
                                        setPhoneModelText(t);
                                        setFacets((x) => ({ ...x, phone_model: '' }));
                                    }}
                                    onSuggest={(m) => {
                                        setFacets((x) => ({ ...x, [f.key]: m.name }));
                                        // A model of one brand brings its brand, when none is chosen yet.
                                        if (m.brand_id !== null && brandId === defaultBrand(category) && brandText.trim() === '') setBrandId(m.brand_id);
                                    }}
                                />
                            ))}

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
                    </section>

                    {/* A phone only: the name so far, and the way on to the price. */}
                    {step === 'details' && (
                        <div className="flex shrink-0 flex-col gap-3 border-t border-border p-4 medium:hidden">
                            {printsAs}
                            <button
                                type="button"
                                className="min-h-touch rounded-xl bg-action text-lg font-bold text-white"
                                onClick={() => {
                                    setStep('price');
                                }}
                            >
                                Next: the price
                            </button>
                        </div>
                    )}

                    {/* The name, the price and Add: on a counter the right pane, always in view; on a phone the second step. */}
                    <div
                        role="group"
                        aria-label="Name and price"
                        className={`${step === 'price' ? 'flex' : 'hidden'} min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-4 pb-4 medium:flex medium:border-l medium:border-border medium:px-6 medium:pb-6`}
                    >
                        <button
                            type="button"
                            className="min-h-touch self-start rounded-xl border-2 border-border px-4 medium:hidden"
                            onClick={() => {
                                setStep('details');
                            }}
                        >
                            Back to the details
                        </button>
                        {printsAs}

                        <section aria-label="Price" className="flex flex-col gap-2">
                            {/* The price is for the sale's customer type, as the toggle in the top bar says (D8, D93). */}
                            <p className="text-lg font-semibold">{customerType === 'wholesale' ? 'Wholesale price' : 'Retail price'}</p>
                            <output className="flex min-h-touch items-center rounded-xl border-2 border-action px-4 text-3xl font-bold">
                                {digits === '' ? <span className="text-text-secondary">₦</span> : naira(digitsToKobo(digits))}
                            </output>
                            <Keypad
                                onKey={(key) => {
                                    setDigits((d) => typeDigits(d, key));
                                }}
                            />
                        </section>

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

                        {similar.length === 0 && (
                            <button
                                type="button"
                                disabled={busy || digits === ''}
                                className="min-h-touch shrink-0 rounded-xl bg-action text-lg font-bold text-white disabled:opacity-60"
                                onClick={() => void send(false)}
                            >
                                {digits === '' ? 'Type the price' : `Add at ${naira(digitsToKobo(digits))}`}
                            </button>
                        )}
                    </div>
                </div>
            )}
        </Sheet>
    );
}

/** The scanned code New item keeps for the item it makes (D93). */
function LearnNote({ code }: { code: string }) {
    return (
        <p role="status" className="rounded-xl border-2 border-action px-4 py-3 text-lg">
            Code <strong>{code}</strong> is kept for this item, so the next scan adds it at once.
        </p>
    );
}

interface FieldProps {
    cat: Catalogue;
    definition: FacetDefinition;
    value: string;
    typed: string;
    showAll: boolean;
    /** The family chosen, for a list chosen by family such as the phone models. */
    family: string | null;
    /** Product models to offer under a typed Model. */
    suggestions: ProductModelRow[];
    onShowAll: () => void;
    onFamily: (family: string | null) => void;
    onChange: (value: string) => void;
    onTyped: (text: string) => void;
    onSuggest: (model: ProductModelRow) => void;
}

function FacetField({ cat, definition, value, typed, showAll, family, suggestions, onShowAll, onFamily, onChange, onTyped, onSuggest }: FieldProps) {
    if (definition.type === 'text') {
        return (
            <div className="flex flex-col gap-2">
                <label className="flex flex-col gap-1 text-lg">
                    <span>
                        {definition.label} <span className="text-text-secondary">· optional</span>
                    </span>
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
                {suggestions.length > 0 && (
                    <div role="group" aria-label={`Listed ${definition.label.toLowerCase()}s`} className="flex flex-wrap gap-2">
                        {suggestions.map((m) => (
                            <button
                                key={m.id}
                                type="button"
                                aria-pressed={value === m.name}
                                className={`min-h-touch rounded-xl border-2 px-4 ${value === m.name ? 'border-action font-semibold' : 'border-border'}`}
                                onClick={() => {
                                    onSuggest(m);
                                }}
                            >
                                {m.name}
                            </button>
                        ))}
                    </div>
                )}
            </div>
        );
    }
    const values = [...cat.picklist.values()]
        .filter((v) => v.list === definition.list && v.status !== 'archived')
        .sort((a, b) => a.position - b.position || a.name.localeCompare(b.name));
    // A long list (phone models) narrows as the cashier types, or is chosen by its family first (D93).
    const needle = typed.trim().toLowerCase();
    const groups =
        definition.group_by === undefined
            ? []
            : [...cat.picklist.values()].filter((v) => v.list === definition.group_by && v.status !== 'archived').sort((a, b) => a.position - b.position || a.name.localeCompare(b.name));
    const byFamily = groups.length > 0;
    let shown = needle !== '' ? values.filter((v) => v.name.toLowerCase().includes(needle)).slice(0, 10) : byFamily ? values.filter((v) => family !== null && family !== '' && v.parent_id === family) : showAll ? values : values.slice(0, 5);
    // The value chosen stays in view, whichever family is open.
    const picked = value === '' ? undefined : cat.picklist.get(value);
    if (picked !== undefined && !shown.some((v) => v.id === picked.id)) shown = [picked, ...shown];
    return (
        <section aria-label={definition.label} className="flex flex-col gap-2">
            <p className="text-lg font-semibold">{definition.label}</p>
            {byFamily && needle === '' && (
                <div role="group" aria-label={`${definition.label} families`} className="flex flex-wrap gap-2">
                    {groups.map((g) => (
                        <button
                            key={g.id}
                            type="button"
                            aria-pressed={family === g.id}
                            className={`min-h-touch rounded-xl border-2 px-4 ${family === g.id ? 'border-action font-semibold' : 'border-dashed border-border'}`}
                            onClick={() => {
                                onFamily(family === g.id ? null : g.id);
                            }}
                        >
                            {g.name}
                        </button>
                    ))}
                </div>
            )}
            {shown.length > 0 && (
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
                    {!byFamily && !showAll && needle === '' && values.length > 5 && (
                        <button type="button" className="min-h-touch rounded-xl border-2 border-border px-4" onClick={onShowAll}>
                            More
                        </button>
                    )}
                </div>
            )}
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
