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

/** "Today 12:20", "Yesterday 18:12" or "4 Nov 13:10", in the device's time (the shop's). */
export function when(iso: string, now: Date = new Date()): string {
    const at = new Date(iso);
    const day = (d: Date) => d.toLocaleDateString('en-CA');
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);
    const prefix = day(at) === day(now) ? 'Today' : day(at) === day(yesterday) ? 'Yesterday' : shortDate(iso);
    return `${prefix} ${clock(iso)}`;
}
