import { describe, expect, it } from 'vitest';
import { Catalogue } from './catalogue';
import { searchDocs } from './docs';

/** Two items, one merged into the other (spec section 6, check C6) [F18]. */
function merged(): Catalogue {
    const cat = new Catalogue();
    cat.apply({
        items: [
            { id: 'keep', category_id: 'c', brand_id: null, item_code: 'K00001', display_name: 'Oraimo Charger Head 20W', search_text: 'oraimo charger head 20w', status: 'active', merged_into_id: null },
            { id: 'gone', category_id: 'c', brand_id: null, item_code: 'G00001', display_name: 'Oriamo Charger Head 20W', search_text: 'oriamo charger head 20w', status: 'merged', merged_into_id: 'keep' },
        ],
        barcodes: [
            { id: 'bc1', code: '6936686402011', item_id: 'gone', status: 'confirmed', conflicted: false, last_chosen_at: '2026-11-05T10:00:00Z' },
            { id: 'bc2', code: '6936686402011', item_id: 'keep', status: 'unconfirmed', conflicted: false, last_chosen_at: '2026-11-04T10:00:00Z' },
        ],
        aliases: [{ id: 'a1', item_id: 'gone', term: 'oriamo head', status: 'active' }],
    });
    return cat;
}

describe('merged items at the counter', () => {
    it('follows the merge pointer to the item it counts under', () => {
        const cat = merged();
        expect(cat.root('gone')).toBe('keep');
        expect(cat.root('keep')).toBe('keep');
    });

    it("adds the kept item when the merged item's barcode is scanned, once", () => {
        const found = merged().barcodeCandidates('6936686402011');
        expect(found.map((b) => b.item_id)).toEqual(['keep']);
    });

    it("finds the kept item by the merged item's search words, and never offers the merged one", () => {
        const docs = searchDocs(merged(), null).upsert;
        expect(docs.map((d) => d.id)).toEqual(['keep']);
        expect(docs[0]?.text).toContain('oriamo head');
    });
});
