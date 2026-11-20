import type { Catalogue } from './catalogue';
import type { SearchDoc } from './search';
import type { CategoryRow } from './types';

/**
 * What search indexes for each item and each generated shape (spec section 7) [S10, R2], and the places on
 * the tiles (D94): each category, and each brand the tiles offer in it (D103), so "earbuds" or "oraimo"
 * finds where to go. Places rank below items (search.ts) and are listed after them. A brand learned from a
 * new item becomes a place as the item is indexed, not only at the next full rebuild.
 */
export function searchDocs(cat: Catalogue, ids: Set<string> | null): { upsert: SearchDoc[]; remove: string[] } {
    const upsert: SearchDoc[] = [];
    const remove: string[] = [];
    const place = (category: CategoryRow, brandId: string): SearchDoc | null => {
        const brand = cat.brands.get(brandId);
        if (brand === undefined) return null;
        return { id: `jump:${category.id}:${brandId}`, name: `${brand.name} ${category.name}`, text: [...brand.aliases, category.word ?? '', ...category.aliases].join(' '), sold: 0 };
    };
    const names = new Set<string>();
    for (const item of cat.items.values()) {
        names.add(`${item.category_id}|${item.display_name.toLowerCase()}`);
    }
    const wanted = ids === null ? [...cat.items.keys()] : [...ids];
    // A merged item's search words move to the item it counts under, so that one is indexed again too.
    if (ids !== null) {
        for (const id of ids) {
            const root = cat.root(id);
            if (root !== id && !wanted.includes(root)) wanted.push(root);
        }
    }
    const aliases = cat.aliasesByItem();
    const offered = new Map<string, Set<string>>();
    const placed = new Set<string>();
    for (const id of wanted) {
        const item = cat.items.get(id);
        if (item === undefined || !cat.sellable(item)) {
            remove.push(id);
            continue;
        }
        upsert.push({
            id,
            name: item.display_name,
            text: [item.search_text, item.item_code, ...(aliases.get(id) ?? [])].join(' '),
            sold: cat.prices.get(id)?.sold_30d ?? 0,
        });
        // An item of a brand new to its category makes that brand a place there (D103).
        const category = cat.categories.get(item.category_id);
        if (ids === null || item.brand_id === null || category === undefined) continue;
        const brands = offered.get(category.id) ?? cat.brandsAtTiles(category.id);
        offered.set(category.id, brands);
        const doc = brands.has(item.brand_id) && !placed.has(`${category.id}:${item.brand_id}`) ? place(category, item.brand_id) : null;
        if (doc !== null) {
            placed.add(`${category.id}:${item.brand_id}`);
            upsert.push(doc);
        }
    }
    if (ids === null) {
        for (const shape of cat.shapes) {
            if (names.has(`${shape.category_id}|${shape.display_name.toLowerCase()}`)) continue;
            upsert.push({ id: shape.id, name: shape.display_name, text: shape.display_name, sold: 0 });
        }
        for (const category of cat.categories.values()) {
            if (category.code.startsWith('_')) continue;
            upsert.push({ id: `jump:${category.id}`, name: category.name, text: [category.word ?? '', ...category.aliases].join(' '), sold: 0 });
            if (!category.sellable || cat.templates.get(category.id)?.facets.some((f) => f.type === 'brand') !== true) continue;
            for (const brandId of cat.brandsAtTiles(category.id)) {
                const doc = place(category, brandId);
                if (doc !== null) upsert.push(doc);
            }
        }
    }
    return { upsert, remove };
}
