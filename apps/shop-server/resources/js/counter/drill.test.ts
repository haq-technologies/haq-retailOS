import { describe, expect, it } from 'vitest';
import { Catalogue } from './catalogue';
import { drill, jumpTile, parseShapeId, shapeAtBrandStep, type Tile } from './drill';

/** The tile with this label, or a failed test. */
function tile(tiles: Tile[], label: string): Tile {
    const found = tiles.find((t) => t.label === label);
    if (found === undefined) throw new Error(`no tile ${label}`);
    return found;
}

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
        // Skip brand first, then the cables' own brands A to Z: Oraimo is shown first there, Anker has an item (D94).
        expect(third.tiles.map((t) => t.label)).toEqual(['Skip brand', 'Anker', 'Oraimo']);
        // Each brand leaves one item, so its tile adds it in one tap and shows its price.
        expect(tile(third.tiles, 'Oraimo')).toMatchObject({ kind: 'value', itemId: 'i2', priceKobo: 300_000 });

        const last = drill(cat, { categoryId: 'c-cables', choices: [{ key: 'connector_pair', value: 'p-c2l' }, { key: 'length', value: 'p-1m' }, { key: 'brand', value: 'b-oraimo' }] }, 'retail');
        expect(last.tiles[0]).toEqual({ kind: 'item', id: 'i2', label: 'Oraimo Type-C to Lightning Cable 1m', priceKobo: 300_000, shape: null });
        // The items end with New, which opens New item with what the tiles know (D94).
        expect(last.tiles[1]).toEqual({ kind: 'new', id: 'new-item', label: 'New Oraimo cable', hint: 'Not here? Make it', categoryId: 'c-cables', brandId: 'b-oraimo', facets: { connector_pair: 'p-c2l', length: 'p-1m' } });
    });

    it('offers a generated shape as an item tile, with no price, once its brand is answered', () => {
        const choices = [{ key: 'connector_pair', value: 'p-c2c' }, { key: 'brand', value: null }];
        const view = drill(cables(), { categoryId: 'c-cables', choices }, 'retail');
        expect(view.tiles.map((t) => t.kind)).toEqual(['item', 'new']);
        expect(view.tiles[0]).toMatchObject({ kind: 'item', id: 'shape:c-cables:c2c-2m', label: 'Type-C to Type-C Cable 2m', priceKobo: null });
        expect(view.tiles[1]).toMatchObject({ kind: 'new', label: 'New cable', brandId: null });
    });

    it('a skipped facet keeps only the items without it, and still asks the brand', () => {
        const view = drill(cables(), { categoryId: 'c-cables', choices: [{ key: 'connector_pair', value: 'p-c2l' }, { key: 'length', value: null }] }, 'retail');
        expect(view.prompt).toBe('Choose brand');
        expect(tile(view.tiles, 'Oraimo')).toMatchObject({ kind: 'value', itemId: 'i3' });
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
        // No brand is the power banks' own here, so the whole listed list shows; a typed brand waits (D94).
        expect(view.tiles.map((t) => t.label)).toEqual(['Skip brand', 'Oraimo']);
    });

    it('makes a shape chosen after a brand that brand\'s item, named with it', () => {
        const view = drill(powerBanks(), { categoryId: 'c-pb', choices: [{ key: 'brand', value: 'b-oraimo' }] }, 'retail');
        expect(view.prompt).toBe('Choose capacity');
        // One tap on a capacity that leaves one shape adds it as Oraimo's.
        expect(view.tiles.find((t) => t.label === '20000mAh')).toMatchObject({ itemId: 'shape:c-pb:capacity=p-20k|brand=b-oraimo' });
        const item = drill(powerBanks(), { categoryId: 'c-pb', choices: [{ key: 'brand', value: 'b-oraimo' }, { key: 'capacity', value: 'p-20k' }] }, 'retail').tiles;
        expect(item.map((t) => t.kind)).toEqual(['item', 'new']);
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

describe('a shape waits for its brand (D79)', () => {
    it('asks the brand before a lone shape is sold, where the brand comes after the other steps', () => {
        const cat = cables();
        // Type-C to Type-C has one shape. Its tile no longer adds it: the brand is still to ask.
        const first = drill(cat, { categoryId: 'c-cables', choices: [] }, 'retail');
        expect(first.tiles.find((t) => t.label === 'Type-C to Type-C')).toMatchObject({ kind: 'value', itemId: null });

        const brand = drill(cat, { categoryId: 'c-cables', choices: [{ key: 'connector_pair', value: 'p-c2c' }] }, 'retail');
        expect(brand.prompt).toBe('Choose brand');
        expect(brand.tiles.map((t) => t.label)).toEqual(['Skip brand', 'Anker', 'Oraimo']);
        // Each answer leaves the one shape, so each adds it in one tap: with the brand, or with none.
        expect(tile(brand.tiles, 'Oraimo')).toMatchObject({ kind: 'value', itemId: 'shape:c-cables:c2c-2m|brand=b-oraimo' });
        expect(brand.tiles[0]).toMatchObject({ kind: 'skip', itemId: 'shape:c-cables:c2c-2m' });
    });

    it('asks the brand first where the catalogue says so, then sells each shape as that brand\'s in one tap', () => {
        const cat = cables();
        const template = cat.templates.get('c-cables');
        if (template === undefined) throw new Error('no template');
        cat.apply({ category_templates: [{ ...template, drill_order: ['brand', 'connector_pair', 'length'] }] });

        const first = drill(cat, { categoryId: 'c-cables', choices: [] }, 'retail');
        expect(first.prompt).toBe('Choose brand');
        expect(first.tiles.map((t) => t.label)).toEqual(['Skip brand', 'Anker', 'Oraimo']);
        // Several cables are left under any answer, so no tile adds one yet.
        expect(first.tiles.every((t) => (t.kind === 'value' || t.kind === 'skip') && t.itemId === null)).toBe(true);

        const oraimo = drill(cat, { categoryId: 'c-cables', choices: [{ key: 'brand', value: 'b-oraimo' }] }, 'retail');
        expect(oraimo.prompt).toBe('Choose connector pair');
        expect(oraimo.tiles.find((t) => t.label === 'Type-C to Type-C')).toMatchObject({ itemId: 'shape:c-cables:c2c-2m|brand=b-oraimo' });

        // With no brand: the shapes, sold as they are.
        const none = drill(cat, { categoryId: 'c-cables', choices: [{ key: 'brand', value: null }] }, 'retail');
        expect(none.tiles.find((t) => t.label === 'Type-C to Type-C')).toMatchObject({ itemId: 'shape:c-cables:c2c-2m' });
    });

    it('never offers "No brand" as a brand: it is the same answer as Skip brand', () => {
        const cat = cables();
        cat.apply({
            brands: [{ id: 'b-none', name: 'No brand', aliases: [], shown_first_in: [], status: 'approved', merged_into_id: null }],
            // An item made with "No brand" in New item is the unbranded Type-C to Type-C Cable 2m.
            items: [{ id: 'i-nb', category_id: 'c-cables', brand_id: 'b-none', item_code: 'A00009', display_name: 'Type-C to Type-C Cable 2m', search_text: '', status: 'active' }],
            item_facets: [
                { id: 'f-nb1', item_id: 'i-nb', facet_key: 'connector_pair', picklist_value_id: 'p-c2c', text_value: null },
                { id: 'f-nb2', item_id: 'i-nb', facet_key: 'length', picklist_value_id: 'p-2m', text_value: null },
            ],
        });
        cat.noBrandId = 'b-none';

        const brand = drill(cat, { categoryId: 'c-cables', choices: [{ key: 'connector_pair', value: 'p-c2c' }] }, 'retail');
        expect(brand.tiles.map((t) => t.label)).toEqual(['Skip brand', 'Anker', 'Oraimo']);
        // Skip brand is the item already made, once, not the shape beside it.
        expect(brand.tiles[0]).toMatchObject({ kind: 'skip', itemId: 'i-nb' });
        // The cable sold with no brand can still be sold as Oraimo's: another item, made on its first sale.
        expect(tile(brand.tiles, 'Oraimo')).toMatchObject({ kind: 'value', itemId: 'shape:c-cables:c2c-2m|brand=b-oraimo' });
    });

    it('puts a shape found by search at its brand step', () => {
        const cat = cables();
        const shape = cat.shapes[0];
        if (shape === undefined) throw new Error('no shape');
        const at = shapeAtBrandStep(cat, shape);
        expect(at).toEqual({ categoryId: 'c-cables', choices: [{ key: 'connector_pair', value: 'p-c2c' }, { key: 'length', value: 'p-2m' }] });
        if (at === null) throw new Error('no state');
        const view = drill(cat, at, 'retail');
        expect(view.prompt).toBe('Choose brand');
        expect(view.tiles[0]).toMatchObject({ kind: 'skip', itemId: 'shape:c-cables:c2c-2m' });

        // A category that asks no brand sells its shape as it is.
        cat.apply({ category_templates: [{ id: 't1', category_id: 'c-cables', facets: [{ key: 'connector_pair', label: 'Connector pair', type: 'picklist', list: 'connector_pairs' }], drill_order: ['connector_pair'], name_order: ['{word}', 'connector_pair'], asks_colour: false }] });
        expect(shapeAtBrandStep(cat, shape)).toBeNull();
    });
});

describe('the brand step in every category with a brand (D94)', () => {
    function earbuds(): Catalogue {
        const cat = new Catalogue();
        cat.apply({
            categories: [{ id: 'c-ear', code: 'earbuds', parent_id: null, name: 'Earbuds and earpods', word: 'Earbuds', sellable: true, aliases: ['earpods'], position: 1 }],
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
                { id: 'b-hoco', name: 'Hoco', aliases: [], shown_first_in: ['earbuds'], status: 'approved', merged_into_id: null },
                { id: 'b-jbl', name: 'JBL', aliases: [], shown_first_in: ['bluetooth_speakers'], status: 'approved', merged_into_id: null },
                { id: 'b-none', name: 'No brand', aliases: [], shown_first_in: [], status: 'approved', merged_into_id: null },
            ],
        });
        cat.noBrandId = 'b-none';
        return cat;
    }

    it('asks the brand with no item yet: Skip brand first, the category\'s own brands A to Z, then More brands', () => {
        const cat = earbuds();
        const first = drill(cat, { categoryId: 'c-ear', choices: [] }, 'retail');
        expect(first.prompt).toBe('Choose brand');
        expect(first.tiles.map((t) => t.label)).toEqual(['Skip brand', 'Hoco', 'Oraimo', 'More brands']);
        expect(first.tiles.at(-1)).toMatchObject({ kind: 'more', hint: '1 more in the brand list' });

        const all = drill(cat, { categoryId: 'c-ear', choices: [], allBrands: true }, 'retail');
        expect(all.tiles.map((t) => t.label)).toEqual(['Skip brand', 'Hoco', 'Oraimo', 'JBL']);
    });

    it('ends a brand with nothing sold yet at New, which opens New item with the brand', () => {
        const view = drill(earbuds(), { categoryId: 'c-ear', choices: [{ key: 'brand', value: 'b-oraimo' }] }, 'retail');
        expect(view.prompt).toBe('None yet. Make one with New.');
        expect(view.tiles).toEqual([{ kind: 'new', id: 'new-item', label: 'New Oraimo earbuds', hint: 'Not here? Make it', categoryId: 'c-ear', brandId: 'b-oraimo', facets: {} }]);
        expect(view.crumbs).toEqual(['Earbuds and earpods', 'Oraimo']);
    });

    it('counts a brand with an item in the category as its own, even one still waiting, and adds a lone item in one tap', () => {
        const cat = earbuds();
        cat.apply({
            brands: [{ id: 'b-zeta', name: 'Zetasound', aliases: [], shown_first_in: [], status: 'pending', merged_into_id: null }],
            items: [{ id: 'i-zeta', category_id: 'c-ear', brand_id: 'b-zeta', item_code: 'A00031', display_name: 'Zetasound Zeta Pods 9 Earbuds', search_text: '', status: 'pending_review' }],
            item_facets: [{ id: 'f-zeta', item_id: 'i-zeta', facet_key: 'model', picklist_value_id: null, text_value: 'Zeta Pods 9' }],
        });
        const view = drill(cat, { categoryId: 'c-ear', choices: [] }, 'retail');
        expect(view.tiles.map((t) => t.label)).toEqual(['Skip brand', 'Hoco', 'Oraimo', 'Zetasound', 'More brands']);
        expect(tile(view.tiles, 'Zetasound')).toMatchObject({ kind: 'value', itemId: 'i-zeta' });
    });

    it('shows the whole list where a category has no brand of its own, as the shop visit agreed', () => {
        const cat = earbuds();
        cat.apply({
            brands: [
                { id: 'b-oraimo', name: 'Oraimo', aliases: [], shown_first_in: [], status: 'approved', merged_into_id: null },
                { id: 'b-hoco', name: 'Hoco', aliases: [], shown_first_in: [], status: 'approved', merged_into_id: null },
            ],
        });
        const view = drill(cat, { categoryId: 'c-ear', choices: [] }, 'retail');
        expect(view.tiles.map((t) => t.label)).toEqual(['Skip brand', 'Hoco', 'JBL', 'Oraimo']);
    });

    it('opens the tiles at a category, or at a category\'s brand, from a search result', () => {
        const cat = earbuds();
        expect(jumpTile(cat, 'jump:c-ear')).toEqual({ kind: 'jump', id: 'jump:c-ear', label: 'Earbuds and earpods', hint: 'Category', state: { categoryId: 'c-ear', choices: [] } });
        expect(jumpTile(cat, 'jump:c-ear:b-oraimo')).toMatchObject({ label: 'Oraimo · Earbuds and earpods', hint: 'Brand', state: { categoryId: 'c-ear', choices: [{ key: 'brand', value: 'b-oraimo' }] } });
        expect(jumpTile(cat, 'jump:c-gone')).toBeNull();
        expect(jumpTile(cat, 'jump:c-ear:b-gone')).toBeNull();
    });
});
