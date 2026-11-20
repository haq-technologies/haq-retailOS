import type { Catalogue } from './catalogue';
import type { CustomerType, FacetDefinition, Shape } from './types';

/**
 * The tiles (spec section 6) [D7]: categories, then the category's drill order one facet at a time, then
 * the items. An optional facet has a Skip tile and the item prints without it. A price shows only on the
 * last step, the item tiles. Generated shapes not yet sold are offered alongside the items [R2].
 *
 * A brand is never multiplied into the generated shapes (catalogue v1.1), so the brand step offers brands
 * wherever a shape could still be chosen, as agreed at the shop visit; a shape chosen after a brand becomes
 * that brand's item on its first sale (D54). A shape is never the last answer while its brand is still to
 * ask (D79), and "No brand" is not offered as a brand: an item made with it has none.
 *
 * The brand step shows Skip brand first, then brands A to Z, and the item tiles end with a New tile that
 * opens New item with the category, the brand and the choices so far already filled in (D94). Which brands,
 * and when, follows the shop's own items (D103). In a category with generated shapes the brand is always
 * asked, with the category's own brands from the catalogue and any brand with an item there (the whole list
 * where it has none, as the shop visit agreed). Elsewhere it is asked as any other step is, only when the
 * items left have more than one brand, and it shows only their brands, so a screen protector whose items
 * have no brand goes straight on. There is no More brands: a brand not shown is chosen or typed in New item,
 * on the go, and is shown in that category from then on.
 *
 * Only an item's own tile adds it to the sale, and every step in a category ends with New, as the list
 * does (D102): a step's tile only moves the tiles on, even when it leaves one thing to sell, so the cashier
 * sees the item and its price before it is added and can always make another, from wherever they are.
 * Skip brand shows every brand, and none: an item made with a brand in New item is found after Skip brand
 * too, and a shape sold from there has no brand.
 */
export interface DrillState {
    categoryId: string | null;
    choices: { key: string; value: string | null }[];
}

export type Tile =
    | { kind: 'category'; id: string; label: string }
    | { kind: 'value'; key: string; value: string; label: string }
    | { kind: 'skip'; key: string; label: string; hint: string }
    | { kind: 'item'; id: string; label: string; priceKobo: number | null; shape: Shape | null }
    | { kind: 'new'; id: string; label: string; hint: string; categoryId: string; brandId: string | null; facets: Record<string, string> }
    | { kind: 'jump'; id: string; label: string; hint: string; state: DrillState };

export interface DrillView {
    crumbs: string[];
    prompt: string;
    tiles: Tile[];
}

export const NOT_IN_LIST = '_not_in_list';

/** A generated shape sold under a chosen brand: the shape's id, then the brand (D54). */
export function brandedShapeId(shapeId: string, brandId: string): string {
    return `${shapeId}|brand=${brandId}`;
}

export function parseShapeId(id: string): { shapeId: string; brandId: string | null } {
    const [shapeId = id, brand] = id.split('|brand=');
    return { shapeId, brandId: brand ?? null };
}

/** The key of a category's brand facet, or undefined where it asks no brand. */
export function brandKeyOf(cat: Catalogue, categoryId: string): string | undefined {
    const template = cat.templates.get(categoryId);
    return template?.drill_order.find((key) => template.facets.find((f) => f.key === key)?.type === 'brand') ?? template?.facets.find((f) => f.type === 'brand')?.key;
}

/**
 * Where the tiles stand for a shape found by search: every step but the brand answered as the shape has
 * it, so the brand step shows next and the shape is sold with its brand or with Skip brand (D79). Null
 * where the category asks no brand; the shape is then sold as it is.
 */
export function shapeAtBrandStep(cat: Catalogue, shape: Shape): DrillState | null {
    const template = cat.templates.get(shape.category_id);
    const brandKey = template?.drill_order.find((key) => template.facets.find((f) => f.key === key)?.type === 'brand');
    if (template === undefined || brandKey === undefined) return null;
    return {
        categoryId: shape.category_id,
        choices: template.drill_order.filter((key) => key !== brandKey).map((key) => ({ key, value: shape.facets[key] ?? null })),
    };
}

/**
 * A search result that is a place on the tiles, not an item (D94): a category ("jump:<category>"), or a
 * category's brand ("jump:<category>:<brand>"), which opens the tiles at that brand's step. Null when the
 * category or the brand is no longer there.
 */
export function jumpTile(cat: Catalogue, id: string): (Tile & { kind: 'jump' }) | null {
    const [categoryId = '', brandId] = id.slice('jump:'.length).split(':');
    const category = cat.categories.get(categoryId);
    if (category === undefined) return null;
    if (brandId === undefined) return { kind: 'jump', id, label: category.name, hint: 'Category', state: { categoryId, choices: [] } };
    const brand = cat.brands.get(brandId);
    const brandKey = brandKeyOf(cat, categoryId);
    if (brand === undefined || brand.status === 'merged' || brandKey === undefined) return null;
    return { kind: 'jump', id, label: `${brand.name} · ${category.name}`, hint: 'Brand', state: { categoryId, choices: [{ key: brandKey, value: brandId }] } };
}

interface Candidate {
    id: string;
    label: string;
    shape: Shape | null;
    value: (key: string) => string | null;
}

export function drill(cat: Catalogue, state: DrillState, type: CustomerType): DrillView {
    const crumbs: string[] = [];
    const node = state.categoryId === null ? undefined : cat.categories.get(state.categoryId);
    for (let c = node; c !== undefined; c = c.parent_id === null ? undefined : cat.categories.get(c.parent_id)) {
        crumbs.unshift(c.name);
    }

    const children = [...cat.categories.values()]
        .filter((c) => c.parent_id === (node?.id ?? null) && !c.code.startsWith('_'))
        .sort((a, b) => a.position - b.position || a.name.localeCompare(b.name));
    if (node === undefined || children.length > 0 || !node.sellable) {
        return {
            crumbs,
            prompt: node === undefined ? 'All categories' : `Choose ${node.name.toLowerCase()}`,
            tiles: children.map((c) => ({ kind: 'category', id: c.id, label: c.name })),
        };
    }

    const template = cat.templates.get(node.id);
    const facets = template?.facets ?? [];
    // The pool: every item and every shape the choices so far allow. A shape stays in it under any brand,
    // even when it has been sold with another brand or with none, so it can still be sold under this one.
    // Skip brand keeps every brand (D102).
    let pool = candidatesFor(cat, node.id);
    let brand: string | null = null;
    for (const choice of state.choices) {
        const definition = facets.find((f) => f.key === choice.key);
        if (definition?.type === 'brand' && choice.value !== null) brand = choice.value;
        pool = pool.filter((c) => matches(c, definition, choice.key, choice.value));
        if (choice.value !== null) crumbs.push(valueLabel(cat, definition, choice.value));
        else crumbs.push(definition?.type === 'brand' ? 'Every brand' : `No ${definition?.label.toLowerCase() ?? choice.key}`);
    }
    // What shows now: the pool, with each shape that is already an item under the brand so far shown once.
    const candidates = distinct(pool, facets, brand);

    // The way on when the item is not here: New item, with what the tiles already know (D94). It ends every
    // step in the category, not only the list, so a phone model or a brand not yet sold is made from
    // there (D102).
    const brandName = brand === null ? null : (cat.brands.get(brand)?.name ?? null);
    const known: Record<string, string> = {};
    for (const choice of state.choices) {
        if (choice.value !== null && facets.find((f) => f.key === choice.key)?.type !== 'brand') known[choice.key] = choice.value;
    }
    const word = (node.word ?? node.name).toLowerCase();
    const newTile: Tile = {
        kind: 'new',
        id: 'new-item',
        label: `New ${brandName === null ? '' : `${brandName} `}${word}`,
        hint: 'Not here? Make it',
        categoryId: node.id,
        brandId: brand,
        facets: known,
    };

    const chosen = new Set(state.choices.map((c) => c.key));
    // The brand step. In a category with generated shapes it is always asked, even with no item yet: a shape
    // waits for its brand, and with a brand it is another item (D79, D94). Elsewhere it is asked as any other
    // step is, only when the items left have more than one brand (D103).
    const generated = cat.generates(node.id);
    const brandKey = template?.drill_order.find((key) => facets.find((f) => f.key === key)?.type === 'brand');
    const brandOpen = generated && brandKey !== undefined && !chosen.has(brandKey);
    if (template !== undefined && (candidates.length > 1 || brandOpen)) {
        for (const key of template.drill_order) {
            if (chosen.has(key)) continue;
            const definition = template.facets.find((f) => f.key === key);
            const isBrand = definition?.type === 'brand';
            const values = new Set<string>();
            let blanks = 0;
            for (const c of candidates) {
                const v = c.value(key);
                if (v === null) blanks++;
                else values.add(v);
            }

            // A step's tile only moves the tiles on, even when it leaves one thing to sell: the item is added
            // from its own tile, in the list that ends with New (D102).
            const valueTile = (v: string, text: string): Tile => ({ kind: 'value', key, value: v, label: text });

            if (isBrand && generated) {
                // The category's own brands, and any brand with an item there; with none, the whole list (C8).
                const offered = cat.brandsAtTiles(node.id);
                for (const id of values) offered.add(id);
                if (offered.size === 0) {
                    for (const b of cat.brands.values()) if (b.status === 'approved' && b.id !== cat.noBrandId) offered.add(b.id);
                }
                return { crumbs, prompt: 'Choose brand', tiles: [...brandStep(cat, key, offered, valueTile), newTile] };
            }

            // One value and no blanks, or nothing at all: there is nothing to choose at this step. Outside the
            // generated categories the brand is such a step: one brand, or none, is not asked (D103).
            if (values.size === 0 || (values.size === 1 && blanks === 0)) continue;

            if (isBrand) {
                // Only the brands of the items left, so every brand tile leads to something to sell (D103).
                return { crumbs, prompt: 'Choose brand', tiles: [...brandStep(cat, key, values, valueTile), newTile] };
            }

            const label = definition?.label ?? key;
            const tiles: Tile[] = [...values]
                .map((v) => ({ v, label: valueLabel(cat, definition, v), order: valueOrder(cat, definition, v) }))
                .sort((a, b) => a.order - b.order || a.label.localeCompare(b.label))
                .map(({ v, label: text }) => valueTile(v, text));
            if (blanks > 0) {
                tiles.push({ kind: 'skip', key, label: `Skip ${label.toLowerCase()}`, hint: `Prints without a ${label.toLowerCase()}` });
            }
            return { crumbs, prompt: `Choose ${label.toLowerCase()}`, tiles: [...tiles, newTile] };
        }
    }

    const items: Tile[] = candidates
        .map((c) => {
            const price = c.shape === null ? cat.priceFor(c.id, type) : null;
            if (c.shape !== null && brand !== null && brandName !== null) {
                return { kind: 'item' as const, id: brandedShapeId(c.id, brand), label: `${brandName} ${c.label}`, priceKobo: null, shape: c.shape };
            }
            return { kind: 'item' as const, id: c.id, label: c.label, priceKobo: price === null ? null : (price.setPrice ?? price.prefill), shape: c.shape };
        })
        .sort((a, b) => a.label.localeCompare(b.label));
    items.push(newTile);
    return {
        crumbs,
        prompt: items.length === 1 ? 'None yet. Make one with New.' : 'Choose the item',
        tiles: items,
    };
}

/**
 * The brand step's tiles: Skip brand first, which shows every brand (D102), then the brands offered, A to Z
 * (D94). There is no More brands: a brand not offered is chosen or typed in New item, and its item makes it
 * one of the category's brands from then on (D103).
 */
function brandStep(cat: Catalogue, key: string, brands: Set<string>, valueTile: (value: string, label: string) => Tile): Tile[] {
    const byName = [...brands]
        .map((id) => ({ id, label: cat.brands.get(id)?.name ?? id }))
        .sort((a, b) => a.label.localeCompare(b.label))
        .map(({ id, label }) => valueTile(id, label));
    return [{ kind: 'skip', key, label: 'Skip brand', hint: 'Shows every brand' }, ...byName];
}

/**
 * Whether a candidate stays after a choice. Skip brand keeps every brand, and none (D102). A shape has no
 * brand of its own, so it stays under any brand chosen.
 */
function matches(c: Candidate, definition: FacetDefinition | undefined, key: string, value: string | null): boolean {
    if (definition?.type === 'brand') return value === null || c.shape !== null || c.value(key) === value;
    return c.value(key) === value;
}

/**
 * Drops each shape that is already an item: under the brand chosen (none after Skip brand), a shape whose
 * values are exactly an item's, with no facet of its own beyond them, prints the same name and is the same
 * item, so it shows once, as the item with its price (D54).
 */
function distinct(candidates: Candidate[], facets: FacetDefinition[], brand: string | null): Candidate[] {
    const items = candidates.filter((c) => c.shape === null);
    return candidates.filter(
        (c) =>
            c.shape === null ||
            !items.some((item) =>
                facets.every((f) => item.value(f.key) === (f.type === 'brand' ? brand : (c.shape?.facets[f.key] ?? null))),
            ),
    );
}

/**
 * A category's items and all its shapes. A shape already sold stays: distinct() shows it once, as its item,
 * under the brand it was sold with, and it is still there to sell under another brand.
 */
function candidatesFor(cat: Catalogue, categoryId: string): Candidate[] {
    const out: Candidate[] = [];
    for (const item of cat.items.values()) {
        if (item.category_id !== categoryId || !cat.sellable(item)) continue;
        out.push({ id: item.id, label: item.display_name, shape: null, value: (key) => cat.facetValue(item.id, key) });
    }
    for (const shape of cat.shapes) {
        if (shape.category_id !== categoryId) continue;
        out.push({ id: shape.id, label: shape.display_name, shape, value: (key) => (key === 'brand' ? null : (shape.facets[key] ?? null)) });
    }
    return out;
}

function valueLabel(cat: Catalogue, definition: FacetDefinition | undefined, value: string): string {
    if (definition?.type === 'brand') return cat.brands.get(value)?.name ?? value;
    if (definition?.type === 'picklist') return cat.picklist.get(value)?.name ?? value;
    return value;
}

function valueOrder(cat: Catalogue, definition: FacetDefinition | undefined, value: string): number {
    if (definition?.type === 'picklist') return cat.picklist.get(value)?.position ?? 0;
    return 0;
}
