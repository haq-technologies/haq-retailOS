import { describe, expect, it } from 'vitest';
import { digitsToKobo, naira, quickCash, typeDigits } from './money';

describe('money on the counter', () => {
    it('shows kobo as naira with grouping, and kobo only when there are some', () => {
        expect(naira(1_400_000)).toBe('₦14,000');
        expect(naira(250)).toBe('₦2.50');
        expect(naira(0)).toBe('₦0');
        expect(naira(-50_000)).toBe('−₦500');
        expect(naira(123_456_789_00)).toBe('₦123,456,789');
    });

    it('types whole naira on the pad, with 00, Clear and Delete', () => {
        let d = '';
        for (const key of ['2', '5', '00']) {
            d = typeDigits(d, key);
        }
        expect(d).toBe('2500');
        expect(digitsToKobo(d)).toBe(250_000);
        expect(typeDigits(d, 'delete')).toBe('250');
        expect(typeDigits(d, 'clear')).toBe('');
        expect(typeDigits('', '0')).toBe('');
        expect(typeDigits('999999999', '1')).toBe('999999999');
    });

    it('offers quick cash amounts above the total', () => {
        expect(quickCash(2_250_000)).toEqual([2_300_000, 2_500_000, 3_000_000]);
        expect(quickCash(500_000)).toEqual([1_000_000]);
    });
});
