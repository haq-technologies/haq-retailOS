import { describe, expect, it } from 'vitest';
import { Backoff, MAX_WAIT_MS } from './backoff';

describe('Backoff (finding LG8)', () => {
    it('keeps its pace while the server answers well, or does not answer at all', () => {
        const b = new Backoff(2000);
        b.answered(200);
        expect(b.delayMs()).toBe(2000);
        b.answered(0);
        expect(b.delayMs()).toBe(2000);
        // A restart's 503 is not a fault to slow down for: the counter must see the server back at once.
        b.answered(503);
        expect(b.delayMs()).toBe(2000);
    });

    it('doubles the wait after each server error, up to 30 seconds, and comes back on a good answer', () => {
        const b = new Backoff(2000);
        const waits = [1, 2, 3, 4, 5].map(() => {
            b.answered(500);
            return b.delayMs();
        });
        expect(waits).toEqual([4000, 8000, 16000, MAX_WAIT_MS, MAX_WAIT_MS]);
        b.answered(200);
        expect(b.delayMs()).toBe(2000);
    });

    it('skips interval ticks to wait out the same backoff', () => {
        const b = new Backoff(3000);
        b.answered(500);
        b.answered(500);
        // 12 s at a 3 s pace: three ticks wait, the fourth polls.
        expect([b.tick(), b.tick(), b.tick(), b.tick()]).toEqual([false, false, false, true]);
        b.answered(200);
        expect(b.tick()).toBe(true);
    });
});
