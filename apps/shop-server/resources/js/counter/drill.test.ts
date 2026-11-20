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
        expect(third.tiles[0]).toEqual({ kind: 'skip', key: 'brand', label: 'Skip brand', hint: 'Shows every brand' });
        // Each brand leaves one item, but its tile only moves the tiles on, with no price: the item is added from its own tile (D102).
        expect(tile(third.tiles, 'Oraimo')).toEqual({ kind: 'value', key: 'brand', value: 'b-oraimo', label: 'Oraimo' });

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
        const choices = [{ key: 'connector_pair', value: 'p-c2l' }, { key: 'length', value: null }];
        const view = drill(cables(), { categoryId: 'c-cables', choices }, 'retail');
        expect(view.prompt).toBe('Choose brand');
        expect(view.tiles.map((t) => t.label)).toEqual(['Skip brand', 'Anker', 'Oraimo']);
        expect(view.crumbs.at(-1)).toBe('No length');
        const oraimo = drill(cables(), { categoryId: 'c-cables', choices: [...choices, { key: 'brand', value: 'b-oraimo' }] }, 'retail');
        expect(oraimo.tiles.map((t) => t.label)).toEqual(['Oraimo Type-C to Lightning Cable', 'New Oraimo cable']);
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
        // A capacity that leaves one shape only moves the tiles on (D102); the list then sells it as Oraimo's.
        expect(tile(view.tiles, '20000mAh')).toEqual({ kind: 'value', key: 'capacity', value: 'p-20k', label: '20000mAh' });
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
        const oraimo = [{ key: 'brand', value: 'b-oraimo' }];
        const view = drill(cat, { categoryId: 'c-pb', choices: oraimo }, 'retail');
        expect(view.prompt).toBe('Choose capacity');
        expect(view.tiles.map((t) => t.label)).toEqual(['10000mAh', '20000mAh']);
        const sold = drill(cat, { categoryId: 'c-pb', choices: [...oraimo, { key: 'capacity', value: 'p-20k' }] }, 'retail');
        expect(sold.tiles.map((t) => (t.kind === 'item' ? t.id : t.kind))).toEqual(['i-o20', 'new']);
        const other = drill(cat, { categoryId: 'c-pb', choices: [...oraimo, { key: 'capacity', value: 'p-10k' }] }, 'retail');
        expect(other.tiles.map((t) => (t.kind === 'item' ? t.id : t.kind))).toEqual(['shape:c-pb:capacity=p-10k|brand=b-oraimo', 'new']);
    });
});

describe('a shape waits for its brand (D79)', () => {
    it('asks the brand before a lone shape is sold, where the brand comes after the other steps', () => {
        const cat = cables();
        // Type-C to Type-C has one shape. Its tile only moves on to the brand, which is still to ask.
        const first = drill(cat, { categoryId: 'c-cables', choices: [] }, 'retail');
        expect(tile(first.tiles, 'Type-C to Type-C')).toEqual({ kind: 'value', key: 'connector_pair', value: 'p-c2c', label: 'Type-C to Type-C' });

        const c2c = [{ key: 'connector_pair', value: 'p-c2c' }];
        const brand = drill(cat, { categoryId: 'c-cables', choices: c2c }, 'retail');
        expect(brand.prompt).toBe('Choose brand');
        expect(brand.tiles.map((t) => t.label)).toEqual(['Skip brand', 'Anker', 'Oraimo']);
        // Each answer then lists the one shape, with the brand or with none, and New after it (D102).
        const oraimo = drill(cat, { categoryId: 'c-cables', choices: [...c2c, { key: 'brand', value: 'b-oraimo' }] }, 'retail');
        expect(oraimo.tiles.map((t) => (t.kind === 'item' ? t.id : t.label))).toEqual(['shape:c-cables:c2c-2m|brand=b-oraimo', 'New Oraimo cable']);
        const none = drill(cat, { categoryId: 'c-cables', choices: [...c2c, { key: 'brand', value: null }] }, 'retail');
        expect(none.tiles.map((t) => (t.kind === 'item' ? t.id : t.label))).toEqual(['shape:c-cables:c2c-2m', 'New cable']);
    });

    it('asks the brand first where the catalogue says so, then sells each shape as that brand\'s', () => {
        const cat = cables();
        const template = cat.templates.get('c-cables');
        if (template === undefined) throw new Error('no template');
        cat.apply({ category_templates: [{ ...template, drill_order: ['brand', 'connector_pair', 'length'] }] });

        const first = drill(cat, { categoryId: 'c-cables', choices: [] }, 'retail');
        expect(first.prompt).toBe('Choose brand');
        expect(first.tiles.map((t) => t.label)).toEqual(['Skip brand', 'Anker', 'Oraimo']);

        const oraimo = [{ key: 'brand', value: 'b-oraimo' }];
        expect(drill(cat, { categoryId: 'c-cables', choices: oraimo }, 'retail').prompt).toBe('Choose connector pair');
        const oraimoC2c = drill(cat, { categoryId: 'c-cables', choices: [...oraimo, { key: 'connector_pair', value: 'p-c2c' }] }, 'retail');
        expect(oraimoC2c.tiles[0]).toMatchObject({ kind: 'item', id: 'shape:c-cables:c2c-2m|brand=b-oraimo', label: 'Oraimo Type-C to Type-C Cable 2m' });

        // Skip brand: the shapes, sold as they are, with no brand.
        const none = [{ key: 'brand', value: null }];
        const noneC2c = drill(cat, { categoryId: 'c-cables', choices: [...none, { key: 'connector_pair', value: 'p-c2c' }] }, 'retail');
        expect(noneC2c.tiles[0]).toMatchObject({ kind: 'item', id: 'shape:c-cables:c2c-2m', label: 'Type-C to Type-C Cable 2m' });
        expect(noneC2c.crumbs).toEqual(['Cables', 'Every brand', 'Type-C to Type-C']);
    });

    it('shows every brand after Skip brand, and the shape with no brand beside them (D102)', () => {
        const cat = cables();
        const template = cat.templates.get('c-cables');
        if (template === undefined) throw new Error('no template');
        cat.apply({ category_templates: [{ ...template, drill_order: ['brand', 'connector_pair', 'length'] }] });

        const none = [{ key: 'brand', value: null }, { key: 'connector_pair', value: 'p-c2l' }];
        const lengths = drill(cat, { categoryId: 'c-cables', choices: none }, 'retail');
        expect(lengths.tiles.map((t) => t.label)).toEqual(['1m', '2m', 'Skip length']);
        const oneMetre = drill(cat, { categoryId: 'c-cables', choices: [...none, { key: 'length', value: 'p-1m' }] }, 'retail');
        expect(oneMetre.prompt).toBe('Choose the item');
        expect(oneMetre.tiles.map((t) => t.label)).toEqual(['Anker Type-C to Lightning Cable 1m', 'Oraimo Type-C to Lightning Cable 1m', 'New cable']);
        expect(oneMetre.tiles[1]).toMatchObject({ kind: 'item', id: 'i2', priceKobo: 300_000 });
        // New after Skip brand opens New item with no brand chosen: the form starts from the category's own default.
        expect(oneMetre.tiles[2]).toMatchObject({ kind: 'new', brandId: null, facets: { connector_pair: 'p-c2l', length: 'p-1m' } });
        const twoMetres = drill(cat, { categoryId: 'c-cables', choices: [...none, { key: 'length', value: 'p-2m' }] }, 'retail');
        expect(twoMetres.tiles.map((t) => (t.kind === 'item' ? t.id : t.kind))).toEqual(['shape:c-cables:c2l-2m', 'new']);
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
        // Skip brand lists the item already made, once, not the shape beside it.
        const c2c = [{ key: 'connector_pair', value: 'p-c2c' }];
        const none = drill(cat, { categoryId: 'c-cables', choices: [...c2c, { key: 'brand', value: null }] }, 'retail');
        expect(none.tiles.map((t) => (t.kind === 'item' ? t.id : t.kind))).toEqual(['i-nb', 'new']);
        // The cable sold with no brand can still be sold as Oraimo's: another item, made on its first sale.
        const oraimo = drill(cat, { categoryId: 'c-cables', choices: [...c2c, { key: 'brand', value: 'b-oraimo' }] }, 'retail');
        expect(oraimo.tiles.map((t) => (t.kind === 'item' ? t.id : t.kind))).toEqual(['shape:c-cables:c2c-2m|brand=b-oraimo', 'new']);
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
        expect(view.tiles[0]).toMatchObject({ kind: 'skip', label: 'Skip brand' });
        const none = drill(cat, { ...at, choices: [...at.choices, { key: 'brand', value: null }] }, 'retail');
        expect(none.tiles.map((t) => (t.kind === 'item' ? t.id : t.kind))).toEqual(['shape:c-cables:c2c-2m', 'new']);

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

    it('counts a brand with an item in the category as its own, even one still waiting, and lists its lone item with New', () => {
        const cat = earbuds();
        cat.apply({
            brands: [{ id: 'b-zeta', name: 'Zetasound', aliases: [], shown_first_in: [], status: 'pending', merged_into_id: null }],
            items: [{ id: 'i-zeta', category_id: 'c-ear', brand_id: 'b-zeta', item_code: 'A00031', display_name: 'Zetasound Zeta Pods 9 Earbuds', search_text: '', status: 'pending_review' }],
            item_facets: [{ id: 'f-zeta', item_id: 'i-zeta', facet_key: 'model', picklist_value_id: null, text_value: 'Zeta Pods 9' }],
        });
        const view = drill(cat, { categoryId: 'c-ear', choices: [] }, 'retail');
        expect(view.tiles.map((t) => t.label)).toEqual(['Skip brand', 'Hoco', 'Oraimo', 'Zetasound', 'More brands']);
        expect(tile(view.tiles, 'Zetasound')).toEqual({ kind: 'value', key: 'brand', value: 'b-zeta', label: 'Zetasound' });
        const zeta = drill(cat, { categoryId: 'c-ear', choices: [{ key: 'brand', value: 'b-zeta' }] }, 'retail');
        expect(zeta.tiles.map((t) => t.label)).toEqual(['Zetasound Zeta Pods 9 Earbuds', 'New Zetasound earbuds']);
    });

    it('finds an item made with a brand after Skip brand too, and says "None yet" only when there is nothing (D102)', () => {
        const cat = earbuds();
        const skip = { categoryId: 'c-ear', choices: [{ key: 'brand', value: null }] };
        expect(drill(cat, skip, 'retail').prompt).toBe('None yet. Make one with New.');
        // Skip brand, then New, where the cashier chose Oraimo: the item is Oraimo's.
        cat.apply({
            items: [{ id: 'i-fp4', category_id: 'c-ear', brand_id: 'b-oraimo', item_code: 'A00032', display_name: 'Oraimo FreePods 4 Earbuds', search_text: '', status: 'pending_review' }],
            item_facets: [{ id: 'f-fp4', item_id: 'i-fp4', facet_key: 'model', picklist_value_id: null, text_value: 'FreePods 4' }],
        });
        const again = drill(cat, skip, 'retail');
        expect(again.prompt).toBe('Choose the item');
        expect(again.crumbs).toEqual(['Earbuds and earpods', 'Every brand']);
        expect(again.tiles.map((t) => t.label)).toEqual(['Oraimo FreePods 4 Earbuds', 'New earbuds']);
        expect(again.tiles[1]).toMatchObject({ kind: 'new', brandId: null });
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

describe('a step never adds an item (D102)', () => {
    function pouches(): Catalogue {
        const cat = new Catalogue();
        cat.apply({
            categories: [{ id: 'c-case', code: 'cases', parent_id: null, name: 'Phone pouches and covers', word: 'Pouch', sellable: true, aliases: [], position: 1 }],
            category_templates: [
                {
                    id: 't-case',
                    category_id: 'c-case',
                    facets: [
                        { key: 'brand', label: 'Made by', type: 'brand' },
                        { key: 'phone_model', label: 'Phone model', type: 'picklist', list: 'phone_models' },
                        { key: 'case_type', label: 'Type', type: 'picklist', list: 'case_types' },
                    ],
                    drill_order: ['phone_model', 'case_type', 'brand'],
                    name_order: ['brand', 'phone_model', 'case_type', '{word}'],
                    asks_colour: true,
                },
            ],
            picklist_values: [
                { id: 'p-s23', list: 'phone_models', name: 'Samsung Galaxy S23+', aliases: [], parent_id: null, status: 'active', position: 1 },
                { id: 'p-sil', list: 'case_types', name: 'Silicone', aliases: [], parent_id: null, status: 'active', position: 1 },
            ],
            brands: [
                { id: 'b-none', name: 'No brand', aliases: [], shown_first_in: [], status: 'approved', merged_into_id: null },
                { id: 'b-spigen', name: 'Spigen', aliases: [], shown_first_in: ['cases'], status: 'approved', merged_into_id: null },
            ],
            // The one pouch so far, made with "Made by: No brand" after Skip brand.
            items: [{ id: 'i-s23', category_id: 'c-case', brand_id: 'b-none', item_code: 'A00040', display_name: 'Samsung Galaxy S23+ Silicone Pouch', search_text: '', status: 'pending_review' }],
            item_facets: [
                { id: 'f-s23a', item_id: 'i-s23', facet_key: 'phone_model', picklist_value_id: 'p-s23', text_value: null },
                { id: 'f-s23b', item_id: 'i-s23', facet_key: 'case_type', picklist_value_id: 'p-sil', text_value: null },
            ],
        });
        cat.noBrandId = 'b-none';
        cat.setPrices([{ item_id: 'i-s23', retail: { set_price: 1_000_000, prefill: 1_000_000, prefill_source: 'set_price', range: null }, wholesale: { set_price: null, prefill: null, prefill_source: null, range: null }, sold_30d: 1 }]);
        return cat;
    }

    it('lists the one pouch after Skip brand, with New after it, instead of adding it', () => {
        const cat = pouches();
        // One phone model and one type so far: nothing to choose there, so the brand step comes first.
        const first = drill(cat, { categoryId: 'c-case', choices: [] }, 'retail');
        expect(first.prompt).toBe('Choose brand');
        expect(first.tiles).toEqual([
            { kind: 'skip', key: 'brand', label: 'Skip brand', hint: 'Shows every brand' },
            { kind: 'value', key: 'brand', value: 'b-spigen', label: 'Spigen' },
        ]);

        const skip = drill(cat, { categoryId: 'c-case', choices: [{ key: 'brand', value: null }] }, 'retail');
        expect(skip.prompt).toBe('Choose the item');
        expect(skip.tiles).toEqual([
            { kind: 'item', id: 'i-s23', label: 'Samsung Galaxy S23+ Silicone Pouch', priceKobo: 1_000_000, shape: null },
            { kind: 'new', id: 'new-item', label: 'New pouch', hint: 'Not here? Make it', categoryId: 'c-case', brandId: null, facets: {} },
        ]);
    });
});
