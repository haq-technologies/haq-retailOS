import { useState } from 'react';
import { Notice } from '@/components/notice';
import type { CounterApi } from '@/counter/use-api';
import { longDay, plural } from './format';
import { field, Mark, button, primary } from './pilot-parts';
import type { Card } from './pilot-types';

export function DecisionCard({ api, card, onDone }: { api: CounterApi; card: Card; onDone: () => void }) {
    const [choice, setChoice] = useState<'continue' | 'stop' | null>(null);
    const [note, setNote] = useState('');
    const [changing, setChanging] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const d = card.decision;
    const r = card.rules;

    const record = async () => {
        if (choice === null) return;
        setBusy(true);
        setError(null);
        try {
            const a = await api.post<{ message?: string }>('/api/back-office/pilot/decision', { decision: choice, note: note.trim() === '' ? null : note.trim() });
            if (a.ok) {
                setConfirm(false);
                setChanging(false);
                setChoice(null);
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
        <section aria-labelledby="pilot-decision" className="flex flex-col gap-3 rounded-2xl border-2 border-border p-5">
            <h2 id="pilot-decision" className="text-xl font-bold">
                The owner&apos;s decision
            </h2>
            {d !== null && (
                <div className="flex flex-col gap-1 text-lg">
                    <p>
                        <Mark tone={d.decision === 'continue' ? 'good' : 'wait'} label={d.decision === 'continue' ? 'Continue with the app' : 'Stop the pilot'} />
                        <span className="text-text-secondary">
                            {' '}
                            · {d.by}, {longDay(d.at.slice(0, 10))}
                            {d.changes > 0 && ` · changed ${plural(d.changes, 'time', 'times')}`}
                        </span>
                    </p>
                    {d.note !== null && <p>&ldquo;{d.note}&rdquo;</p>}
                    {d.notebook_stops !== null && (
                        <p>
                            The app is now the shop&apos;s record. Keep the notebook going until {longDay(d.notebook_stops)}, then stop it.
                        </p>
                    )}
                    <p className="text-text-secondary">The pilot&apos;s record is closed, with the scorecard as it stood when the decision was made.</p>
                </div>
            )}
            {d === null && !card.can_decide && <p className="text-lg text-text-secondary">After the {r.days} days the owner decides here: continue or stop.</p>}
            {card.can_decide && (d === null || changing) && (
                <>
                    {d === null && card.complete !== true && <Notice tone="warning">Only {plural(card.measured ?? 0, 'day is', 'days are')} measured of {r.days}. The decision closes the record, so it is usually made after day {r.days}.</Notice>}
                    {error !== null && <Notice tone="error">{error}</Notice>}
                    <div className="grid gap-2 medium:grid-cols-2">
                        {(
                            [
                                ['continue', 'Continue', `The app becomes the shop's record now; the notebook stops ${String(r.notebook_stops_after_days)} days later.`],
                                ['stop', 'Stop', 'The pilot ends. You take all the shop’s data with you.'],
                            ] as const
                        ).map(([value, label, hint]) => (
                            <label key={value} className={`flex min-h-touch cursor-pointer items-start gap-3 rounded-xl border-2 px-4 py-3 ${choice === value ? 'border-action' : 'border-border'}`}>
                                <input type="radio" name="decision" className="mt-1 h-5 w-5" checked={choice === value} onChange={() => { setChoice(value); setConfirm(false); }} />
                                <span>
                                    <span className="block font-semibold">{label}</span>
                                    <span className="text-text-secondary">{hint}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                    <label className="flex flex-col gap-1 text-lg font-semibold">
                        {d === null ? 'Your words on it (optional)' : 'Why the decision changes'}
                        <input value={note} maxLength={200} className={field} onChange={(e) => { setNote(e.target.value); }} />
                    </label>
                    <div className="flex flex-wrap gap-3">
                        {!confirm ? (
                            <button type="button" disabled={choice === null} className={primary} onClick={() => { setConfirm(true); }}>
                                Record the decision
                            </button>
                        ) : (
                            <button type="button" disabled={busy} className={primary} onClick={() => void record()}>
                                {busy ? 'Recording…' : `Yes, record "${choice === 'continue' ? 'Continue' : 'Stop'}"`}
                            </button>
                        )}
                        {d !== null && (
                            <button type="button" className={button} onClick={() => { setChanging(false); setConfirm(false); }}>
                                Cancel
                            </button>
                        )}
                    </div>
                </>
            )}
            {card.can_decide && d !== null && !changing && (
                <button type="button" className={`${button} self-start`} onClick={() => { setChanging(true); }}>
                    Change the decision
                </button>
            )}
        </section>
    );
}
