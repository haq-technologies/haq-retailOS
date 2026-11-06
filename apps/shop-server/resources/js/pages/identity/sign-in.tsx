import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Notice } from '@/components/notice';
import { roleLabel, SignInPanel, type SignedIn, type Tile } from '@/components/sign-in-panel';

interface Props {
    terminal: { code: string; name: string; canSell: boolean };
    tiles: Tile[];
    idleLockMinutes: number;
}

/**
 * Sign-in for a back-office device (spec section 3, mode A); a counter signs in on the counter page
 * itself. After the idle limit the device returns to the name tiles (S6). The token lives only in memory.
 */
export default function SignIn({ terminal, tiles, idleLockMinutes }: Props) {
    const [session, setSession] = useState<SignedIn | null>(null);
    const [lockedNote, setLockedNote] = useState<string | null>(null);
    const idleTimer = useRef<number | undefined>(undefined);

    const backToTiles = useCallback((note: string | null) => {
        setSession(null);
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

                {session === null ? (
                    <SignInPanel
                        tiles={tiles}
                        onSignedIn={(s) => {
                            setSession(s);
                            setLockedNote(null);
                        }}
                    />
                ) : (
                    <section className="flex flex-col gap-4 rounded-xl bg-panel p-6">
                        <p className="text-2xl">
                            {session.user.name} <span className="text-text-secondary">· {roleLabel(session.user.role)}</span>
                        </p>
                        <p className="text-lg text-text-secondary">
                            {terminal.canSell ? (
                                <a className="font-semibold text-action-text underline" href="/counter">
                                    Open the counter
                                </a>
                            ) : (
                                'This device is for the back office. It cannot sell.'
                            )}
                        </p>
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
            </main>
        </>
    );
}
