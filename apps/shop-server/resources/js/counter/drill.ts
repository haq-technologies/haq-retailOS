import type { Catalogue } from './catalogue';
import type { CustomerType, FacetDefinition, Shape } from './types';

/**
 * The tiles (spec section 6) [D7]: categories, then the category's drill order one facet at a time, then
 * the items. An optional facet has a Skip tile and the item prints without it. A price shows only on the
 * last step, the item tiles. Generated shapes not yet sold are offered alongside the items [R2].
 */
export interface DrillState {
    categoryId: string | null;
    choices: { key: string; value: string | null }[];
}

export type Tile =
    | { kind: 'category'; id: string; label: string }
    | { kind: 'value'; key: string; value: string; label: string; itemId: string | null; priceKobo: number | null }
    | { kind: 'skip'; key: string; label: string; hint: string }
    | { kind: 'item'; id: string; label: string; priceKobo: number | null; shape: Shape | null };

export interface DrillView {
    crumbs: string[];
    prompt: string;
    tiles: Tile[];
}

export const NOT_IN_LIST = '_not_in_list';

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
    let candidates = candidatesFor(cat, node.id);
    for (const choice of state.choices) {
        candidates = candidates.filter((c) => c.value(choice.key) === choice.value);
        const definition = template?.facets.find((f) => f.key === choice.key);
        crumbs.push(choice.value === null ? `No ${definition?.label.toLowerCase() ?? choice.key}` : valueLabel(cat, definition, choice.value));
    }

    const chosen = new Set(state.choices.map((c) => c.key));
    if (template !== undefined && candidates.length > 1) {
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
            // One value and no blanks, or nothing at all: there is nothing to choose at this step.
            if (values.size === 0 || (values.size === 1 && blanks === 0)) continue;

            const label = definition?.label ?? key;
            // A value that leaves one item is that item: the tile shows its price and adds it in one tap.
            const tiles: Tile[] = [...values]
                .map((v) => ({ v, label: valueLabel(cat, definition, v), order: valueOrder(cat, definition, v, node.code) }))
                .sort((a, b) => a.order - b.order || a.label.localeCompare(b.label))
                .map(({ v, label: text }) => {
                    const left = candidates.filter((c) => c.value(key) === v);
                    const only = left.length === 1 ? left[0] : undefined;
                    const price = only === undefined || only.shape !== null ? null : cat.priceFor(only.id, type);
                    return { kind: 'value' as const, key, value: v, label: text, itemId: only?.id ?? null, priceKobo: price === null ? null : (price.setPrice ?? price.prefill) };
                });
            if (blanks > 0) {
                tiles.push({ kind: 'skip', key, label: `Skip ${label.toLowerCase()}`, hint: `Prints without a ${label.toLowerCase()}` });
            }
            return { crumbs, prompt: `Choose ${label.toLowerCase()}`, tiles };
        }
    }

    return {
        crumbs,
        prompt: candidates.length === 0 ? 'Nothing here yet. Use New item.' : 'Choose the item',
        tiles: candidates
            .map((c) => {
                const price = c.shape === null ? cat.priceFor(c.id, type) : null;
                return { kind: 'item' as const, id: c.id, label: c.label, priceKobo: price === null ? null : (price.setPrice ?? price.prefill), shape: c.shape };
            })
            .sort((a, b) => a.label.localeCompare(b.label)),
    };
}

function candidatesFor(cat: Catalogue, categoryId: string): Candidate[] {
    const out: Candidate[] = [];
    const taken = new Set<string>();
    for (const item of cat.items.values()) {
        if (item.category_id !== categoryId || !cat.sellable(item)) continue;
        out.push({ id: item.id, label: item.display_name, shape: null, value: (key) => cat.facetValue(item.id, key) });
        taken.add(item.display_name.toLowerCase());
    }
    for (const shape of cat.shapes) {
        if (shape.category_id !== categoryId || taken.has(shape.display_name.toLowerCase())) continue;
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
