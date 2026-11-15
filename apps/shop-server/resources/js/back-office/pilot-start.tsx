import { useState } from 'react';
import { Notice } from '@/components/notice';
import type { CounterApi } from '@/counter/use-api';
import { field, button, primary } from './pilot-parts';
import type { Card } from './pilot-types';

export function StartCard({ api, card, onDone, again = false }: { api: CounterApi; card: Card; onDone: () => void; again?: boolean }) {
    const [day, setDay] = useState(card.today);
    const [note, setNote] = useState('');
    const [shown, setShown] = useState(!again);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const r = card.rules;

    if (!card.can_decide) {
        return <p className="rounded-2xl bg-panel p-5 text-lg">The owner starts the {r.days} days. Until then there is nothing to measure.</p>;
    }
    if (!shown) {
        return (
            <button type="button" className={`${button} self-start`} onClick={() => { setShown(true); }}>
                Start the {r.days} days again
            </button>
        );
    }

    const begun = card.start !== null && card.start.first_day <= card.today;
    const submit = async () => {
        setBusy(true);
        setError(null);
        try {
            const a = await api.post<{ message?: string }>('/api/back-office/pilot/start', { first_day: day, note: note.trim() === '' ? null : note.trim() });
            if (a.ok) {
                setShown(!again);
                setNote('');
                onDone();
            } else setError(a.body.message ?? 'That did not work. Try again.');
        } catch {
            setError('The device cannot reach the shop server. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <section aria-labelledby="pilot-start" className="flex flex-col gap-3 rounded-2xl border-2 border-border p-5">
            <h2 id="pilot-start" className="text-xl font-bold">
                {again ? `Start the ${String(r.days)} days again` : `Start the ${String(r.days)} days`}
            </h2>
            {again ? (
                <p className="text-text-secondary">
                    {begun ? 'Only if something stopped a fair measure, such as the counters not being set up. The days so far stay on record, and the new start is today or later.' : 'The first day has not come yet, so it can still move.'}
                </p>
            ) : (
                <ul className="flex list-disc flex-col gap-1 pl-6 text-lg">
                    <li>Every counter sells beside the notebook. Each evening, type the notebook&apos;s totals for cash, transfer and POS.</li>
                    <li>Trace each difference: was the notebook wrong, or the app?</li>
                    <li>
                        After {r.days} trading days you decide: continue with the app as the shop&apos;s record, or stop. Either way you take all your data.
                    </li>
                </ul>
            )}
            {error !== null && <Notice tone="error">{error}</Notice>}
            <div className="flex flex-wrap items-end gap-3">
                <label className="flex flex-col gap-1 text-lg font-semibold">
                    First day
                    <input type="date" value={day} min={card.today} className={field} onChange={(e) => { setDay(e.target.value); }} />
                </label>
                {again && begun && (
                    <label className="flex min-w-64 flex-1 flex-col gap-1 text-lg font-semibold">
                        Why they start again
                        <input type="text" value={note} maxLength={200} className={field} onChange={(e) => { setNote(e.target.value); }} />
                    </label>
                )}
                <button type="button" disabled={busy || day === ''} className={primary} onClick={() => void submit()}>
                    {busy ? 'Starting…' : again ? 'Start again' : `Start the ${String(r.days)} days`}
                </button>
                {again && (
                    <button type="button" className={button} onClick={() => { setShown(false); }}>
                        Cancel
                    </button>
                )}
            </div>
        </section>
    );
}
