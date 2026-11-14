import { describe, expect, it } from 'vitest';
import { checkPrice } from './price-rules';
import { isReceiptNumber, ScanDetector } from './scanner';
import { buildIndex, search, tokenize } from './search';

describe('the price checks', () => {
    const range = { low: 250_000, high: 300_000, source: 'item' };

    it('mirror the server: confirm outside the range, retype far above it, a manager below the floor', () => {
        expect(checkPrice(280_000, range, 50, 3)).toEqual({ kind: 'ok' });
        expect(checkPrice(280_000, null, 50, 3)).toEqual({ kind: 'first_sale' });
        expect(checkPrice(320_000, range, 50, 3)).toMatchObject({ kind: 'confirm', direction: 'above' });
        expect(checkPrice(3_000_000, range, 50, 3)).toMatchObject({ kind: 'retype' });
        expect(checkPrice(200_000, range, 50, 3)).toMatchObject({ kind: 'confirm', direction: 'below' });
        expect(checkPrice(100_000, range, 50, 3)).toMatchObject({ kind: 'approval' });
    });
});

describe('the scanner', () => {
    it('takes a fast burst ended by Enter as a scan, and typing as typing', () => {
        const d = new ScanDetector();
        let t = 1000;
        const events = '6901234567892'.split('').map((k) => d.key(k, (t += 8)));
        expect(events.slice(0, 3)).toEqual([{ kind: 'none' }, { kind: 'none' }, { kind: 'none' }]);
        expect(events[3]).toEqual({ kind: 'burst', leaked: '690', first: true });
        expect(events[4]).toEqual({ kind: 'burst', leaked: '690', first: false });
        expect(d.key('Enter', (t += 10))).toEqual({ kind: 'scan', code: '6901234567892' });

        // A person types far slower, so their Enter is theirs.
        for (const k of 'cable') d.key(k, (t += 180));
        expect(d.key('Enter', (t += 200))).toEqual({ kind: 'none' });
    });

    it('lets two or three quick keys through, as a cashier types "00"', () => {
        const d = new ScanDetector();
        expect([d.key('0', 1000), d.key('0', 1010), d.key('Enter', 1500)]).toEqual([{ kind: 'none' }, { kind: 'none' }, { kind: 'none' }]);
    });

    it("reads a receipt's barcode whole: a scanner sends Shift before each capital (D76)", () => {
        const d = new ScanDetector();
        let t = 1000;
        const keys = ['Shift', 'T', '0', '1', '-', 'Shift', 'E', '1', '-', '0', '0', '0', '1', '2', '3'];
        const events = keys.map((k) => d.key(k, (t += 8)));
        // Shift is no key of the code: the run starts at T, and is a scan from its fourth key.
        expect(events[4]).toEqual({ kind: 'burst', leaked: 'T01', first: true });
        expect(d.key('Enter', (t += 10))).toEqual({ kind: 'scan', code: 'T01-E1-000123' });
    });

    it('keeps the run alive across a Shift held between two keys, and still ends it on another key', () => {
        const d = new ScanDetector();
        // The Shift comes 20 ms after the dash and the capital 20 ms after the Shift: 40 ms apart, still one run.
        const keys: [string, number][] = [['T', 1000], ['0', 1008], ['1', 1016], ['-', 1024], ['Shift', 1044], ['E', 1064], ['1', 1072]];
        for (const [k, at] of keys) d.key(k, at);
        expect(d.key('Enter', 1080)).toEqual({ kind: 'scan', code: 'T01-E1' });

        // A person's capital, typed slowly, is typing; and an arrow key still ends a run.
        for (const [k, at] of [['Shift', 3000], ['C', 3150], ['a', 3320], ['b', 3500]] as [string, number][]) d.key(k, at);
        expect(d.key('Enter', 3700)).toEqual({ kind: 'none' });
        for (const [k, at] of [['1', 5000], ['2', 5008], ['3', 5016], ['4', 5024], ['ArrowLeft', 5032]] as [string, number][]) d.key(k, at);
        expect(d.key('Enter', 5040)).toEqual({ kind: 'none' });
    });

    it('knows a receipt number', () => {
        expect(isReceiptNumber('T01-E1-000123')).toBe(true);
        expect(isReceiptNumber('6901234567892')).toBe(false);
    });
});

describe('search', () => {
    const index = buildIndex([
        { id: 'a', name: 'Anker Type-C to Lightning Cable 1m', text: 'anker type c to lightning cable 1m', sold: 2 },
        { id: 'b', name: 'Oraimo Type-C to Lightning Cable 1m', text: 'oraimo type c to lightning cable 1m', sold: 40 },
        { id: 'c', name: 'Oraimo 20W Charger Head', text: 'oraimo 20w charger head', sold: 1 },
        { id: 'd', name: 'Oraimo Type-C to Lightning Cable 1.5m', text: 'oraimo cable 1.5m', sold: 0 },
        { id: 'e', name: 'Tecno Spark 20 Pouch', text: 'tecno spark 20 pouch earpiece', sold: 0 },
    ]);

    it('splits words on letter and digit boundaries', () => {
        expect(tokenize('20w Type-C 1.5m')).toEqual(['20', 'w', 'type', 'c', '1.5', 'm']);
    });

    it('treats "1m" as "1 m" and "20w" as "20W", and ranks by recent sales', () => {
        expect(search(index, 'cable 1m').slice(0, 2)).toEqual(['b', 'a']);
        expect(search(index, '20w')).toContain('c');
    });

    it('matches prefixes and one typo in longer words', () => {
        expect(search(index, 'lightn')).toEqual(expect.arrayContaining(['a', 'b']));
        expect(search(index, 'charjer')).toEqual(['c']);
        expect(search(index, 'earpiece')).toEqual(['e']);
    });
});
