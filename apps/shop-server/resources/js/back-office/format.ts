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

/** The same, inside a sentence: "today, 12:20", "yesterday, 18:12" or "on 4 Nov, 13:10". */
export function whenInline(iso: string, now: Date = new Date()): string {
    const [day = '', time = ''] = when(iso, now).split(/ (?=\d\d:\d\d$)/);
    return day === 'Today' || day === 'Yesterday' ? `${day.toLowerCase()}, ${time}` : `on ${day}, ${time}`;
}

/** An audit entry's values in words: "Total ₦5,000 · Lines 2". Amounts in kobo show as naira. */
export function describeValues(values: Record<string, unknown> | null): string {
    if (values === null) return '';
    return Object.entries(values)
        .filter(([, v]) => v !== null && v !== '' && !(Array.isArray(v) && v.length === 0))
        .map(([k, v]) => {
            const label = k.replace(/_kobo$/, '').replace(/_/g, ' ');
            const shown = k.endsWith('_kobo') && typeof v === 'number' ? naira(v) : Array.isArray(v) ? v.join(', ') : typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean' ? String(v) : JSON.stringify(v);
            return `${label.charAt(0).toUpperCase()}${label.slice(1)} ${shown}`;
        })
        .join(' · ');
}
