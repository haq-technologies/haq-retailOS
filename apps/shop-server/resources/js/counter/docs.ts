import type { Catalogue } from './catalogue';
import type { SearchDoc } from './search';

/**
 * What search indexes for each item and each generated shape (spec section 7) [S10, R2], and the places on
 * the tiles (D94): each category, and each of a category's own brands, so "earbuds" or "oraimo" finds where
 * to go in a shop that has sold nothing yet. Places rank below items (search.ts) and are listed after them.
 */
export function searchDocs(cat: Catalogue, ids: Set<string> | null): { upsert: SearchDoc[]; remove: string[] } {
    const upsert: SearchDoc[] = [];
    const remove: string[] = [];
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
            for (const brandId of cat.brandsOf(category.id)) {
                const brand = cat.brands.get(brandId);
                if (brand === undefined) continue;
                upsert.push({ id: `jump:${category.id}:${brandId}`, name: `${brand.name} ${category.name}`, text: [...brand.aliases, category.word ?? '', ...category.aliases].join(' '), sold: 0 });
            }
        }
    }
    return { upsert, remove };
}
