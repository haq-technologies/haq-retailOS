import { useCallback, useEffect, useState } from 'react';
import { DotIcon } from '@/components/icons';
import { OwnerSettings } from '@/components/owner-settings';
import { roleLabel, type SignedIn } from '@/components/sign-in-panel';
import { useCounterApi } from '@/counter/use-api';
import { Dashboard } from './dashboard';
import { Flags } from './flags';
import { NeedsCost } from './needs-cost';
import type { Can, Me } from './types';

/**
 * The back office (spec section 12; wireframes BackDashboard, NeedsCost and the rest): a header, the
 * sections on the left (at the bottom on a phone), and the section itself. Each person sees only the
 * sections their role may open. The address keeps the section, so Back works.
 */
type Section = 'dashboard' | 'costs' | 'flags' | 'settings';

const sections: { id: Section; label: string; allowed: (c: Can) => boolean; icon: string }[] = [
    { id: 'dashboard', label: 'Dashboard', allowed: (c) => c.dashboard, icon: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z' },
    { id: 'costs', label: 'Needs cost', allowed: (c) => c.costs, icon: 'M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z' },
    { id: 'flags', label: 'Flags', allowed: (c) => c.flags, icon: 'M5 21V4h12l-2.5 4.5L17 13H5' },
    { id: 'settings', label: 'Shop settings', allowed: (c) => c.settings, icon: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM4 12h2M18 12h2M12 4v2M12 18v2' },
];

function fromHash(): string {
    return window.location.hash.replace(/^#\/?/, '');
}

interface Props {
    session: SignedIn;
    setToken: (token: string) => void;
    canSell: boolean;
    onLock: () => void;
}

export function BackOfficeApp({ session, setToken, canSell, onLock }: Props) {
    const api = useCounterApi(session.token, setToken);
    const [me, setMe] = useState<Me | null>(null);
    const [section, setSection] = useState<string>(fromHash());
    const [badge, setBadge] = useState<{ costs: number; flags: number }>({ costs: 0, flags: 0 });

    useEffect(() => {
        void api.get<Me>('/api/back-office/me').then((a) => {
            if (a.ok) setMe(a.body);
        });
    }, [api]);

    const refreshBadges = useCallback(() => {
        void api
            .get<{ todo: { needs_cost: number }; attention: { total: number } }>('/api/back-office/dashboard')
            .then((a) => {
                if (a.ok) setBadge({ costs: a.body.todo.needs_cost, flags: a.body.attention.total });
            })
            .catch(() => undefined);
    }, [api]);

    useEffect(() => {
        refreshBadges();
        const onHash = () => {
            setSection(fromHash());
        };
        window.addEventListener('hashchange', onHash);
        return () => {
            window.removeEventListener('hashchange', onHash);
        };
    }, [refreshBadges]);

    const go = (id: string) => {
        window.location.hash = `/${id}`;
    };

    if (me === null) return <p className="p-6 text-lg text-text-secondary">Loading the back office…</p>;

    const open = sections.filter((s) => s.allowed(me.can));
    const current: Section | undefined = open.find((s) => s.id === section)?.id ?? open[0]?.id;
    const count = (id: Section) => (id === 'costs' ? badge.costs : id === 'flags' ? badge.flags : 0);

    const nav = (
        <>
            {open.map((s) => (
                <a
                    key={s.id}
                    href={`#/${s.id}`}
                    aria-current={current === s.id ? 'page' : undefined}
                    className={`flex min-h-touch items-center gap-3 rounded-xl px-3 text-lg ${current === s.id ? 'bg-panel font-bold text-action-text shadow-[inset_4px_0_0_var(--color-action)]' : 'font-medium'}`}
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" className="h-6 w-6 shrink-0" fill="none" stroke="currentColor" strokeWidth="2" strokeLinejoin="round">
                        <path d={s.icon} />
                    </svg>
                    <span className="flex-1">{s.label}</span>
                    {count(s.id) > 0 && <span className="min-w-8 rounded-full bg-panel px-2 text-center text-base font-semibold">{count(s.id)}</span>}
                </a>
            ))}
        </>
    );

    return (
        <div className="flex min-h-screen flex-col">
            <header className="flex min-h-[72px] items-center gap-4 border-b border-border px-4">
                <span className="text-lg font-bold">HAQ Retail OS</span>
                <span className="hidden text-text-secondary medium:inline">Back office</span>
                <span className="ml-auto flex items-center gap-2">
                    <DotIcon className="h-3 w-3 text-pay" /> Online
                </span>
                <button type="button" title="Switch person" className="flex min-h-touch items-center gap-2 rounded-full border-2 border-border px-2 pr-4" onClick={onLock}>
                    <span className="flex h-10 w-10 items-center justify-center rounded-full bg-panel font-bold">{me.user.name.slice(0, 2).toUpperCase()}</span>
                    <span className="text-lg font-semibold">
                        {me.user.name} · {roleLabel(me.user.role)}
                    </span>
                </button>
            </header>
            <div className="flex min-h-0 flex-1">
                <nav aria-label="Back office" className="hidden w-[248px] shrink-0 flex-col gap-1 border-r border-border p-3 medium:flex">
                    {nav}
                    <div className="flex-1" />
                    {canSell && (
                        <a href="/counter" className="flex min-h-touch items-center rounded-xl border-2 border-border px-3 text-lg font-semibold text-action-text">
                            Open the counter
                        </a>
                    )}
                </nav>
                <main className="min-w-0 flex-1 p-4 pb-28 medium:p-8">
                    {open.length === 0 && <p className="text-lg">There is nothing in the back office for your role.</p>}
                    {current === 'dashboard' && <Dashboard api={api} can={me.can} go={go} onChanged={refreshBadges} />}
                    {current === 'costs' && <NeedsCost api={api} onChanged={refreshBadges} />}
                    {current === 'flags' && <Flags api={api} onChanged={refreshBadges} />}
                    {current === 'settings' && (
                        <div className="flex max-w-2xl flex-col gap-4">
                            <h1 className="text-3xl font-bold">Shop settings</h1>
                            <OwnerSettings token={session.token} />
                        </div>
                    )}
                </main>
            </div>
            <nav aria-label="Back office" className="fixed inset-x-0 bottom-0 grid border-t border-border bg-background medium:hidden" style={{ gridTemplateColumns: `repeat(${String(Math.max(open.length, 1))}, 1fr)` }}>
                {open.map((s) => (
                    <a key={s.id} href={`#/${s.id}`} aria-current={current === s.id ? 'page' : undefined} className={`flex min-h-[72px] flex-col items-center justify-center gap-1 text-sm ${current === s.id ? 'font-bold text-action-text' : ''}`}>
                        <svg aria-hidden="true" viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2" strokeLinejoin="round">
                            <path d={s.icon} />
                        </svg>
                        {s.label}
                    </a>
                ))}
            </nav>
        </div>
    );
}
