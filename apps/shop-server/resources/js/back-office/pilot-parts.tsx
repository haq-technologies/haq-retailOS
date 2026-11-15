import { CheckIcon, ClockIcon, WarningIcon } from '@/components/icons';
import { naira } from './format';
import { styles } from './pickers';
import type { Status } from './pilot-types';

/* The small parts the measured pilot's screens share: the verdict marks, a measure's card and its words. */
export const { button, primary } = styles;
export const field = 'min-h-touch rounded-xl border-2 border-border bg-background px-3 text-lg font-normal';
export const methodName: Record<string, string> = { cash: 'Cash', transfer: 'Transfer', pos: 'POS' };

/** A day's verdict in words, with an icon: never colour alone (spec section 15). */
export const verdicts: Record<string, { label: string; tone: 'good' | 'bad' | 'wait' }> = {
    matches: { label: 'Matches', tone: 'good' },
    matches_traced: { label: 'Matches once traced', tone: 'good' },
    app_wrong: { label: 'Does not match', tone: 'bad' },
    to_trace: { label: 'Still to trace', tone: 'wait' },
    no_notebook: { label: 'Notebook not entered', tone: 'wait' },
};

export const statuses: Record<Status, { label: string; tone: 'good' | 'bad' | 'wait' }> = {
    passed: { label: 'Met', tone: 'good' },
    failed: { label: 'Not met', tone: 'bad' },
    open: { label: 'In progress', tone: 'wait' },
};

export function Mark({ tone, label }: { tone: 'good' | 'bad' | 'wait'; label: string }) {
    const colour = tone === 'good' ? 'text-pay' : tone === 'bad' ? 'text-destructive' : 'text-text-secondary';
    const Icon = tone === 'good' ? CheckIcon : tone === 'bad' ? WarningIcon : ClockIcon;
    return (
        <span className={`inline-flex items-center gap-2 font-semibold ${colour}`}>
            <Icon className="h-5 w-5 shrink-0" />
            {label}
        </span>
    );
}

export function seconds(ms: number | null): string {
    return ms === null ? '–' : `${String(Math.round(ms / 1000))} s`;
}

/** The difference as words: "₦2,000 more in the app". */
export function difference(kobo: number): string {
    if (kobo === 0) return 'Same';
    return `${naira(Math.abs(kobo))} more in the ${kobo > 0 ? 'app' : 'notebook'}`;
}

export function Measure({ title, status, children }: { title: string; status: Status; children: React.ReactNode }) {
    const s = statuses[status];
    return (
        <section aria-label={title} className={`flex flex-col gap-2 rounded-2xl p-4 ${status === 'failed' ? 'border-2 border-destructive bg-background' : 'bg-panel'}`}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-lg font-bold">{title}</h2>
                <Mark tone={s.tone} label={s.label} />
            </div>
            {children}
        </section>
    );
}
