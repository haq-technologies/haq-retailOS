import { describe, expect, it } from 'vitest';
import { describeError, isIgnored, ReportLimiter } from './error-report';

describe('error reports from a screen (finding LG3)', () => {
    it('sends at most 5 a minute, and the same message once a minute', () => {
        const limiter = new ReportLimiter();
        const sent = Array.from({ length: 7 }, (_, i) => limiter.allow(`error ${String(i)}`, 1_000 + i));
        expect(sent).toEqual([true, true, true, true, true, false, false]);

        const fresh = new ReportLimiter();
        expect(fresh.allow('TypeError: x is undefined', 0)).toBe(true);
        expect(fresh.allow('TypeError: x is undefined', 30_000)).toBe(false);
        expect(fresh.allow('TypeError: x is undefined', 61_000)).toBe(true);
    });

    it('sends the name, message and stack of an error, cut to what the server takes', () => {
        const error = new TypeError('Cannot read properties of undefined');
        const details = describeError(error, '\n    at CartPanel');
        expect(details.message).toBe('TypeError: Cannot read properties of undefined');
        expect(details.stack).toContain('at CartPanel');
        expect(describeError('x'.repeat(900)).message).toHaveLength(500);
        expect(describeError({ code: 7 })).toEqual({ message: '{"code":7}', stack: null });
        // A rejection with nothing, or with an object that cannot be written out, still makes a report.
        expect(describeError(undefined).message).toBe('undefined');
        const circular: Record<string, unknown> = {};
        circular.self = circular;
        expect(describeError(circular).message).toBe('[object Object]');
    });

    it('leaves out browser noise that says nothing about the app', () => {
        expect(isIgnored('ResizeObserver loop completed with undelivered notifications.')).toBe(true);
        expect(isIgnored('Script error.')).toBe(true);
        expect(isIgnored('TypeError: x is undefined')).toBe(false);
    });
});
