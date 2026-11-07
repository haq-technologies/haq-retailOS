import type {
    AliasRow,
    BarcodeRow,
    BrandRow,
    CategoryRow,
    ChangesPage,
    CustomerType,
    FeedRows,
    FeedTable,
    ItemFacetRow,
    ItemPrices,
    ItemRow,
    PicklistRow,
    ProductModelRow,
    Shape,
    TemplateRow,
} from './types';

/**
 * The counter's copy of the catalogue in memory, kept from the feed. Everything the counter shows is
 * read from here, so a tap never waits for the server (spec section 4) [F25].
 */
export class Catalogue {
    categories = new Map<string, CategoryRow>();
    templates = new Map<string, TemplateRow>(); // by category_id
    picklist = new Map<string, PicklistRow>();
    brands = new Map<string, BrandRow>();
    models = new Map<string, ProductModelRow>();
    items = new Map<string, ItemRow>();
    facets = new Map<string, Map<string, ItemFacetRow>>(); // by item_id, then facet_key
    barcodes = new Map<string, BarcodeRow>(); // by row id
    aliases = new Map<string, AliasRow>(); // by row id
    prices = new Map<string, ItemPrices>();
    shapes: Shape[] = [];
    seq = 0;

    private facetIndex = new Map<string, ItemFacetRow>(); // by row id
    private templateIndex = new Map<string, TemplateRow>(); // by row id

    apply(rows: FeedRows): void {
        for (const r of rows.categories ?? []) this.categories.set(r.id, r);
        for (const r of rows.category_templates ?? []) {
            const old = this.templateIndex.get(r.id);
            if (old !== undefined) this.templates.delete(old.category_id);
            this.templateIndex.set(r.id, r);
            this.templates.set(r.category_id, r);
        }
        for (const r of rows.picklist_values ?? []) this.picklist.set(r.id, r);
        for (const r of rows.brands ?? []) this.brands.set(r.id, r);
        for (const r of rows.product_models ?? []) this.models.set(r.id, r);
        for (const r of rows.items ?? []) this.items.set(r.id, r);
        for (const r of rows.item_facets ?? []) {
            const old = this.facetIndex.get(r.id);
            if (old !== undefined) this.facets.get(old.item_id)?.delete(old.facet_key);
            this.facetIndex.set(r.id, r);
            const byKey = this.facets.get(r.item_id) ?? new Map<string, ItemFacetRow>();
            byKey.set(r.facet_key, r);
            this.facets.set(r.item_id, byKey);
        }
        for (const r of rows.barcodes ?? []) this.barcodes.set(r.id, r);
        for (const r of rows.aliases ?? []) this.aliases.set(r.id, r);
    }

    remove(removed: ChangesPage['removed']): void {
        for (const [table, ids] of Object.entries(removed) as [FeedTable, string[]][]) {
            for (const id of ids) {
                if (table === 'item_facets') {
                    const old = this.facetIndex.get(id);
                    if (old !== undefined) this.facets.get(old.item_id)?.delete(old.facet_key);
                    this.facetIndex.delete(id);
                } else if (table === 'category_templates') {
                    const old = this.templateIndex.get(id);
                    if (old !== undefined) this.templates.delete(old.category_id);
                    this.templateIndex.delete(id);
                } else {
                    this.mapFor(table).delete(id);
                }
            }
        }
    }

    setPrices(prices: ItemPrices[]): void {
        for (const p of prices) this.prices.set(p.item_id, p);
    }

    /** Forgets everything, before a fresh snapshot after the server was restored (D44). */
    clear(): void {
        for (const map of [this.categories, this.templates, this.picklist, this.brands, this.models, this.items, this.facets, this.barcodes, this.aliases, this.prices, this.facetIndex, this.templateIndex]) {
            map.clear();
        }
        this.shapes = [];
        this.seq = 0;
    }

    /** Items a cashier can sell: not merged into another, not archived. */
    sellable(item: ItemRow): boolean {
        return item.status !== 'merged' && item.status !== 'archived';
    }

    /** The item an item now counts under: itself, or the end of its merge pointers [F18]. */
    root(itemId: string): string {
        let id = itemId;
        for (let hops = 0; hops < 20; hops++) {
            const item = this.items.get(id);
            const next = item?.status === 'merged' ? item.merged_into_id : null;
            if (next === null || next === undefined) return id;
            id = next;
        }
        return id;
    }

    /**
     * The barcode's candidates, at most 5, most recently chosen first (spec section 6). A merged item's code
     * adds the item it now counts under.
     */
    barcodeCandidates(code: string): BarcodeRow[] {
        const seen = new Set<string>();
        return [...this.barcodes.values()]
            .filter((b) => b.code === code && b.status !== 'disabled')
            .sort((a, b) => (b.last_chosen_at ?? '').localeCompare(a.last_chosen_at ?? ''))
            .map((b) => ({ ...b, item_id: this.root(b.item_id) }))
            .filter((b) => {
                const item = this.items.get(b.item_id);
                if (item === undefined || !this.sellable(item) || seen.has(b.item_id)) return false;
                seen.add(b.item_id);
                return true;
            })
            .slice(0, 5);
    }

    /** Every item's active search words, with those of items merged into it, worked out once per rebuild. */
    aliasesByItem(): Map<string, string[]> {
        const byItem = new Map<string, string[]>();
        for (const a of this.aliases.values()) {
            if (a.status !== 'active') continue;
            const root = this.root(a.item_id);
            byItem.set(root, [...(byItem.get(root) ?? []), a.term]);
        }
        return byItem;
    }

    /** The price the counter fills in for an item and customer type, with where it came from [S4]. */
    priceFor(itemId: string, type: CustomerType): { setPrice: number | null; prefill: number | null; source: 'set_price' | 'last_price' | 'median' | null } {
        const info = this.prices.get(itemId)?.[type];
        return { setPrice: info?.set_price ?? null, prefill: info?.prefill ?? null, source: info?.prefill_source ?? null };
    }

    asksColour(categoryId: string): boolean {
        return this.templates.get(categoryId)?.asks_colour ?? false;
    }

    /** The value of one of an item's facets: brand from the item, the rest from its facet rows. */
    facetValue(itemId: string, key: string): string | null {
        if (key === 'brand') {
            return this.items.get(itemId)?.brand_id ?? null;
        }
        const row = this.facets.get(itemId)?.get(key);
        return row === undefined ? null : (row.picklist_value_id ?? row.text_value);
    }

    private mapFor(table: FeedTable): Map<string, unknown> {
        switch (table) {
            case 'categories':
                return this.categories;
            case 'picklist_values':
                return this.picklist;
            case 'brands':
                return this.brands;
            case 'product_models':
                return this.models;
            case 'items':
                return this.items;
            case 'barcodes':
                return this.barcodes;
            case 'aliases':
                return this.aliases;
            default:
                return new Map();
        }
    }
}
