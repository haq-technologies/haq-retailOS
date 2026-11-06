import { useCallback, useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import { postJson } from '@/lib/api';
import { canSubmit, emptyPinPad, keyFromKeyboard, press, type PinPadKey, type PinPadState } from '@/lib/pin-pad';

/**
 * Name tiles and the PIN pad (spec section 3, mode A): tap your name, type your PIN. Used by the counter
 * and by the back-office sign-in. The acting-user token it returns lives only in memory.
 */
export interface Tile {
    id: string;
    name: string;
    role: string;
    pinLength: [number, number];
}

export interface SignedIn {
    token: string;
    user: { id: string; name: string; role: string };
}

interface PinAnswer {
    outcome: string;
    message?: string;
    token?: string;
    user?: SignedIn['user'];
}

export const roleLabel = (role: string) => role.charAt(0).toUpperCase() + role.slice(1);
const padKeys: PinPadKey[] = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'clear', '0', 'delete'];

interface Props {
    tiles: Tile[];
    onSignedIn: (session: SignedIn) => void;
}

export function SignInPanel({ tiles, onSignedIn }: Props) {
    const [chosen, setChosen] = useState<Tile | null>(null);
    const [pad, setPad] = useState<PinPadState>(emptyPinPad);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const back = useCallback(() => {
        setChosen(null);
        setPad(emptyPinPad);
        setError(null);
    }, []);

    const submit = useCallback(async () => {
        if (chosen === null || busy || !canSubmit(pad, chosen.pinLength[0])) {
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const answer = await postJson<PinAnswer>('/api/identity/pin', { user_id: chosen.id, pin: pad.digits });
            if (answer.ok && answer.body.token !== undefined && answer.body.user !== undefined) {
                onSignedIn({ token: answer.body.token, user: answer.body.user });
                back();
            } else {
                setError(answer.body.message ?? 'Something went wrong. Try again.');
            }
        } catch {
            setError('The counter cannot reach the server. Check the cable, then try again.');
        } finally {
            setPad(emptyPinPad);
            setBusy(false);
        }
    }, [chosen, busy, pad, onSignedIn, back]);

    // Keyboard and mouse are full fallbacks to touch (spec section 2): digits, Backspace, Enter and Esc.
    useEffect(() => {
        if (chosen === null) {
            return;
        }
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Enter') {
                void submit();
            } else if (event.key === 'Escape') {
                back();
            } else {
                const key = keyFromKeyboard(event.key);
                if (key !== null) {
                    setPad((current) => press(current, key, chosen.pinLength[1]));
                }
            }
        };
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('keydown', onKey);
        };
    }, [chosen, submit, back]);

    if (chosen === null) {
        return (
            <section aria-label="Name tiles" className="grid grid-cols-2 gap-3 medium:grid-cols-3 large:grid-cols-4">
                {tiles.length === 0 && <p className="col-span-full text-lg text-text-secondary">Nobody can sign in yet. Add people with shop:add-user.</p>}
                {tiles.map((tile) => (
                    <button
                        key={tile.id}
                        type="button"
                        className="flex min-h-24 flex-col items-start justify-center rounded-xl border-2 border-border bg-background px-4 py-3 text-left"
                        onClick={() => {
                            setChosen(tile);
                        }}
                    >
                        <span className="text-xl font-semibold">{tile.name}</span>
                        <span className="text-text-secondary">{roleLabel(tile.role)}</span>
                    </button>
                ))}
            </section>
        );
    }

    return (
        <section aria-label={`PIN for ${chosen.name}`} className="mx-auto flex w-full max-w-sm flex-col gap-4">
            <p className="text-xl">
                PIN for <strong>{chosen.name}</strong>
            </p>
            <p aria-live="polite" className="flex min-h-touch items-center justify-center gap-3 rounded-xl bg-panel text-3xl">
                {Array.from({ length: chosen.pinLength[1] }, (_, i) => (
                    <span key={i} aria-hidden="true">
                        {i < pad.digits.length ? '●' : '○'}
                    </span>
                ))}
                <span className="sr-only">{pad.digits.length} digits typed</span>
            </p>
            {error !== null && <Notice tone="error">{error}</Notice>}
            <div className="grid grid-cols-3 gap-3">
                {padKeys.map((key) => (
                    <button
                        key={key}
                        type="button"
                        disabled={busy}
                        className="min-h-touch rounded-xl border-2 border-border bg-background text-2xl font-semibold"
                        onClick={() => {
                            setPad((current) => press(current, key, chosen.pinLength[1]));
                        }}
                    >
                        {key === 'delete' ? 'Delete' : key === 'clear' ? 'Clear' : key}
                    </button>
                ))}
            </div>
            <div className="grid grid-cols-2 gap-3">
                <button type="button" className="min-h-touch rounded-xl border-2 border-border text-lg" onClick={back}>
                    Back
                </button>
                <button
                    type="button"
                    disabled={busy || !canSubmit(pad, chosen.pinLength[0])}
                    className="min-h-touch rounded-xl bg-action text-lg font-bold text-white disabled:opacity-60"
                    onClick={() => void submit()}
                >
                    Sign in
                </button>
            </div>
        </section>
    );
}
