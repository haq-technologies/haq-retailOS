import type { PriceRange } from './types';

/**
 * The price checks, as the server applies them at Finish (app/Sales/PriceRules.php) [F11, S5], so the
 * cashier sees the prompt before Pay. The server checks again, and counts the below-range confirms.
 */
export type PriceVerdict =
    | { kind: 'ok' }
    | { kind: 'first_sale' }
    | { kind: 'confirm'; direction: 'above' | 'below'; range: PriceRange }
    | { kind: 'retype'; range: PriceRange }
    | { kind: 'approval'; range: PriceRange };

export function checkPrice(priceKobo: number, range: PriceRange | null, floorPercent: number, retypeMultiple: number): PriceVerdict {
    if (range === null) return { kind: 'first_sale' };
    if (priceKobo > range.high) {
        return priceKobo > range.high * retypeMultiple ? { kind: 'retype', range } : { kind: 'confirm', direction: 'above', range };
    }
    if (priceKobo < range.low) {
        const floor = Math.floor((range.low * floorPercent) / 100);
        return priceKobo < floor ? { kind: 'approval', range } : { kind: 'confirm', direction: 'below', range };
    }
    return { kind: 'ok' };
}

export function usualLabel(range: PriceRange, naira: (k: number) => string): string {
    return range.low === range.high ? `Usual ${naira(range.low)}` : `Usual ${naira(range.low)} to ${naira(range.high)}`;
}
