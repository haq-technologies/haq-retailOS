/** What the review queues read from the server, and what the screen has open (spec sections 3 and 6). */
export type Named = { id: string; name: string };
export interface Queue {
    counts: { names: number; items: number; barcodes: number; duplicates: number };
    brands: { id: string; name: string; typed_by: string | null; sales: number; items: string; first_sold: string | null; looks_like: Named | null }[];
    models: { id: string; name: string; list: string; family: string | null; typed_by: string | null; sales: number; items: string; looks_like: Named | null }[];
    words: { word: string; item_ids: string[]; names: string; typed_by: string | null; sales: number; low_kobo: number | null; high_kobo: number | null }[];
    items: { id: string; name: string; typed_line: boolean; category: string; parts: string; created_by: string | null; created_at: string; sales: number; prices: number[]; brand_waiting: string | null }[];
    learned: { id: string; code: string; item_id: string; name: string; learned_by: string | null; learned_at: string; confirmations: number }[];
    conflicts: { code: string; links: { item_id: string; name: string; chosen_by: string | null; sales: number }[] }[];
    aliases: { id: string; term: string; item_id: string; name: string; hits: number; active: boolean; needed: number }[];
    duplicates: { keep: Side; merge: Side; reason: string }[];
    merges: { id: string; item_id: string; name: string; into_id: string; into_name: string; by: string; at: string; sales: number }[];
}
export interface Side {
    id: string;
    name: string;
    source: string;
    sales: number;
    barcodes: number;
    set_price_kobo: number | null;
}

export type Tab = 'names' | 'items' | 'barcodes' | 'duplicates';
export type Open =
    | { kind: 'edit'; itemId: string; title: string }
    | { kind: 'pick-item'; from: Named }
    | { kind: 'confirm-merge'; from: Named; into: Named }
    | { kind: 'pick-brand'; brand: Named }
    | { kind: 'pick-model'; model: Named & { list: string } }
    | { kind: 'category'; word: string }
    | null;
