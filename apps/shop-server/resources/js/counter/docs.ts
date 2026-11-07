import type { Catalogue } from './catalogue';
import type { SearchDoc } from './search';

/** What search indexes for each item and each generated shape (spec section 7) [S10, R2]. */
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
    }
    return { upsert, remove };
}
