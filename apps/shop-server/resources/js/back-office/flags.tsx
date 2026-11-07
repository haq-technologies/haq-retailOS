import { useCallback, useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import type { CounterApi } from '@/counter/use-api';
import { plural, when } from './format';
import { SaleSheet } from './sale-sheet';
import type { Flag } from './types';

/**
 * Flags (spec sections 8 and 12; wireframe FlagList): everything the counters flagged, newest first. A
 * flag can be marked as seen, but it is never deleted. A manager sees the same list with no cost amounts,
 * and the export leaves cost out for anyone who cannot see it.
 */
interface FlagList {
    period: string;
    kinds: Record<string, string>;
    counts: Record<string, number>;
    total: number;
    flags: Flag[];
    people: { id: string; name: string }[];
}

const periods: { id: string; label: string }[] = [
    { id: 'today', label: 'Today' },
    { id: 'week', label: 'This week' },
    { id: 'month', label: 'This month' },
    { id: 'all', label: 'All' },
];

export function Flags({ api, onChanged }: { api: CounterApi; onChanged: () => void }) {
    const [period, setPeriod] = useState('week');
    const [kind, setKind] = useState<string | null>(null);
    const [person, setPerson] = useState('');
    const [limit, setLimit] = useState(50);
    const [list, setList] = useState<FlagList | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [viewing, setViewing] = useState<string[] | null>(null);
    const [busy, setBusy] = useState(false);

    const query = `period=${period}${kind === null ? '' : `&kind=${kind}`}${person === '' ? '' : `&person=${person}`}`;

    const load = useCallback(() => {
        void api
            .get<FlagList>(`/api/back-office/flags?${query}&limit=${String(limit)}`)
            .then((a) => {
                if (a.ok) {
                    setList(a.body);
                    setError(null);
                }
            })
            .catch(() => {
                setError('The flags could not load. Check the connection.');
            });
    }, [api, query, limit]);

    useEffect(() => {
        load();
    }, [load]);

    const markSeen = async (keys: string[]) => {
        setBusy(true);
        try {
            const a = await api.post<{ marked?: number; message?: string }>('/api/back-office/flags/seen', { keys });
            if (!a.ok) setError(a.body.message ?? 'That did not save. Try again.');
            load();
            onChanged();
        } catch {
            setError('The device cannot reach the shop server. Try again.');
        } finally {
            setBusy(false);
        }
    };

    const exportCsv = async () => {
        try {
            const ok = await api.download(`/api/back-office/flags.csv?${query}`, `flags-${period}.csv`);
            if (!ok) setError('The export did not download. Try again.');
        } catch {
            setError('The device cannot reach the shop server. Try again.');
        }
    };

    const total = list === null ? 0 : Object.values(list.counts).reduce((s, n) => s + n, 0);
    const unseen = (list?.flags ?? []).filter((f) => f.seen_at === null).map((f) => f.key);

    return (
        <div className="flex flex-col gap-5">
            <header className="flex flex-wrap items-end gap-4">
                <div className="flex flex-1 flex-col gap-1">
                    <h1 className="text-3xl font-bold">Flags</h1>
                    <p className="text-lg text-text-secondary">Everything the counters flagged, newest first. A flag can be marked as seen, but it is never deleted.</p>
                </div>
                <button type="button" className="min-h-touch rounded-xl border-2 border-border px-5 text-lg font-semibold text-action-text" onClick={() => void exportCsv()}>
                    Export to CSV
                </button>
            </header>

            {error !== null && <Notice tone="error">{error}</Notice>}

            <div className="flex flex-wrap items-center gap-3">
                <div role="group" aria-label="Period" className="flex flex-wrap gap-2">
                    {periods.map((p) => (
                        <button
                            key={p.id}
                            type="button"
                            aria-pressed={period === p.id}
                            className={`min-h-touch rounded-xl border-2 px-4 text-lg ${period === p.id ? 'border-action bg-panel font-bold text-action-text' : 'border-border font-medium'}`}
                            onClick={() => {
                                setPeriod(p.id);
                                setLimit(50);
                            }}
                        >
                            {p.label}
                        </button>
                    ))}
                </div>
                <label className="flex items-center gap-2 text-lg">
                    <span className="sr-only">Person</span>
                    <select
                        value={person}
                        className="min-h-touch rounded-xl border-2 border-border bg-background px-3 text-lg"
                        onChange={(e) => {
                            setPerson(e.target.value);
                        }}
                    >
                        <option value="">Everyone</option>
                        {(list?.people ?? []).map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                </label>
            </div>

            {list !== null && (
                <div role="group" aria-label="Kind of flag" className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        aria-pressed={kind === null}
                        className={`min-h-12 rounded-full border-2 px-4 ${kind === null ? 'border-action bg-panel font-bold text-action-text' : 'border-border'}`}
                        onClick={() => {
                            setKind(null);
                        }}
                    >
                        All flags · {total}
                    </button>
                    {Object.entries(list.kinds)
                        .filter(([k]) => (list.counts[k] ?? 0) > 0)
                        .map(([k, label]) => (
                            <button
                                key={k}
                                type="button"
                                aria-pressed={kind === k}
                                className={`min-h-12 rounded-full border-2 px-4 ${kind === k ? 'border-action bg-panel font-bold text-action-text' : 'border-border'}`}
                                onClick={() => {
                                    setKind(kind === k ? null : k);
                                }}
                            >
                                {label} · {list.counts[k] ?? 0}
                            </button>
                        ))}
                </div>
            )}

            {list !== null && list.flags.length === 0 && <p className="text-lg text-text-secondary">Nothing was flagged in this period.</p>}

            {list !== null && list.flags.length > 0 && (
                <>
                    <ul aria-label="Flags" className="flex flex-col">
                        {list.flags.map((f) => (
                            <li key={f.key} className="flex flex-col gap-3 border-b border-border py-4 large:flex-row large:items-center">
                                <span className="w-40 shrink-0 text-text-secondary">{when(f.at)}</span>
                                <span className="flex min-w-0 flex-1 flex-col gap-1">
                                    <span className="text-lg font-semibold">{f.title}</span>
                                    <span className="text-text-secondary">{f.detail}</span>
                                </span>
                                <span className="flex flex-col large:w-56">
                                    <span>
                                        {f.who ?? 'No one named'}
                                        {f.approver !== null && `, approved by ${f.approver}`}
                                    </span>
                                    {f.terminal !== null && <span className="text-text-secondary">{f.terminal}</span>}
                                </span>
                                <span className="flex flex-wrap items-center gap-2 large:w-80 large:justify-end">
                                    {f.sale_ids.length > 0 && (
                                        <button
                                            type="button"
                                            className="min-h-touch rounded-xl border-2 border-border px-4 text-lg font-semibold text-action-text"
                                            onClick={() => {
                                                setViewing(f.sale_ids);
                                            }}
                                        >
                                            {f.sale_ids.length > 1 ? 'View sales' : 'View sale'}
                                        </button>
                                    )}
                                    {f.seen_at === null ? (
                                        <button type="button" disabled={busy} className="min-h-touch rounded-xl border-2 border-border px-4 text-lg font-semibold text-action-text disabled:opacity-60" onClick={() => void markSeen([f.key])}>
                                            Mark as seen
                                        </button>
                                    ) : (
                                        <span className="text-text-secondary">
                                            Seen by {f.seen_by} · {when(f.seen_at)}
                                        </span>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <div className="flex flex-wrap items-center gap-3">
                        <p className="flex-1 text-text-secondary">
                            Showing {list.flags.length} of {plural(list.total, 'flag', 'flags')}. A manager sees the same list with no cost amounts, and exports leave cost out for anyone who cannot see it.
                        </p>
                        {unseen.length > 1 && (
                            <button type="button" disabled={busy} className="min-h-touch rounded-xl border-2 border-border px-4 text-lg font-semibold text-action-text disabled:opacity-60" onClick={() => void markSeen(unseen)}>
                                Mark these {unseen.length} as seen
                            </button>
                        )}
                        {list.flags.length < list.total && (
                            <button
                                type="button"
                                className="min-h-touch rounded-xl border-2 border-border px-5 text-lg font-semibold text-action-text"
                                onClick={() => {
                                    setLimit(limit + 50);
                                }}
                            >
                                Show more
                            </button>
                        )}
                    </div>
                </>
            )}

            {viewing !== null && (
                <SaleSheet
                    api={api}
                    saleIds={viewing}
                    onClose={() => {
                        setViewing(null);
                    }}
                />
            )}
        </div>
    );
}
