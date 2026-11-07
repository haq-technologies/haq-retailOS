import { naira } from '@/counter/money';

export { naira };

/** "6 Nov", in the device's time (the shop's). */
export function shortDate(iso: string | null): string {
    if (iso === null) return '';
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

/** "Thursday 6 November 2026". */
export function longDay(day: string): string {
    return new Date(`${day}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

/** "08:02". */
export function clock(iso: string | null): string {
    if (iso === null) return '';
    return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

export function plural(n: number, one: string, many: string): string {
    return `${String(n)} ${n === 1 ? one : many}`;
}
