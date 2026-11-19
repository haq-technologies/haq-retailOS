import { describe, expect, it } from 'vitest';
import { Catalogue } from './catalogue';
import { searchDocs } from './docs';
import { buildIndex, search } from './search';

/*
 * Search finds places on the tiles as well as items (D94): each category, and each of a category's own
 * brands, so a cashier in a shop that has sold nothing yet can type "earbuds" or "oraimo" and go there.
 */
function shop(): Catalogue {
    const cat = new Catalogue();
    cat.apply({
        categories: [
            { id: 'c-ear', code: 'earbuds', parent_id: null, name: 'Earbuds and earpods', word: 'Earbuds', sellable: true, aliases: ['earpods'], position: 1 },
            { id: 'c-nil', code: '_not_in_list', parent_id: null, name: 'Not in the list', word: 'Item', sellable: true, aliases: [], position: 9999 },
        ],
        category_templates: [
            {
                id: 't-ear',
                category_id: 'c-ear',
                facets: [
                    { key: 'brand', label: 'Brand', type: 'brand' },
                    { key: 'model', label: 'Model', type: 'text' },
                ],
                drill_order: ['brand', 'model'],
                name_order: ['brand', 'model', '{word}'],
                asks_colour: false,
            },
        ],
        brands: [
            { id: 'b-oraimo', name: 'Oraimo', aliases: [], shown_first_in: ['earbuds'], status: 'approved', merged_into_id: null },
            { id: 'b-jbl', name: 'JBL', aliases: [], shown_first_in: ['bluetooth_speakers'], status: 'approved', merged_into_id: null },
            { id: 'b-none', name: 'No brand', aliases: [], shown_first_in: ['earbuds'], status: 'approved', merged_into_id: null },
        ],
        items: [{ id: 'i-o3', category_id: 'c-ear', brand_id: 'b-oraimo', item_code: 'A00003', display_name: 'Oraimo FreePods 3 Earbuds', search_text: 'oraimo freepods 3 earbuds', status: 'active' }],
    });
    cat.noBrandId = 'b-none';
    return cat;
}

describe('search finds places on the tiles (D94)', () => {
    it('indexes each category and its own brands, never the holding category or No brand', () => {
        const ids = searchDocs(shop(), null).upsert.map((d) => d.id);
        expect(ids).toContain('jump:c-ear');
        expect(ids).toContain('jump:c-ear:b-oraimo');
        expect(ids).not.toContain('jump:c-ear:b-jbl');
        expect(ids).not.toContain('jump:c-ear:b-none');
        expect(ids).not.toContain('jump:c-nil');
    });

    it('ranks the items above the places, and finds a category by its other words', () => {
        const index = buildIndex(searchDocs(shop(), null).upsert);
        expect(search(index, 'oraimo')).toEqual(['i-o3', 'jump:c-ear:b-oraimo']);
        expect(search(index, 'earpods')).toContain('jump:c-ear');
    });
});
