import { describe, expect, it } from 'vitest';
import { describeValues, when, whenInline } from './format';

describe('back-office times', () => {
    const now = new Date('2026-11-07T15:00:00');
    it('says today and yesterday, and the date otherwise', () => {
        expect(when('2026-11-07T12:20:00', now)).toBe('Today 12:20');
        expect(when('2026-11-06T18:12:00', now)).toBe('Yesterday 18:12');
        expect(when('2026-11-04T13:10:00', now)).toBe('4 Nov 13:10');
    });
    it('reads inside a sentence', () => {
        expect(whenInline('2026-11-07T12:20:00', now)).toBe('today, 12:20');
        expect(whenInline('2026-11-04T13:10:00', now)).toBe('on 4 Nov, 13:10');
    });
});

describe('audit values in words', () => {
    it('names each value and shows kobo as naira, leaving out empty ones', () => {
        expect(describeValues({ receipt_number: 'T01-E1-000001', total_kobo: 500_000, lines: 2, payments: ['cash'], note: null })).toBe('Receipt number T01-E1-000001 · Total ₦5,000 · Lines 2 · Payments cash');
        expect(describeValues(null)).toBe('');
    });
});
