import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { BackOfficeApp } from '@/back-office/app';
import { Notice } from '@/components/notice';
import { SignInPanel, type SignedIn, type Tile } from '@/components/sign-in-panel';

interface Props {
    terminal: { code: string; name: string; canSell: boolean };
    tiles: Tile[];
    idleLockMinutes: number;
}

/**
 * The back office (spec section 12): the owner's phone or laptop, or a counter, for the owner, managers
 * and the storekeeper. It signs in with the name tiles; after the idle limit it locks again (S6).
 */
export default function BackOfficePage({ terminal, tiles, idleLockMinutes }: Props) {
    const [session, setSession] = useState<SignedIn | null>(null);
    const [note, setNote] = useState<string | null>(null);
    const idle = useRef<number | undefined>(undefined);

    const lock = useCallback((why: string | null) => {
        setSession(null);
        setNote(why);
    }, []);

    useEffect(() => {
        if (session === null || idleLockMinutes <= 0) return;
        const restart = () => {
            window.clearTimeout(idle.current);
            idle.current = window.setTimeout(() => {
                lock(`Locked after ${String(idleLockMinutes)} minutes without use.`);
            }, idleLockMinutes * 60_000);
        };
        restart();
        window.addEventListener('pointerdown', restart);
        window.addEventListener('keydown', restart);
        return () => {
            window.clearTimeout(idle.current);
            window.removeEventListener('pointerdown', restart);
            window.removeEventListener('keydown', restart);
        };
    }, [session, idleLockMinutes, lock]);

    const setToken = useCallback((token: string) => {
        setSession((s) => (s === null ? s : { ...s, token }));
    }, []);

    if (session !== null) {
        return (
            <>
                <Head title="Back office" />
                <BackOfficeApp
                    session={session}
                    setToken={setToken}
                    canSell={terminal.canSell}
                    onLock={() => {
                        lock(null);
                    }}
                />
            </>
        );
    }

    return (
        <>
            <Head title="Back office" />
            <main className="mx-auto flex min-h-screen max-w-3xl flex-col gap-6 px-4 py-6">
                <header className="flex items-baseline justify-between">
                    <h1 className="text-2xl font-semibold">Back office</h1>
                    <span className="text-text-secondary">
                        {terminal.code} · {terminal.name}
                    </span>
                </header>
                {note !== null && <Notice tone="warning">{note}</Notice>}
                <SignInPanel
                    tiles={tiles}
                    onSignedIn={(s) => {
                        setSession(s);
                        setNote(null);
                    }}
                />
                {terminal.canSell && (
                    <a href="/counter" className="text-lg font-semibold text-action-text underline">
                        Back to the counter
                    </a>
                )}
            </main>
        </>
    );
}
