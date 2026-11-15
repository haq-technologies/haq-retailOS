import type { Catalogue } from './catalogue';
import type { CustomerType, FacetDefinition, Shape } from './types';

/**
 * The tiles (spec section 6) [D7]: categories, then the category's drill order one facet at a time, then
 * the items. An optional facet has a Skip tile and the item prints without it. A price shows only on the
 * last step, the item tiles. Generated shapes not yet sold are offered alongside the items [R2].
 *
 * A brand is never multiplied into the generated shapes (catalogue v1.1), so the brand step offers the full
 * brand list wherever a shape could still be chosen, as agreed at the shop visit; a shape chosen after a
 * brand becomes that brand's item on its first sale (D54).
 *
 * A shape is never the last answer while its brand is still to ask (D79). Until then a category that asks
 * the brand after its other steps never reached the brand step: one shape was left, and the one-tap rule
 * (D34) added it with no brand. Now the brand step comes first, with every listed brand and Skip brand, and
 * Skip adds the item with no brand in one tap. "No brand" in the brand list is the same answer as Skip, so
 * it is not offered as a brand.
 */
export interface DrillState {
    categoryId: string | null;
    choices: { key: string; value: string | null }[];
}

export type Tile =
    | { kind: 'category'; id: string; label: string }
    | { kind: 'value'; key: string; value: string; label: string; itemId: string | null; priceKobo: number | null }
    | { kind: 'skip'; key: string; label: string; hint: string; itemId: string | null }
    | { kind: 'item'; id: string; label: string; priceKobo: number | null; shape: Shape | null };

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
    let pool = candidatesFor(cat, node.id);
    let brand: string | null = null;
    for (const choice of state.choices) {
        const definition = facets.find((f) => f.key === choice.key);
        if (definition?.type === 'brand' && choice.value !== null) brand = choice.value;
        pool = pool.filter((c) => matches(c, definition, choice.key, choice.value));
        crumbs.push(choice.value === null ? `No ${definition?.label.toLowerCase() ?? choice.key}` : valueLabel(cat, definition, choice.value));
    }
    // What shows now: the pool, with each shape that is already an item under the brand so far shown once.
    const candidates = distinct(pool, facets, brand);

    const chosen = new Set(state.choices.map((c) => c.key));
    // The brand step, while it is still to ask. A shape waits for it: with a brand it is another item (D79).
    const brandKey = template?.drill_order.find((key) => facets.find((f) => f.key === key)?.type === 'brand');
    const brandOpen = brandKey !== undefined && !chosen.has(brandKey);
    const shapeInPool = pool.some((c) => c.shape !== null);
    const shapeWaits = brandOpen && shapeInPool;
    if (template !== undefined && (candidates.length > 1 || shapeWaits)) {
        for (const key of template.drill_order) {
            if (chosen.has(key)) continue;
            const definition = template.facets.find((f) => f.key === key);
            const values = new Set<string>();
            let blanks = 0;
            for (const c of candidates) {
                const v = c.value(key);
                if (v === null) blanks++;
                else values.add(v);
            }
            // While a shape could still be chosen, every listed brand is offered, not only those already sold.
            if (definition?.type === 'brand' && shapeInPool) {
                for (const b of cat.brands.values()) {
                    if (b.status === 'approved' && b.id !== cat.noBrandId) values.add(b.id);
                }
            }
            // One value and no blanks, or nothing at all: there is nothing to choose at this step.
            if (values.size === 0 || (values.size === 1 && blanks === 0)) continue;

            const label = definition?.label ?? key;
            const isBrand = definition?.type === 'brand';
            // What a tile leaves is worked out as the next step would: under a brand, the shapes not yet sold
            // as that brand's items stay too, so a brand with one item sold still offers the rest (a power
            // bank's other capacities, a cable's other lengths). A tile that leaves one thing to sell is that
            // thing: it adds it in one tap. A shape is not yet a thing to sell while its brand is to ask.
            const leaves = (value: string | null): { itemId: string | null; priceKobo: number | null } => {
                const brandAfter = isBrand ? value : brand;
                const left = distinct(
                    pool.filter((c) => matches(c, definition, key, value)),
                    facets,
                    brandAfter,
                );
                const only = left.length === 1 ? left[0] : undefined;
                if (only === undefined || (only.shape !== null && brandOpen && !isBrand)) return { itemId: null, priceKobo: null };
                if (only.shape !== null) {
                    // A shape left after a brand was chosen is added as that brand's item (D54).
                    return { itemId: brandAfter !== null ? brandedShapeId(only.id, brandAfter) : only.id, priceKobo: null };
                }
                const price = cat.priceFor(only.id, type);
                return { itemId: only.id, priceKobo: price.setPrice ?? price.prefill };
            };
            const tiles: Tile[] = [...values]
                .map((v) => ({ v, label: valueLabel(cat, definition, v), order: valueOrder(cat, definition, v, node.code) }))
                .sort((a, b) => a.order - b.order || a.label.localeCompare(b.label))
                .map(({ v, label: text }) => ({ kind: 'value' as const, key, value: v, label: text, ...leaves(v) }));
            if (blanks > 0) {
                tiles.push({ kind: 'skip', key, label: `Skip ${label.toLowerCase()}`, hint: `Prints without a ${label.toLowerCase()}`, itemId: leaves(null).itemId });
            }
            return { crumbs, prompt: `Choose ${label.toLowerCase()}`, tiles };
        }
    }

    const brandName = brand === null ? null : (cat.brands.get(brand)?.name ?? null);
    const items = candidates
        .map((c) => {
            const price = c.shape === null ? cat.priceFor(c.id, type) : null;
            if (c.shape !== null && brand !== null && brandName !== null) {
                return { kind: 'item' as const, id: brandedShapeId(c.id, brand), label: `${brandName} ${c.label}`, priceKobo: null, shape: c.shape };
            }
            return { kind: 'item' as const, id: c.id, label: c.label, priceKobo: price === null ? null : (price.setPrice ?? price.prefill), shape: c.shape };
        });
    return {
        crumbs,
        prompt: items.length === 0 ? 'Nothing here yet. Use New item.' : 'Choose the item',
        tiles: items.sort((a, b) => a.label.localeCompare(b.label)),
    };
}

/** Whether a candidate stays after a choice. A shape has no brand of its own, so it stays under any brand chosen. */
function matches(c: Candidate, definition: FacetDefinition | undefined, key: string, value: string | null): boolean {
    return c.value(key) === value || (definition?.type === 'brand' && value !== null && c.shape !== null);
}

/**
 * Drops each shape that is already an item: under the brand chosen (or none), a shape whose values are
 * exactly an item's, with no facet of its own beyond them, prints the same name and is the same item, so it
 * shows once, as the item with its price (D54).
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

function valueOrder(cat: Catalogue, definition: FacetDefinition | undefined, value: string, categoryCode: string): number {
    if (definition?.type === 'picklist') return cat.picklist.get(value)?.position ?? 0;
    if (definition?.type === 'brand') return cat.brands.get(value)?.shown_first_in.includes(categoryCode) === true ? -1 : 0;
    return 0;
}
