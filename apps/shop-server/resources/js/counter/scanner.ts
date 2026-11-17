/**
 * Scanner input is told from typing by timing: keys under 30 ms apart, ended by Enter (spec section 7) [S1].
 * The scan goes to the barcode handler wherever focus is, and its Enter never triggers payment.
 *
 * Nobody types four keys in a row under 30 ms apart, but two quick keys ("00") happen, so the first three
 * keys of a fast run pass through as typed; from the fourth the run is a scan, and "leaked" says which
 * three keys to take back from the search field.
 */
export type ScanEvent = { kind: 'none' } | { kind: 'burst'; leaked: string; first: boolean } | { kind: 'scan'; code: string };

const PASS_THROUGH = 3;

/**
 * Keys that type nothing themselves. A scanner sends Shift before each capital, as a keyboard does, so a
 * receipt's barcode (T01-E1-000123) arrives as Shift, T, 0, 1, -, Shift, E and so on; they are no part of
 * the code and never end the run (D76).
 */
const MODIFIERS = new Set(['Shift', 'CapsLock', 'Control', 'Alt', 'AltGraph', 'Meta']);

export class ScanDetector {
    private buffer = '';
    private last = Number.NEGATIVE_INFINITY;

    constructor(
        private readonly gapMs = 30,
        private readonly minLength = 4,
    ) {}

    key(key: string, at: number): ScanEvent {
        if (key === 'Enter') {
            const code = this.buffer;
            const fresh = at - this.last < this.gapMs * 4;
            this.reset();
            return code.length >= this.minLength && fresh ? { kind: 'scan', code } : { kind: 'none' };
        }
        if (MODIFIERS.has(key)) {
            // Inside a fast run it keeps the run alive, so the capital after it is still counted as fast.
            if (this.buffer !== '' && at - this.last < this.gapMs) this.last = at;
            return { kind: 'none' };
        }
        if (key.length !== 1) {
            this.reset();
            return { kind: 'none' };
        }
        const fast = at - this.last < this.gapMs;
        this.buffer = fast && this.buffer !== '' ? this.buffer + key : key;
        this.last = at;
        return this.buffer.length > PASS_THROUGH ? { kind: 'burst', leaked: this.buffer.slice(0, PASS_THROUGH), first: this.buffer.length === PASS_THROUGH + 1 } : { kind: 'none' };
    }

    reset(): void {
        this.buffer = '';
        this.last = Number.NEGATIVE_INFINITY;
    }
}

/** The longest code a barcode keeps, as on the server. A longer QR text is read but never learned (D83). */
export const MAX_CODE = 120;

/**
 * Junk codes never learn (spec section 6) [F13]: shorter than 6 characters, one character repeated (000000),
 * already linked to more than 5 items, or longer than a barcode keeps (D83).
 */
export function isJunkCode(code: string, linked: number): boolean {
    return code.length < 6 || code.length > MAX_CODE || /^(.)\1+$/u.test(code) || linked > 5;
}

/** A receipt number, read from a receipt's barcode: T01-E1-000123 (spec section 4). */
export function isReceiptNumber(code: string): boolean {
    return /^[A-Z]\d{2}-E\d+-\d{6}$/.test(code);
}
