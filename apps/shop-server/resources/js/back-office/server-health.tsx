import { useCallback, useEffect, useState } from 'react';
import { CheckIcon, WarningIcon } from '@/components/icons';
import { Notice } from '@/components/notice';
import type { CounterApi } from '@/counter/use-api';
import { clock, longDay, plural, when, whenInline } from './format';
import { styles } from './pickers';

/**
 * Server health (spec section 4; wireframe HealthPage) [F15, F24, D45]: the continuous copy to the backup
 * disk, the last backup and test restore, the disks and SSDs, the certificate, the clock, the version, the
 * grant check and the audit chains, with what needs putting right at the top. Until the sysadmin console
 * exists the owner sees it; it shows server health only, never sales or cost.
 */
interface Warning {
    kind: string;
    title: string;
    detail: string;
}

interface Disk {
    free: number;
    total: number;
}

interface View {
    checked_at: string | null;
    backups: {
        last: { label: string; type: string; at: string } | null;
        last_full: string | null;
        count: number;
        failed_at: string | null;
        restore: { state: string | null; label: string | null; verified: boolean | null; finished_at: string | null; problems: string[] } | null;
        last_verified_restore: string | null;
        backup_now: boolean;
    } | null;
    disks: { main: Disk | null; backup: Disk | null } | null;
    certificate: { expires_at: string; subject: string | null } | null;
    host: { written_at: string | null; disks: { device: string; model: string | null; passed: boolean | null; life_left: number | null }[]; smartctl: boolean; time_synced: boolean | null } | null;
    wal: { archived_at: string | null; failed_at: string | null; count: number } | null;
    database_bytes: number;
    version: string;
    clock: { state: string; reason: string | null; since: string | null; server_time: string; confirmed_at: string | null; confirmed_by: string | null };
    grants: { checked_at: string; problems: string[] } | null;
    audit: { checked_at: string; problems: string[] } | null;
    chains: number;
    warnings: Warning[];
}

const { button, primary } = styles;

function bytes(n: number): string {
    return n >= 1024 ** 3 ? `${(n / 1024 ** 3).toFixed(n >= 10 * 1024 ** 3 ? 0 : 1)} GB` : `${String(Math.round(n / 1024 ** 2))} MB`;
}

function ago(iso: string, now: Date = new Date()): string {
    const minutes = Math.round((now.getTime() - new Date(iso).getTime()) / 60_000);
    if (minutes < 2) return 'just now';
    if (minutes < 90) return `${String(minutes)} minutes ago`;
    const hours = Math.round(minutes / 60);
    return hours < 48 ? `${String(hours)} hours ago` : `${String(Math.round(hours / 24))} days ago`;
}

function Card({ title, value, note, warning = false, children }: { title: string; value: string; note: string; warning?: boolean; children?: React.ReactNode }) {
    return (
        <section aria-label={title} className={`flex flex-col gap-1 rounded-2xl p-4 ${warning ? 'border-2 border-warning bg-background' : 'bg-panel'}`}>
            <h2 className="text-base font-semibold text-text-secondary">{title}</h2>
            <p className="text-lg font-semibold">{value}</p>
            <p className={`flex items-center gap-2 text-base ${warning ? 'font-semibold text-warning-text' : ''}`}>
                {warning ? <WarningIcon className="h-5 w-5 shrink-0" /> : <CheckIcon className="h-5 w-5 shrink-0" />}
                {note}
            </p>
            {children}
        </section>
    );
}

export function ServerHealth({ api }: { api: CounterApi }) {
    const [view, setView] = useState<View | null>(null);
    const [message, setMessage] = useState<{ tone: 'error' | 'ok'; text: string } | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        void api
            .get<View>('/api/back-office/health')
            .then((a) => {
                if (a.ok) setView(a.body);
            })
            .catch(() => {
                setMessage({ tone: 'error', text: 'Server health could not load. Check the connection.' });
            });
    }, [api]);

    useEffect(() => {
        load();
        const timer = window.setInterval(load, 60_000);
        return () => {
            window.clearInterval(timer);
        };
    }, [load]);

    const send = async (url: string, done: (body: { message?: string }) => string) => {
        setBusy(true);
        setMessage(null);
        try {
            const a = await api.post<{ message?: string }>(url, {});
            setMessage(a.ok ? { tone: 'ok', text: done(a.body) } : { tone: 'error', text: a.body.message ?? 'That did not work. Try again.' });
        } catch {
            setMessage({ tone: 'error', text: 'The device cannot reach the shop server. Try again.' });
        } finally {
            setBusy(false);
            load();
        }
    };

    if (view === null) return <p className="text-lg text-text-secondary">Loading…</p>;

    const has = (kind: string) => view.warnings.some((w) => w.kind === kind);
    const b = view.backups;
    const lastBackup = b?.last ?? null;
    const restore = b?.restore ?? null;
    const unverified = view.clock.state === 'unverified';
    const ssds = view.host?.disks ?? [];

    return (
        <div className="flex flex-col gap-5">
            <header className="flex flex-col gap-1">
                <h1 className="text-3xl font-bold">Server health</h1>
                <p className="text-lg text-text-secondary">{view.checked_at === null ? 'Not checked yet' : `Checked ${whenInline(view.checked_at)}`} · server health only, never sales or cost</p>
            </header>

            {message !== null && (message.tone === 'error' ? <Notice tone="error">{message.text}</Notice> : <p role="status" className="rounded-xl bg-panel p-4 text-lg">{message.text}</p>)}

            {view.warnings.map((w) => (
                <div key={`${w.kind}:${w.title}`} role="alert" className="flex flex-col gap-3 rounded-2xl border-2 border-warning p-4 medium:flex-row medium:items-center">
                    <WarningIcon className="hidden h-8 w-8 shrink-0 text-warning-text medium:block" />
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <span className="text-lg font-bold">{w.title}</span>
                        <span className="leading-normal">{w.detail}</span>
                    </div>
                    {w.kind === 'backup' && b !== null && (
                        <button
                            type="button"
                            className={primary}
                            disabled={busy || b.backup_now}
                            onClick={() => void send('/api/back-office/health/backup', (body) => body.message ?? 'The backup starts within a minute or two.')}
                        >
                            {b.backup_now ? 'Backing up…' : 'Back up now'}
                        </button>
                    )}
                </div>
            ))}

            <div className="grid gap-4 tabular-nums medium:grid-cols-2 large:grid-cols-3">
                <Card
                    title="Continuous copy (WAL)"
                    value={view.wal?.archived_at == null ? 'Nothing copied yet' : `Last sent ${ago(view.wal.archived_at)}`}
                    note={has('wal') ? 'Failing' : 'OK · to the second disk'}
                    warning={has('wal')}
                />
                <Card
                    title="Last backup"
                    value={lastBackup === null ? 'None yet' : `${when(lastBackup.at)} · ${ago(lastBackup.at)}`}
                    note={b === null ? 'No backup disk' : has('backup') ? 'Needs attention' : `OK · ${lastBackup?.type === 'full' ? 'full' : 'daily'} backup`}
                    warning={has('backup')}
                />
                <Card
                    title="Last test restore"
                    value={b?.last_verified_restore == null ? 'None verified yet' : `${when(b.last_verified_restore)} · every audit chain verified`}
                    note={b === null ? 'No backup disk' : has('restore') ? (restore?.verified === false ? 'Failed' : 'Overdue') : 'OK · runs every week'}
                    warning={has('restore') || b === null}
                />
                <Card
                    title="Free disk"
                    value={
                        view.disks?.main == null
                            ? 'Not known'
                            : `${bytes(view.disks.main.free)} of ${bytes(view.disks.main.total)}${view.disks.backup == null ? '' : ` · backup disk ${bytes(view.disks.backup.free)}`}`
                    }
                    note={has('disk') ? 'Nearly full' : 'OK'}
                    warning={has('disk')}
                />
                <Card
                    title="SSD health"
                    value={ssds.length === 0 ? 'Not reported' : ssds.map((d) => `${d.model ?? d.device} ${d.life_left === null ? (d.passed === false ? 'failed' : 'passed') : `${String(d.life_left)}% life left`}`).join(' · ')}
                    note={has('ssd') ? 'Wearing out' : ssds.length === 0 ? (view.host === null ? 'The server has not reported its disks yet' : 'The disks do not report their health') : 'OK'}
                    warning={has('ssd')}
                />
                <Card
                    title="Certificate"
                    value={view.certificate === null ? 'Not found' : `Valid to ${longDay(view.certificate.expires_at.slice(0, 10)).replace(/^\w+ /, '')}`}
                    note={has('certificate') ? 'Renew it soon' : view.certificate === null ? 'HTTPS is set up at install' : 'OK · warns 60 days before'}
                    warning={has('certificate')}
                />
                <Card
                    title="Clock"
                    value={
                        unverified
                            ? `It says ${new Date(view.clock.server_time).toLocaleString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}`
                            : view.clock.confirmed_at !== null
                              ? `Confirmed${view.clock.confirmed_by === null ? '' : ` by ${view.clock.confirmed_by}`} ${when(view.clock.confirmed_at)}`
                              : `Trusted · ${clock(view.clock.server_time)} now`
                    }
                    note={unverified ? 'Unverified: sales are marked' : view.host?.time_synced === true ? 'OK · set from the internet' : 'OK · no internet time source'}
                    warning={unverified}
                >
                    {unverified && view.clock.reason !== 'before_build' && (
                        <button
                            type="button"
                            className={`${button} mt-2 self-start`}
                            disabled={busy}
                            onClick={() => void send('/api/back-office/health/clock', () => 'Thank you. Sales are stamped as trusted again from now.')}
                        >
                            The time is right
                        </button>
                    )}
                </Card>
                <Card title="App version" value={view.version} note="OK" />
                <Card
                    title="Database grants"
                    value={view.grants === null ? 'Not checked yet' : view.grants.problems.length === 0 ? 'Match their rules' : plural(view.grants.problems.length, 'difference', 'differences')}
                    note={has('grants') ? 'Needs attention' : `OK · checked ${view.grants === null ? '' : when(view.grants.checked_at)}`}
                    warning={has('grants')}
                />
                <Card
                    title="Audit chains"
                    value={view.audit === null ? 'Not checked yet' : view.audit.problems.length === 0 ? `${plural(view.chains, 'chain', 'chains')} and their roots verified` : 'A chain does not verify'}
                    note={has('audit') ? 'Needs attention' : `OK · checked ${view.audit === null ? '' : when(view.audit.checked_at)}`}
                    warning={has('audit')}
                />
                <Card title="Database size" value={bytes(view.database_bytes)} note="For information" />
            </div>
        </div>
    );
}
