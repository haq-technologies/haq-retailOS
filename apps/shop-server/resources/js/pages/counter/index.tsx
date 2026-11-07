import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Notice } from '@/components/notice';
import { SignInPanel, type SignedIn, type Tile } from '@/components/sign-in-panel';
import { emptyCart, type Cart } from '@/counter/cart';
import { Catalogue } from '@/counter/catalogue';
import { getMeta, pruneJournal, setMeta } from '@/counter/db';
import { reportJournal } from '@/counter/recovery';
import { searchDocs } from '@/counter/docs';
import { SearchClient } from '@/counter/search-client';
import { CatalogueSync } from '@/counter/sync';
import { CounterScreen, type FullSettings } from '@/counter/ui/counter-screen';

/** The counter's long-lived parts: the catalogue copy, the search worker and the feed. */
class Runtime {
    readonly cat = new Catalogue();
    search: SearchClient | null = null;
    sync: CatalogueSync | null = null;

    connect(search: SearchClient, sync: CatalogueSync): void {
        this.search = search;
        this.sync = sync;
    }

    disconnect(): void {
        this.sync?.stop();
        this.search?.terminate();
        this.sync = null;
        this.search = null;
    }
}

interface Props {
    terminal: { code: string; name: string; canSell: boolean };
    tiles: Tile[];
    idleLockMinutes: number;
    settings: FullSettings;
}

/**
 * The counter: one Inertia page that boots a self-contained client (spec section 4) [R4]. The catalogue
 * lives in IndexedDB and in memory, search runs in a Web Worker, and the sale in progress is saved on
 * every change, so a server restart shows Reconnecting with the cart intact, and a lock or a reload keeps
 * it (S6).
 */
export default function CounterPage({ terminal, tiles, idleLockMinutes, settings }: Props) {
    const [runtime] = useState(() => new Runtime());
    const cat = runtime.cat;
    const [catVersion, setCatVersion] = useState(0);
    const [online, setOnline] = useState(true);
    const wasOnline = useRef(true);
    const [ready, setReady] = useState(false);
    const [cart, setCart] = useState<Cart>(emptyCart());
    const [cartLoaded, setCartLoaded] = useState(false);
    const [session, setSession] = useState<SignedIn | null>(null);
    const [lockedNote, setLockedNote] = useState<string | null>(null);
    const idleTimer = useRef<number | undefined>(undefined);

    // The catalogue copy, the search worker and the feed.
    useEffect(() => {
        const client = new SearchClient();
        const catalogue = runtime.cat;
        const s = new CatalogueSync(catalogue, {
            onItems: (ids) => {
                const docs = searchDocs(catalogue, ids);
                if (ids === null) client.index(docs.upsert);
                else client.upsert(docs.upsert, docs.remove);
                setCatVersion((v) => v + 1);
                if (catalogue.seq > 0) setReady(true);
            },
            onStatus: setOnline,
        });
        runtime.connect(client, s);
        void s.start().then(() => {
            setReady(true);
        });
        // The journal goes to the server at start, so after a restore this counter's epoch is raised past
        // its last sales before it sells again (D44); it is pruned to seven days only afterwards.
        void reportJournal(terminal.code)
            .catch(() => false)
            .finally(() => {
                void pruneJournal().catch(() => undefined);
            });
        return () => {
            runtime.disconnect();
        };
    }, [runtime, terminal.code]);

    // Back from Reconnecting: the server may have been restored or reinstalled meanwhile.
    useEffect(() => {
        if (online && !wasOnline.current) void reportJournal(terminal.code).catch(() => false);
        wasOnline.current = online;
    }, [online, terminal.code]);

    // The sale in progress survives a reload, a lock and a server restart.
    useEffect(() => {
        void getMeta<Cart>('cart')
            .then((saved) => {
                if (saved !== undefined && Array.isArray(saved.lines)) setCart(saved);
            })
            .catch(() => undefined)
            .finally(() => {
                setCartLoaded(true);
            });
    }, []);
    useEffect(() => {
        if (!cartLoaded) return;
        void setMeta('cart', cart).catch(() => undefined);
    }, [cart, cartLoaded]);

    // The app shell, so a reload while the server restarts still opens the counter.
    useEffect(() => {
        if (import.meta.env.PROD && 'serviceWorker' in navigator) {
            void navigator.serviceWorker.register('/counter-sw.js', { scope: '/' }).catch(() => undefined);
        }
    }, []);

    const lock = useCallback((note: string | null) => {
        setSession(null);
        setLockedNote(note);
    }, []);

    // The idle lock returns to the name tiles and keeps the sale (S6).
    useEffect(() => {
        if (session === null || idleLockMinutes <= 0) return;
        const restart = () => {
            window.clearTimeout(idleTimer.current);
            idleTimer.current = window.setTimeout(() => {
                lock(`Locked after ${String(idleLockMinutes)} minutes without use. The sale is kept. Tap your name to carry on.`);
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
    }, [session, idleLockMinutes, lock]);

    const update = useCallback((fn: (c: Cart) => Cart) => {
        setCart((c) => fn(c));
    }, []);

    const setToken = useCallback((token: string) => {
        setSession((s) => (s === null ? s : { ...s, token }));
    }, []);

    if (session !== null && terminal.canSell) {
        return (
            <>
                <Head title={`Counter ${terminal.code}`} />
                {!ready && (
                    <p role="status" className="fixed inset-x-0 top-0 z-50 bg-panel p-2 text-center">
                        Loading the catalogue…
                    </p>
                )}
                <CounterScreen
                    session={session}
                    setToken={setToken}
                    terminal={terminal}
                    people={tiles}
                    settings={settings}
                    cat={cat}
                    catVersion={catVersion}
                    online={online}
                    search={runtime.search}
                    sync={runtime.sync}
                    cart={cart}
                    update={update}
                    onLock={() => {
                        lock(null);
                    }}
                />
            </>
        );
    }

    return (
        <>
            <Head title="Sign in" />
            <main className="mx-auto flex min-h-screen max-w-3xl flex-col gap-6 px-4 py-6">
                <header className="flex items-baseline justify-between">
                    <h1 className="text-2xl font-semibold">Who is selling?</h1>
                    <span className="text-text-secondary">
                        {terminal.code} · {terminal.name}
                    </span>
                </header>
                {lockedNote !== null && <Notice tone="warning">{lockedNote}</Notice>}
                {!online && <Notice tone="warning">Reconnecting to the shop server. The sale on this counter is kept.</Notice>}
                {cart.lines.length > 0 && <p className="text-lg text-text-secondary">A sale with {cart.lines.length} lines is waiting on this counter.</p>}
                {session !== null && !terminal.canSell ? (
                    <Notice tone="warning">This device is for the back office. It cannot sell.</Notice>
                ) : (
                    <SignInPanel
                        tiles={tiles}
                        onSignedIn={(s) => {
                            setSession(s);
                            setLockedNote(null);
                        }}
                    />
                )}
            </main>
        </>
    );
}
