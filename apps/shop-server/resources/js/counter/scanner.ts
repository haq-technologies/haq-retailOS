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

/** A receipt number, read from a receipt's barcode: T01-E1-000123 (spec section 4). */
export function isReceiptNumber(code: string): boolean {
    return /^[A-Z]\d{2}-E\d+-\d{6}$/.test(code);
}
