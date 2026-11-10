import { describe, expect, it } from 'vitest';
import { buildIndex, search, type SearchDoc } from './search';

/*
 * Check A2 (spec section 14) [S10]: typing "ora free3c" finds Oraimo FreePods 3C Earbuds in under 100 ms
 * from the browser's copy with 5,000 items, and a one-letter typo still finds it. The 5,000 are made the
 * way a shop's catalogue looks: brands, phone models, products and sizes, many sharing words.
 */
const brands = ['Oraimo', 'Anker', 'Baseus', 'Itel', 'Tecno', 'Infinix', 'Samsung', 'Apple', 'New Age', 'Hoco', 'Remax', 'Xiaomi'];
const products = ['FreePods', 'PowerBank', 'Cable', 'Charger Head', 'Earbuds', 'Pouch', 'Screen Protector', 'Car Charger', 'Speaker', 'Neckband'];
const sizes = ['1m', '2m', '20W', '33W', '10000mAh', '20000mAh', 'Pro', 'Lite', '3C', '4', 'Max', 'Mini'];

function catalogue(): SearchDoc[] {
    const docs: SearchDoc[] = [{ id: 'target', name: 'Oraimo FreePods 3C Earbuds', text: 'oraimo freepods 3c earbuds', sold: 3 }];
    for (let n = 0; docs.length < 5000; n++) {
        const brand = brands[n % brands.length] ?? '';
        const product = products[(n >> 2) % products.length] ?? '';
        const size = sizes[(n >> 5) % sizes.length] ?? '';
        // A shop has one Oraimo FreePods 3C; its neighbours share some of its words, never all.
        if (brand === 'Oraimo' && product === 'FreePods' && size === '3C') continue;
        const name = `${brand} ${product} ${size} ${String(n)}`;
        docs.push({ id: `i${String(n)}`, name, text: name.toLowerCase(), sold: n % 7 });
    }
    return docs;
}

describe('counter search at 5,000 items (check A2)', () => {
    const index = buildIndex(catalogue());

    it('finds "ora free3c" first, in under 100 ms', () => {
        search(index, 'warm up');
        expect(search(index, 'ora free3c')[0]).toBe('target');
        // The median of 15 searches, so a busy CI machine's one slow moment does not fail the check.
        const times = Array.from({ length: 15 }, () => {
            const started = performance.now();
            search(index, 'ora free3c');
            return performance.now() - started;
        }).sort((a, b) => a - b);
        expect(times[7]).toBeLessThan(100);
    });

    it('still finds it with a one-letter typo', () => {
        expect(search(index, 'oraimo freepids 3c')[0]).toBe('target');
        expect(search(index, 'oraino free3c')[0]).toBe('target');
    });
});
