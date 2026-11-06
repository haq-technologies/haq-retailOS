import MiniSearch from 'minisearch';

/**
 * Search (spec section 7) [S10, R2]: words split on letter and digit boundaries ("1m" is "1 m", "20w" is
 * "20W"), prefixes match, one typo is allowed in words of 4 or more letters, and items sold more in the
 * last 30 days rank first. It covers item names, active aliases and the generated shapes not yet sold.
 */
export interface SearchDoc {
    id: string;
    name: string;
    text: string;
    sold: number;
}

export function tokenize(text: string): string[] {
    return text.toLowerCase().match(/\d+(?:\.\d+)?|\p{L}+/gu) ?? [];
}

export function buildIndex(docs: SearchDoc[]): MiniSearch<SearchDoc> {
    const index = new MiniSearch<SearchDoc>({
        fields: ['name', 'text'],
        storeFields: ['sold'],
        tokenize,
        processTerm: (term) => term.toLowerCase(),
        searchOptions: {
            combineWith: 'AND',
            prefix: true,
            fuzzy: (term) => (term.length >= 4 && /^\p{L}+$/u.test(term) ? 1 : false),
            boost: { name: 2 },
            boostDocument: (_id, _term, stored) => 1 + Math.log1p(typeof stored?.sold === 'number' ? stored.sold : 0) * 0.5,
        },
    });
    index.addAll(docs);
    return index;
}

export function search(index: MiniSearch<SearchDoc>, query: string, limit = 24): string[] {
    if (tokenize(query).length === 0) return [];
    return index
        .search(query)
        .slice(0, limit)
        .map((r) => String(r.id));
}

export function upsert(index: MiniSearch<SearchDoc>, doc: SearchDoc): void {
    if (index.has(doc.id)) index.discard(doc.id);
    index.add(doc);
}
