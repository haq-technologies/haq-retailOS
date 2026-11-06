/**
 * The counter's copy of the catalogue, as the feed sends it (app/Counter/Feed.php). No cost ever reaches
 * the counter (spec section 2).
 */
export type CustomerType = 'retail' | 'wholesale';

export interface CategoryRow {
    id: string;
    code: string;
    parent_id: string | null;
    name: string;
    word: string | null;
    sellable: boolean;
    aliases: string[];
    position: number;
}

export interface FacetDefinition {
    key: string;
    label: string;
    type: 'brand' | 'picklist' | 'text';
    list?: string;
    allow_typed?: boolean;
    group_by?: string;
    default?: string;
}

export interface TemplateRow {
    id: string;
    category_id: string;
    facets: FacetDefinition[];
    drill_order: string[];
    name_order: string[];
    asks_colour: boolean;
}

export interface PicklistRow {
    id: string;
    list: string;
    name: string;
    aliases: string[];
    parent_id: string | null;
    status: string;
    position: number;
}

export interface BrandRow {
    id: string;
    name: string;
    aliases: string[];
    shown_first_in: string[];
    status: string;
    merged_into_id: string | null;
}

export interface ProductModelRow {
    id: string;
    brand_id: string | null;
    name: string;
    categories: string[];
    status: string;
}

export interface ItemRow {
    id: string;
    category_id: string;
    brand_id: string | null;
    item_code: string;
    display_name: string;
    search_text: string;
    status: string;
    merged_into_id?: string | null;
}

export interface ItemFacetRow {
    id: string;
    item_id: string;
    facet_key: string;
    picklist_value_id: string | null;
    text_value: string | null;
}

export interface BarcodeRow {
    id: string;
    code: string;
    item_id: string;
    status: 'unconfirmed' | 'confirmed' | 'disabled';
    conflicted: boolean;
    last_chosen_at: string | null;
}

export interface AliasRow {
    id: string;
    item_id: string;
    term: string;
    status: 'candidate' | 'active';
}

export interface PriceRange {
    low: number;
    high: number;
    source: string;
}

export interface PriceInfo {
    set_price: number | null;
    prefill: number | null;
    prefill_source: 'set_price' | 'last_price' | 'median' | null;
    range: PriceRange | null;
}

export interface ItemPrices {
    item_id: string;
    retail: PriceInfo;
    wholesale: PriceInfo;
    sold_30d: number;
}

export interface Shape {
    id: string;
    category_id: string;
    facets: Record<string, string>;
    display_name: string;
}

export interface FeedRows {
    categories?: CategoryRow[];
    category_templates?: TemplateRow[];
    picklist_values?: PicklistRow[];
    brands?: BrandRow[];
    product_models?: ProductModelRow[];
    items?: ItemRow[];
    item_facets?: ItemFacetRow[];
    barcodes?: BarcodeRow[];
    aliases?: AliasRow[];
}

export type FeedTable = keyof FeedRows;

export const FEED_TABLES: FeedTable[] = [
    'categories',
    'category_templates',
    'picklist_values',
    'brands',
    'product_models',
    'items',
    'item_facets',
    'barcodes',
    'aliases',
];

export interface SnapshotPage {
    seq: number;
    rows: FeedRows;
    prices: ItemPrices[];
    next: string | null;
}

export interface ChangesPage {
    seq: number;
    more: boolean;
    rows: FeedRows;
    removed: Partial<Record<FeedTable, string[]>>;
    prices: ItemPrices[];
}

export interface Person {
    id: string;
    name: string;
    role: string;
    pinLength: [number, number];
}

export interface CounterSettings {
    transferAlertTick: boolean;
    floorPercent: number;
    retypeMultiple: number;
    colours: string[];
}
