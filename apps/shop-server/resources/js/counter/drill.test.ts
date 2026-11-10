import { describe, expect, it } from 'vitest';
import { Catalogue } from './catalogue';
import { drill, parseShapeId } from './drill';

function cables(): Catalogue {
    const cat = new Catalogue();
    cat.apply({
        categories: [
            { id: 'c-cables', code: 'cables', parent_id: null, name: 'Cables', word: 'Cable', sellable: true, aliases: [], position: 1 },
            { id: 'c-wall', code: 'wall_chargers', parent_id: null, name: 'Wall chargers', word: null, sellable: false, aliases: [], position: 2 },
            { id: 'c-head', code: 'charger_head', parent_id: 'c-wall', name: 'Charger head', word: 'Charger Head', sellable: true, aliases: [], position: 1 },
            { id: 'c-nil', code: '_not_in_list', parent_id: null, name: 'Not in the list', word: 'Item', sellable: true, aliases: [], position: 9999 },
        ],
        category_templates: [
            {
                id: 't1',
                category_id: 'c-cables',
                facets: [
                    { key: 'brand', label: 'Brand', type: 'brand' },
                    { key: 'connector_pair', label: 'Connector pair', type: 'picklist', list: 'connector_pairs' },
                    { key: 'length', label: 'Length', type: 'picklist', list: 'cable_lengths' },
                ],
                drill_order: ['connector_pair', 'length', 'brand'],
                name_order: ['brand', 'connector_pair', '{word}', 'length'],
                asks_colour: false,
            },
        ],
        picklist_values: [
            { id: 'p-c2l', list: 'connector_pairs', name: 'Type-C to Lightning', aliases: [], parent_id: null, status: 'active', position: 1 },
            { id: 'p-c2c', list: 'connector_pairs', name: 'Type-C to Type-C', aliases: [], parent_id: null, status: 'active', position: 2 },
            { id: 'p-1m', list: 'cable_lengths', name: '1m', aliases: [], parent_id: null, status: 'active', position: 2 },
            { id: 'p-2m', list: 'cable_lengths', name: '2m', aliases: [], parent_id: null, status: 'active', position: 3 },
        ],
        brands: [
            { id: 'b-anker', name: 'Anker', aliases: [], shown_first_in: [], status: 'approved', merged_into_id: null },
            { id: 'b-oraimo', name: 'Oraimo', aliases: [], shown_first_in: ['cables'], status: 'approved', merged_into_id: null },
        ],
        items: [
            { id: 'i1', category_id: 'c-cables', brand_id: 'b-anker', item_code: 'A00001', display_name: 'Anker Type-C to Lightning Cable 1m', search_text: '', status: 'active' },
            { id: 'i2', category_id: 'c-cables', brand_id: 'b-oraimo', item_code: 'A00002', display_name: 'Oraimo Type-C to Lightning Cable 1m', search_text: '', status: 'active' },
            { id: 'i3', category_id: 'c-cables', brand_id: 'b-oraimo', item_code: 'A00003', display_name: 'Oraimo Type-C to Lightning Cable', search_text: '', status: 'active' },
        ],
        item_facets: [
            { id: 'f1', item_id: 'i1', facet_key: 'connector_pair', picklist_value_id: 'p-c2l', text_value: null },
            { id: 'f2', item_id: 'i1', facet_key: 'length', picklist_value_id: 'p-1m', text_value: null },
            { id: 'f3', item_id: 'i2', facet_key: 'connector_pair', picklist_value_id: 'p-c2l', text_value: null },
            { id: 'f4', item_id: 'i2', facet_key: 'length', picklist_value_id: 'p-1m', text_value: null },
            { id: 'f5', item_id: 'i3', facet_key: 'connector_pair', picklist_value_id: 'p-c2l', text_value: null },
        ],
    });
    cat.shapes = [
        { id: 'shape:c-cables:c2c-2m', category_id: 'c-cables', facets: { connector_pair: 'p-c2c', length: 'p-2m' }, display_name: 'Type-C to Type-C Cable 2m' },
        { id: 'shape:c-cables:c2l-2m', category_id: 'c-cables', facets: { connector_pair: 'p-c2l', length: 'p-2m' }, display_name: 'Type-C to Lightning Cable 2m' },
    ];
    cat.setPrices([{ item_id: 'i2', retail: { set_price: 300_000, prefill: 300_000, prefill_source: 'set_price', range: null }, wholesale: { set_price: null, prefill: null, prefill_source: null, range: null }, sold_30d: 4 }]);
    return cat;
}

describe('the tiles', () => {
    it('starts with the top categories in order, never the holding category', () => {
        const view = drill(cables(), { categoryId: null, choices: [] }, 'retail');
        expect(view.tiles.map((t) => t.label)).toEqual(['Cables', 'Wall chargers']);
    });

    it('opens a parent category on its children', () => {
        const view = drill(cables(), { categoryId: 'c-wall', choices: [] }, 'retail');
        expect(view.tiles.map((t) => t.label)).toEqual(['Charger head']);
    });

    it('steps through the drill order, with shapes not yet sold, and a Skip tile for a facet some items lack', () => {
        const cat = cables();
        const first = drill(cat, { categoryId: 'c-cables', choices: [] }, 'retail');
        expect(first.prompt).toBe('Choose connector pair');
        expect(first.tiles.map((t) => t.label)).toEqual(['Type-C to Lightning', 'Type-C to Type-C']);

        const second = drill(cat, { categoryId: 'c-cables', choices: [{ key: 'connector_pair', value: 'p-c2l' }] }, 'retail');
        expect(second.prompt).toBe('Choose length');
        expect(second.tiles.map((t) => t.label)).toEqual(['1m', '2m', 'Skip length']);
        expect(second.crumbs).toEqual(['Cables', 'Type-C to Lightning']);

        const third = drill(cat, { categoryId: 'c-cables', choices: [{ key: 'connector_pair', value: 'p-c2l' }, { key: 'length', value: 'p-1m' }] }, 'retail');
        expect(third.prompt).toBe('Choose brand');
        expect(third.tiles.map((t) => t.label)).toEqual(['Oraimo', 'Anker']); // shown first in cables
        // Each brand leaves one item, so its tile adds it in one tap and shows its price.
        expect(third.tiles[0]).toMatchObject({ kind: 'value', itemId: 'i2', priceKobo: 300_000 });

        const last = drill(cat, { categoryId: 'c-cables', choices: [{ key: 'connector_pair', value: 'p-c2l' }, { key: 'length', value: 'p-1m' }, { key: 'brand', value: 'b-oraimo' }] }, 'retail');
        expect(last.tiles).toEqual([{ kind: 'item', id: 'i2', label: 'Oraimo Type-C to Lightning Cable 1m', priceKobo: 300_000, shape: null }]);
    });

    it('offers a generated shape as an item tile, with no price', () => {
        const view = drill(cables(), { categoryId: 'c-cables', choices: [{ key: 'connector_pair', value: 'p-c2c' }] }, 'retail');
        expect(view.tiles).toHaveLength(1);
        expect(view.tiles[0]).toMatchObject({ kind: 'item', label: 'Type-C to Type-C Cable 2m', priceKobo: null });
    });

    it('a skipped facet keeps only the items without it', () => {
        const view = drill(cables(), { categoryId: 'c-cables', choices: [{ key: 'connector_pair', value: 'p-c2l' }, { key: 'length', value: null }] }, 'retail');
        expect(view.tiles.map((t) => t.label)).toEqual(['Oraimo Type-C to Lightning Cable']);
        expect(view.crumbs.at(-1)).toBe('No length');
    });
});

describe('the brand step with generated shapes (D54)', () => {
    function powerBanks(): Catalogue {
        const cat = new Catalogue();
        cat.apply({
            categories: [{ id: 'c-pb', code: 'power_banks', parent_id: null, name: 'Power banks', word: 'Power Bank', sellable: true, aliases: [], position: 1 }],
            category_templates: [
                {
                    id: 't-pb',
                    category_id: 'c-pb',
                    facets: [
                        { key: 'brand', label: 'Brand', type: 'brand' },
                        { key: 'capacity', label: 'Capacity', type: 'picklist', list: 'capacities_mah' },
                    ],
                    drill_order: ['brand', 'capacity'],
                    name_order: ['brand', '{word}', 'capacity'],
                    asks_colour: false,
                },
            ],
            picklist_values: [
                { id: 'p-10k', list: 'capacities_mah', name: '10000mAh', aliases: [], parent_id: null, status: 'active', position: 1 },
                { id: 'p-20k', list: 'capacities_mah', name: '20000mAh', aliases: [], parent_id: null, status: 'active', position: 2 },
            ],
            brands: [
                { id: 'b-oraimo', name: 'Oraimo', aliases: [], shown_first_in: [], status: 'approved', merged_into_id: null },
                { id: 'b-new', name: 'Typed Brand', aliases: [], shown_first_in: [], status: 'pending', merged_into_id: null },
            ],
        });
        cat.shapes = [
            { id: 'shape:c-pb:capacity=p-10k', category_id: 'c-pb', facets: { capacity: 'p-10k' }, display_name: 'Power Bank 10000mAh' },
            { id: 'shape:c-pb:capacity=p-20k', category_id: 'c-pb', facets: { capacity: 'p-20k' }, display_name: 'Power Bank 20000mAh' },
        ];
        return cat;
    }

    it('offers every listed brand in a fresh shop, not only brands already sold, and a Skip', () => {
        const view = drill(powerBanks(), { categoryId: 'c-pb', choices: [] }, 'retail');
        expect(view.prompt).toBe('Choose brand');
        expect(view.tiles.map((t) => t.label)).toEqual(['Oraimo', 'Skip brand']);
    });

    it('makes a shape chosen after a brand that brand\'s item, named with it', () => {
        const view = drill(powerBanks(), { categoryId: 'c-pb', choices: [{ key: 'brand', value: 'b-oraimo' }] }, 'retail');
        expect(view.prompt).toBe('Choose capacity');
        // One tap on a capacity that leaves one shape adds it as Oraimo's.
        expect(view.tiles.find((t) => t.label === '20000mAh')).toMatchObject({ itemId: 'shape:c-pb:capacity=p-20k|brand=b-oraimo' });
        const item = drill(powerBanks(), { categoryId: 'c-pb', choices: [{ key: 'brand', value: 'b-oraimo' }, { key: 'capacity', value: 'p-20k' }] }, 'retail').tiles;
        expect(item).toHaveLength(1);
        expect(item[0]).toMatchObject({ kind: 'item', id: 'shape:c-pb:capacity=p-20k|brand=b-oraimo', label: 'Oraimo Power Bank 20000mAh' });
        expect(parseShapeId('shape:c-pb:capacity=p-20k|brand=b-oraimo')).toEqual({ shapeId: 'shape:c-pb:capacity=p-20k', brandId: 'b-oraimo' });
        expect(parseShapeId('shape:c-pb:capacity=p-20k')).toEqual({ shapeId: 'shape:c-pb:capacity=p-20k', brandId: null });
    });

    it("still offers a brand's other capacities after one of them is sold, and the sold one once, as the item", () => {
        const cat = powerBanks();
        cat.apply({
            items: [{ id: 'i-o20', category_id: 'c-pb', brand_id: 'b-oraimo', item_code: 'A00020', display_name: 'Oraimo Power Bank 20000mAh', search_text: '', status: 'active' }],
            item_facets: [{ id: 'f-o20', item_id: 'i-o20', facet_key: 'capacity', picklist_value_id: 'p-20k', text_value: null }],
        });
        const view = drill(cat, { categoryId: 'c-pb', choices: [{ key: 'brand', value: 'b-oraimo' }] }, 'retail');
        expect(view.prompt).toBe('Choose capacity');
        expect(view.tiles.find((t) => t.label === '20000mAh')).toMatchObject({ itemId: 'i-o20' });
        expect(view.tiles.find((t) => t.label === '10000mAh')).toMatchObject({ itemId: 'shape:c-pb:capacity=p-10k|brand=b-oraimo' });
    });
});
