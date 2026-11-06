import { useCallback, useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import type { Tile } from '@/components/sign-in-panel';
import { roleLabel } from '@/components/sign-in-panel';
import { keyFromKeyboard, press, type PinPadKey } from '@/lib/pin-pad';
import type { CounterApi } from '../use-api';

/**
 * A manager's or the owner's PIN on the same screen (spec section 3) [S3]: single-use, bound to one action
 * (this line at this price, this discard). It never changes who is signed in. Wireframe: PriceManager.
 */
interface Props {
    people: Tile[];
    api: CounterApi;
    action: string;
    subjectId: string;
    itemId?: string | null;
    priceKobo?: number | null;
    onApproved: (token: string) => void;
}

const keys: PinPadKey[] = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'clear', '0', 'delete'];

export function ApprovalPad({ people, api, action, subjectId, itemId = null, priceKobo = null, onApproved }: Props) {
    const approvers = people.filter((p) => p.role === 'manager' || p.role === 'owner');
    const [chosen, setChosen] = useState<Tile | null>(approvers.length === 1 ? (approvers[0] ?? null) : null);
    const [digits, setDigits] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const length = chosen?.pinLength[1] ?? 6;

    const submit = useCallback(
        async (approver: Tile, pin: string) => {
            setBusy(true);
            try {
                const answer = await api.post<{ approval_token?: string; message?: string }>('/api/identity/approvals', {
                    approver_id: approver.id,
                    pin,
                    action,
                    subject_id: subjectId,
                    item_id: itemId,
                    price_kobo: priceKobo,
                });
                if (answer.ok && answer.body.approval_token !== undefined) {
                    onApproved(answer.body.approval_token);
                } else {
                    setError(answer.body.message ?? 'That PIN did not approve it. Try again.');
                }
            } catch {
                setError('The counter cannot reach the server. Try again.');
            } finally {
                setDigits('');
                setBusy(false);
            }
        },
        [api, action, subjectId, itemId, priceKobo, onApproved],
    );

    // The PIN goes as soon as it is complete, as the wireframe shows ("3 of 6 digits entered").
    const typeKey = useCallback(
        (key: PinPadKey) => {
            if (chosen === null || busy) return;
            const next = press({ digits }, key, length).digits;
            setDigits(next);
            if (next.length === length) void submit(chosen, next);
        },
        [chosen, busy, digits, length, submit],
    );

    useEffect(() => {
        if (chosen === null) return;
        const onKey = (event: KeyboardEvent) => {
            const key = keyFromKeyboard(event.key);
            if (key !== null) {
                event.preventDefault();
                typeKey(key);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('keydown', onKey);
        };
    }, [chosen, typeKey]);

    if (approvers.length === 0) {
        return <Notice tone="warning">There is no manager or owner who can sign in here. Add one with shop:add-user.</Notice>;
    }

    return (
        <div className="flex flex-col gap-3">
            <p className="text-lg font-semibold">Who approves?</p>
            <div className="grid grid-cols-2 gap-2">
                {approvers.map((p) => (
                    <button
                        key={p.id}
                        type="button"
                        aria-pressed={chosen?.id === p.id}
                        className={`flex min-h-touch flex-col items-start justify-center rounded-xl border-2 px-3 py-2 text-left ${chosen?.id === p.id ? 'border-action bg-panel' : 'border-border'}`}
                        onClick={() => {
                            setChosen(p);
                            setDigits('');
                            setError(null);
                        }}
                    >
                        <span className="text-lg font-semibold">{p.name}</span>
                        <span className="text-text-secondary">
                            {roleLabel(p.role)}
                            {chosen?.id === p.id ? ' · selected' : ''}
                        </span>
                    </button>
                ))}
            </div>
            {chosen !== null && (
                <>
                    <p className="text-lg">
                        {chosen.name}, enter your PIN <span className="text-text-secondary">· {digits.length} of {length} digits entered</span>
                    </p>
                    {error !== null && <Notice tone="error">{error}</Notice>}
                    <div className="grid grid-cols-3 gap-2">
                        {keys.map((key) => (
                            <button
                                key={key}
                                type="button"
                                disabled={busy}
                                className="min-h-touch rounded-xl border-2 border-border text-2xl font-semibold"
                                onClick={() => {
                                    typeKey(key);
                                }}
                            >
                                {key === 'clear' ? 'Clear' : key === 'delete' ? 'Delete' : key}
                            </button>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}
