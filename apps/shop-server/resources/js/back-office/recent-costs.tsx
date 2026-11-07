import { useCallback, useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import type { CounterApi } from '@/counter/use-api';
import { naira, plural, when } from './format';
import { styles } from './pickers';

/**
 * Costs entered lately, each newest one with Undo (spec section 2: every cost backfill is reversible)
 * [D48]. Undo shows what it will do first: the earlier sales the cost estimated wait for a cost again (or
 * get back the estimate before), later sales take the cost before it (or none), and the sales made since
 * keep the cost they were sold with. Nothing is changed or deleted; the undo is new records.
 */
interface Recent {
    entry_id: string;
    item_id: string;
    name: string;
    cost_kobo: number | null;
    entered_at: string;
    by: string | null;
    undone: boolean;
    undo: boolean;
    is_undo: boolean;
}

interface UndoPreview {
    entry_id: string;
    name: string;
    cost_kobo: number;
    entered_at: string;
    by: string | null;
    estimates: number;
    back_to_waiting: number;
    sold_since: number;
    cost_after_kobo: number | null;
}

const { button } = styles;

export function RecentCosts({ api, version, onUndone }: { api: CounterApi; version: number; onUndone: (message: string) => void }) {
    const [recent, setRecent] = useState<Recent[]>([]);
    const [preview, setPreview] = useState<UndoPreview | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        void api
            .get<{ entries: Recent[] }>('/api/back-office/costs/recent')
            .then((a) => {
                if (a.ok) setRecent(a.body.entries);
            })
            .catch(() => undefined);
    }, [api]);

    useEffect(() => {
        load();
    }, [load, version]);

    const ask = async (entry: Recent) => {
        setProblem(null);
        const a = await api.get<UndoPreview & { message?: string }>(`/api/back-office/costs/entries/${entry.entry_id}/undo`).catch(() => null);
        if (a === null) setProblem('The device cannot reach the shop server. Try again.');
        else if (!a.ok) setProblem(a.body.message ?? 'That cost cannot be undone.');
        else setPreview(a.body);
    };

    const confirm = async () => {
        if (preview === null) return;
        setBusy(true);
        try {
            const a = await api.post<{ message?: string }>(`/api/back-office/costs/entries/${preview.entry_id}/undo`, {});
            if (a.ok) {
                onUndone(`The cost of ${preview.name} is undone.`);
                setPreview(null);
                load();
            } else setProblem(a.body.message ?? 'That did not save. Try again.');
        } catch {
            setProblem('The device cannot reach the shop server. Try again.');
        } finally {
            setBusy(false);
        }
    };

    if (recent.length === 0) return null;

    return (
        <section aria-labelledby="recent-costs" className="flex flex-col gap-2">
            <h2 id="recent-costs" className="text-xl font-bold">
                Costs entered lately
            </h2>
            <p className="text-text-secondary">Typed a cost wrongly? Undo it: its earlier sales wait for a cost again, and you can enter the right one.</p>
            {problem !== null && preview === null && <Notice tone="error">{problem}</Notice>}
            <ul className="flex flex-col rounded-2xl border-2 border-border">
                {recent.map((r) => (
                    <li key={r.entry_id} className="flex flex-wrap items-center gap-3 border-b border-border p-3 last:border-b-0">
                        <span className="flex min-w-0 flex-1 flex-col">
                            <span className="text-lg font-semibold">{r.name}</span>
                            <span className="text-text-secondary">
                                {r.is_undo ? `Undo · back to ${r.cost_kobo === null ? 'no cost' : naira(r.cost_kobo)}` : r.cost_kobo === null ? 'No cost' : naira(r.cost_kobo)}
                                {r.undone ? ' · undone' : ''} · {r.by ?? ''} · {when(r.entered_at)}
                            </span>
                        </span>
                        {r.undo && (
                            <button type="button" className={button} onClick={() => void ask(r)}>
                                Undo
                            </button>
                        )}
                    </li>
                ))}
            </ul>

            {preview !== null && (
                <Sheet
                    title={`Undo the cost of ${preview.name}`}
                    onClose={() => {
                        setPreview(null);
                        setProblem(null);
                    }}
                >
                    <p className="text-lg">
                        {naira(preview.cost_kobo)}, entered {when(preview.entered_at)}
                        {preview.by === null ? '' : ` by ${preview.by}`}.
                    </p>
                    <ul className="flex list-disc flex-col gap-1 pl-6 text-lg">
                        <li>
                            {preview.estimates === 0
                                ? 'It estimated no earlier sale.'
                                : `${plural(preview.estimates, 'earlier sale', 'earlier sales')} it estimated ${preview.back_to_waiting === preview.estimates ? 'wait for a cost again' : 'get back the estimate before it'}.`}
                        </li>
                        <li>Later sales take {preview.cost_after_kobo === null ? 'no cost, until you enter one' : `the cost before it, ${naira(preview.cost_after_kobo)}`}.</li>
                        {preview.sold_since > 0 && <li>{plural(preview.sold_since, 'sale', 'sales')} made since keep the cost they were sold with, as every finished sale does.</li>}
                    </ul>
                    {problem !== null && <Notice tone="error">{problem}</Notice>}
                    <div className="flex flex-wrap justify-end gap-3">
                        <button
                            type="button"
                            className={button}
                            onClick={() => {
                                setPreview(null);
                            }}
                        >
                            Keep it
                        </button>
                        <button type="button" disabled={busy} className="min-h-touch rounded-xl bg-destructive px-6 text-lg font-bold text-white disabled:opacity-60" onClick={() => void confirm()}>
                            Undo the cost
                        </button>
                    </div>
                </Sheet>
            )}
        </section>
    );
}
