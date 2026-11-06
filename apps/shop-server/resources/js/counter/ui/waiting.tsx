import { useState } from 'react';
import { ClockIcon, WarningIcon } from '@/components/icons';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import type { Tile } from '@/components/sign-in-panel';
import { naira } from '../money';
import type { CounterApi } from '../use-api';
import { ApprovalPad } from './approval-pad';
import type { ParkedEntry } from './types';

/**
 * Waiting sales (wireframe WaitingSales): Pending transfers and Held sales from every counter in the
 * branch. "Alert seen" takes a transfer cart here to finish it; Resume takes a held cart; Discard needs a
 * reason, and a manager above the cashier's limit or for someone else's sale [F12, S2].
 */
interface Props {
    parked: ParkedEntry[];
    api: CounterApi;
    people: Tile[];
    canResume: boolean;
    onResume: (entry: ParkedEntry) => void;
    onChanged: () => void;
    onClose: () => void;
}

const reasons = ['Customer left', 'Customer changed their mind', 'Held twice by mistake'];

function age(seconds: number): string {
    const minutes = Math.floor(seconds / 60);
    return minutes < 1 ? 'just now' : minutes < 60 ? `${String(minutes)} min` : `${String(Math.floor(minutes / 60))} h ${String(minutes % 60)} min`;
}

export function WaitingSales({ parked, api, people, canResume, onResume, onChanged, onClose }: Props) {
    const [discarding, setDiscarding] = useState<ParkedEntry | null>(null);
    const [reason, setReason] = useState('');
    const [approval, setApproval] = useState<{ action: string; subject_id: string } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const transfers = parked.filter((p) => p.kind === 'awaiting_transfer');
    const held = parked.filter((p) => p.kind === 'hold');

    const discard = async (entry: ParkedEntry, token: string | null) => {
        setError(null);
        try {
            const answer = await api.post<{ message?: string; approval?: { action: string; subject_id: string } }>(`/api/counter/parked/${entry.id}/discard`, {
                reason,
                approval_token: token,
            });
            if (answer.ok) {
                setDiscarding(null);
                setApproval(null);
                setReason('');
                onChanged();
            } else if (answer.status === 403 && answer.body.approval !== undefined) {
                setApproval(answer.body.approval);
            } else {
                setError(answer.body.message ?? 'That did not discard. Try again.');
            }
        } catch {
            setError('The counter cannot reach the server. Try again.');
        }
    };

    if (discarding !== null) {
        return (
            <Sheet
                title="Discard this sale?"
                onClose={() => {
                    setDiscarding(null);
                    setApproval(null);
                }}
            >
                <p className="text-lg">
                    {discarding.label ?? 'A held sale'} · {naira(discarding.amount_kobo)} · {discarding.terminal} · {discarding.parked_by_name}
                </p>
                <p className="font-semibold">Why is it discarded?</p>
                <div className="flex flex-wrap gap-2">
                    {reasons.map((r) => (
                        <button
                            key={r}
                            type="button"
                            aria-pressed={reason === r}
                            className={`min-h-touch rounded-xl border-2 px-4 ${reason === r ? 'border-action font-semibold' : 'border-border'}`}
                            onClick={() => {
                                setReason(r);
                            }}
                        >
                            {r}
                        </button>
                    ))}
                </div>
                <input
                    type="text"
                    aria-label="Reason"
                    placeholder="Or type the reason"
                    value={reasons.includes(reason) ? '' : reason}
                    maxLength={200}
                    className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                    onChange={(e) => {
                        setReason(e.target.value);
                    }}
                />
                {error !== null && <Notice tone="error">{error}</Notice>}
                {approval !== null ? (
                    <>
                        <Notice tone="warning">A manager must approve this discard.</Notice>
                        <ApprovalPad
                            people={people}
                            api={api}
                            action={approval.action}
                            subjectId={approval.subject_id}
                            onApproved={(token) => {
                                void discard(discarding, token);
                            }}
                        />
                    </>
                ) : (
                    <button
                        type="button"
                        disabled={reason.trim().length < 3}
                        className="min-h-touch rounded-xl bg-destructive text-lg font-bold text-white disabled:opacity-60"
                        onClick={() => void discard(discarding, null)}
                    >
                        <span className="inline-flex items-center gap-2">
                            <WarningIcon /> Discard sale
                        </span>
                    </button>
                )}
            </Sheet>
        );
    }

    return (
        <Sheet title="Waiting sales" onClose={onClose} wide>
            <p className="text-text-secondary">Every counter in this branch</p>
            {!canResume && <Notice tone="warning">Hold or finish the sale on this counter first, then take another.</Notice>}
            <section aria-label="Pending transfers" className="flex flex-col gap-2">
                <h3 className="text-xl font-semibold">Pending transfers ({transfers.length})</h3>
                {transfers.length === 0 && <p className="text-text-secondary">None.</p>}
                {transfers.map((p) => (
                    <div key={p.id} className={`flex flex-wrap items-center gap-3 rounded-xl border-2 p-3 ${p.flagged ? 'border-warning' : 'border-border'}`}>
                        <div className="flex flex-1 flex-col">
                            <span className="text-lg font-semibold">{p.label ?? 'Transfer'}</span>
                            <span className="text-text-secondary">
                                {p.terminal} · {p.parked_by_name}
                            </span>
                        </div>
                        <span className={`flex items-center gap-1 ${p.flagged ? 'font-semibold text-warning-text' : 'text-text-secondary'}`}>
                            {p.flagged ? <WarningIcon /> : <ClockIcon />}
                            {age(p.age_seconds)}
                            {p.flagged ? ', over 30' : ''}
                        </span>
                        <strong className="text-xl">{naira(p.amount_kobo)}</strong>
                        <button
                            type="button"
                            disabled={!canResume}
                            className="min-h-touch rounded-xl bg-action px-4 text-lg font-bold text-white disabled:opacity-60"
                            onClick={() => {
                                onResume(p);
                            }}
                        >
                            Alert seen
                        </button>
                    </div>
                ))}
            </section>
            <section aria-label="Held sales" className="flex flex-col gap-2">
                <h3 className="text-xl font-semibold">Held sales ({held.length})</h3>
                {held.length === 0 && <p className="text-text-secondary">None.</p>}
                {held.map((p) => (
                    <div key={p.id} className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-border p-3">
                        <div className="flex flex-1 flex-col">
                            <span className="text-lg font-semibold">{p.label ?? 'Held sale'}</span>
                            <span className="text-text-secondary">
                                {p.terminal} · {p.parked_by_name} · held {age(p.age_seconds)} ago
                            </span>
                        </div>
                        <strong className="text-xl">{naira(p.amount_kobo)}</strong>
                        <button
                            type="button"
                            className="min-h-touch rounded-xl border-2 border-destructive px-4 text-lg font-semibold text-destructive"
                            onClick={() => {
                                setDiscarding(p);
                                setReason('');
                                setError(null);
                            }}
                        >
                            Discard
                        </button>
                        <button
                            type="button"
                            disabled={!canResume}
                            className="min-h-touch rounded-xl bg-action px-4 text-lg font-bold text-white disabled:opacity-60"
                            onClick={() => {
                                onResume(p);
                            }}
                        >
                            Resume
                        </button>
                    </div>
                ))}
            </section>
            <p className="text-text-secondary">
                Alert seen asks for the bank-alert tick and the reference, then prints the receipt. Held sales must be resumed or discarded before the shift closes, and
                a discard needs a reason.
            </p>
        </Sheet>
    );
}
