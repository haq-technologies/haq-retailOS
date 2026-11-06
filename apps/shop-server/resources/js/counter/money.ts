/** Money on the counter's screen: integer kobo in, ₦ out (spec section 15). */
export function naira(kobo: number): string {
    const sign = kobo < 0 ? '−' : '';
    const abs = Math.abs(Math.trunc(kobo));
    const whole = Math.floor(abs / 100)
        .toString()
        .replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const part = abs % 100;
    return `${sign}₦${whole}${part === 0 ? '' : `.${part.toString().padStart(2, '0')}`}`;
}

/** The price pad types whole naira; "00" adds two zeros. Returns the new digits, at most 9. */
export function typeDigits(current: string, key: string): string {
    if (key === 'clear') {
        return '';
    }
    if (key === 'delete') {
        return current.slice(0, -1);
    }
    if (!/^(\d|00)$/.test(key)) {
        return current;
    }
    const next = (current + key).replace(/^0+/, '');
    return next.length > 9 ? current : next;
}

export function digitsToKobo(digits: string): number {
    return digits === '' ? 0 : Number.parseInt(digits, 10) * 100;
}

export function koboToDigits(kobo: number | null): string {
    return kobo === null || kobo <= 0 ? '' : Math.floor(kobo / 100).toString();
}

/** Quick cash amounts above a total: the next ₦1,000, ₦5,000 and ₦10,000 (as on the wireframe). */
export function quickCash(totalKobo: number): number[] {
    const steps = [100_000, 500_000, 1_000_000];
    const out = new Set<number>();
    for (const step of steps) {
        const up = Math.ceil(totalKobo / step) * step;
        if (up > totalKobo) {
            out.add(up);
        }
    }
    return [...out].sort((a, b) => a - b).slice(0, 3);
}
