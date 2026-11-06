import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Notice } from '@/components/notice';
import { postJson } from '@/lib/api';
import { canSubmit, emptyPinPad, keyFromKeyboard, press, type PinPadKey, type PinPadState } from '@/lib/pin-pad';

interface Tile {
    id: string;
    name: string;
    role: string;
    pinLength: [number, number];
}

interface Props {
    terminal: { code: string; name: string };
    tiles: Tile[];
    idleLockMinutes: number;
}

interface SignedIn {
    token: string;
    user: { id: string; name: string; role: string };
}

interface PinAnswer {
    outcome: string;
    message?: string;
    token?: string;
    user?: SignedIn['user'];
}

const roleLabel = (role: string) => role.charAt(0).toUpperCase() + role.slice(1);
const padKeys: PinPadKey[] = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'clear', '0', 'delete'];

/**
 * The counter's sign-in (spec section 3, mode A): tap your name tile, type your PIN. After the idle
 * limit the counter returns to the name tiles (S6); the sale in progress will be kept when the counter
 * exists (Phase 3). The acting-user token lives only in memory.
 */
export default function SignIn({ terminal, tiles, idleLockMinutes }: Props) {
    const [chosen, setChosen] = useState<Tile | null>(null);
    const [pad, setPad] = useState<PinPadState>(emptyPinPad);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [session, setSession] = useState<SignedIn | null>(null);
    const [lockedNote, setLockedNote] = useState<string | null>(null);
    const idleTimer = useRef<number | undefined>(undefined);

    const backToTiles = useCallback((note: string | null) => {
        setSession(null);
        setChosen(null);
        setPad(emptyPinPad);
        setError(null);
        setLockedNote(note);
    }, []);

    // The idle lock: any touch or key restarts the clock.
    useEffect(() => {
        if (session === null || idleLockMinutes <= 0) {
            return;
        }
        const restart = () => {
            window.clearTimeout(idleTimer.current);
            idleTimer.current = window.setTimeout(() => {
                backToTiles(`Locked after ${String(idleLockMinutes)} minutes without use. Tap your name to carry on.`);
            }, idleLockMinutes * 60_000);
        };
        restart();
        window.addEventListener('pointerdown', restart);
        window.addEventListener('keydown', restart);
        return () => {
            window.clearTimeout(idleTimer.current);
            window.removeEventListener('pointerdown', restart);
            window.removeEventListener('keydown', restart);
        };
    }, [session, idleLockMinutes, backToTiles]);

    const submit = useCallback(async () => {
        if (chosen === null || busy || !canSubmit(pad, chosen.pinLength[0])) {
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const answer = await postJson<PinAnswer>('/api/identity/pin', { user_id: chosen.id, pin: pad.digits });
            if (answer.ok && answer.body.token !== undefined && answer.body.user !== undefined) {
                setSession({ token: answer.body.token, user: answer.body.user });
                setLockedNote(null);
            } else {
                setError(answer.body.message ?? 'Something went wrong. Try again.');
            }
        } catch {
            setError('The counter cannot reach the server. Check the cable, then try again.');
        } finally {
            setPad(emptyPinPad);
            setBusy(false);
        }
    }, [chosen, busy, pad]);

    // Keyboard and mouse are full fallbacks to touch (spec section 2): digits, Backspace, Enter and Esc.
    useEffect(() => {
        if (chosen === null || session !== null) {
            return;
        }
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Enter') {
                void submit();
            } else if (event.key === 'Escape') {
                backToTiles(null);
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
    }, [chosen, session, submit, backToTiles]);

    return (
        <>
            <Head title="Sign in" />
            <main className="mx-auto flex min-h-screen max-w-3xl flex-col gap-6 px-4 py-6">
                <header className="flex items-baseline justify-between">
                    <h1 className="text-2xl font-semibold">{session === null ? 'Who is selling?' : 'Signed in'}</h1>
                    <span className="text-text-secondary">
                        {terminal.code} · {terminal.name}
                    </span>
                </header>

                {lockedNote !== null && <Notice tone="warning">{lockedNote}</Notice>}

                {session !== null && (
                    <section className="flex flex-col gap-4 rounded-xl bg-panel p-6">
                        <p className="text-2xl">
                            {session.user.name} <span className="text-text-secondary">· {roleLabel(session.user.role)}</span>
                        </p>
                        <p className="text-lg text-text-secondary">The counter arrives in Phase 3. There is nothing to sell yet.</p>
                        <button
                            type="button"
                            className="min-h-touch self-start rounded-xl border-2 border-action px-6 text-lg font-semibold text-action-text"
                            onClick={() => {
                                backToTiles(null);
                            }}
                        >
                            Switch person
                        </button>
                    </section>
                )}

                {session === null && chosen === null && (
                    <section aria-label="Name tiles" className="grid grid-cols-2 gap-3 medium:grid-cols-3 large:grid-cols-4">
                        {tiles.length === 0 && <p className="col-span-full text-lg text-text-secondary">Nobody can sign in yet. Add people with shop:add-user.</p>}
                        {tiles.map((tile) => (
                            <button
                                key={tile.id}
                                type="button"
                                className="flex min-h-24 flex-col items-start justify-center rounded-xl border-2 border-border bg-background px-4 py-3 text-left"
                                onClick={() => {
                                    setChosen(tile);
                                    setLockedNote(null);
                                }}
                            >
                                <span className="text-xl font-semibold">{tile.name}</span>
                                <span className="text-text-secondary">{roleLabel(tile.role)}</span>
                            </button>
                        ))}
                    </section>
                )}

                {session === null && chosen !== null && (
                    <section aria-label={`PIN for ${chosen.name}`} className="mx-auto flex w-full max-w-sm flex-col gap-4">
                        <p className="text-xl">
                            PIN for <strong>{chosen.name}</strong>
                        </p>
                        <p aria-live="polite" className="flex min-h-touch items-center justify-center gap-3 rounded-xl bg-panel text-3xl">
                            {Array.from({ length: chosen.pinLength[1] }, (_, i) => (
                                <span key={i} aria-hidden="true">{i < pad.digits.length ? '●' : '○'}</span>
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
                            <button
                                type="button"
                                className="min-h-touch rounded-xl border-2 border-border text-lg"
                                onClick={() => {
                                    backToTiles(null);
                                }}
                            >
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
                )}
            </main>
        </>
    );
}
