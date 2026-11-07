import { useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import type { CounterApi } from '@/counter/use-api';
import { longDay, plural, shortDate } from './format';
import { styles } from './pickers';
import { BucketKey, ProfitBar } from './profit-bar';
import type { Can } from './types';

/**
 * Catalogue health (spec section 12) [v1.3; wireframe CatalogueHealth]: the share of revenue with a cost
 * against the pilot's goal, what waits in review, how many items sold have a barcode, and the best sellers
 * the counter has not learned a barcode for yet. Percentages and counts only.
 */
interface Health {
    since: string | null;
    coverage_percent: number;
    buckets: { confirmed: number; estimated: number; pending: number };
    goal: { percent: number; by: string | null; items_to_goal: number | null };
    review: { new_items: number; names: number; brands: number; models: number; words: number; barcodes: number; learned: number; conflicts: number; duplicates: number };
    barcodes: { items_sold: number; with_barcode: number; percent: number };
    no_barcode: { item_id: string; name: string; sold: number; tiles: number; search: number; other: number }[];
}

const card = 'flex min-h-[104px] flex-col items-start justify-center gap-1 rounded-2xl border-2 border-border p-4 text-left';

export function CatalogueHealth({ api, can, go }: { api: CounterApi; can: Can; go: (section: string) => void }) {
    const [health, setHealth] = useState<Health | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        void api
            .get<Health>('/api/back-office/reports/health')
            .then((a) => {
                if (a.ok) setHealth(a.body);
            })
            .catch(() => {
                setError('Catalogue health could not load. Check the connection.');
            });
    }, [api]);

    const exportItems = async () => {
        try {
            if (!(await api.download('/api/back-office/exports/items.csv', 'items.csv'))) setError('The export did not download. Try again.');
        } catch {
            setError('The device cannot reach the shop server. Try again.');
        }
    };

    if (health === null) return error === null ? <p className="text-text-secondary">Loading…</p> : <Notice tone="error">{error}</Notice>;

    const bar = {
        revenue_kobo: 0,
        confirmed: { revenue_kobo: 0, percent: health.buckets.confirmed },
        estimated: { revenue_kobo: 0, percent: health.buckets.estimated },
        pending: { revenue_kobo: 0, percent: health.buckets.pending },
    };
    const words = [
        health.review.brands > 0 && plural(health.review.brands, 'brand', 'brands'),
        health.review.models > 0 && plural(health.review.models, 'phone model', 'phone models'),
        health.review.words > 0 && plural(health.review.words, 'product', 'products'),
    ].filter((w): w is string => typeof w === 'string');

    return (
        <div className="flex flex-col gap-6">
            <header className="flex flex-wrap items-end gap-4">
                <div className="flex flex-1 flex-col gap-1">
                    <h1 className="text-3xl font-bold">Catalogue health</h1>
                    <p className="text-lg text-text-secondary">
                        {health.since === null ? 'Nothing sold yet.' : `Since trading began on ${longDay(health.since)}.`} The catalogue learns from every sale; these show what still needs you.
                    </p>
                </div>
                <button type="button" className={styles.button} onClick={() => void exportItems()}>
                    Export items to CSV
                </button>
            </header>
            {error !== null && <Notice tone="error">{error}</Notice>}

            <section aria-labelledby="cov-h" className="flex flex-col gap-3 rounded-2xl bg-panel p-5">
                <h2 id="cov-h" className="text-lg font-semibold text-text-secondary">
                    Revenue with a cost
                </h2>
                <p className="flex flex-wrap items-baseline gap-3">
                    <span className="text-4xl font-bold">{health.coverage_percent}%</span>
                    <span className="text-lg text-text-secondary">
                        Goal: {health.goal.percent}%{health.goal.by !== null && ` by day 14, ${shortDate(`${health.goal.by}T12:00:00`)}`}
                    </span>
                </p>
                <ProfitBar profit={bar} />
                <ul className="flex flex-wrap gap-x-6 gap-y-2 text-lg">
                    <li className="flex items-center gap-2">
                        <BucketKey kind="confirmed" /> Confirmed at sale {health.buckets.confirmed}%
                    </li>
                    <li className="flex items-center gap-2">
                        <BucketKey kind="estimated" /> Estimated {health.buckets.estimated}%
                    </li>
                    <li className="flex items-center gap-2">
                        <BucketKey kind="pending" /> Waiting for a cost {health.buckets.pending}%
                    </li>
                </ul>
                {health.goal.items_to_goal !== null && (
                    <p className="text-lg">
                        Entering the costs of the top {plural(health.goal.items_to_goal, 'item', 'items')} in Needs cost would pass the goal.
                    </p>
                )}
                {can.costs && health.coverage_percent < health.goal.percent && (
                    <button type="button" className={`${styles.button} self-start`} onClick={() => { go('costs'); }}>
                        Enter costs
                    </button>
                )}
            </section>

            <div className="grid gap-3 medium:grid-cols-2 large:grid-cols-4">
                <button type="button" className={card} onClick={() => { go('review'); }}>
                    <span className="text-text-secondary">New items to review</span>
                    <span className="text-3xl font-bold">{health.review.new_items}</span>
                    <span className="text-text-secondary">Created by cashiers while selling</span>
                </button>
                <button type="button" className={card} onClick={() => { go('review'); }}>
                    <span className="text-text-secondary">Brands and words waiting</span>
                    <span className="text-3xl font-bold">{health.review.names}</span>
                    <span className="text-text-secondary">{words.length === 0 ? 'None waiting' : words.join(', ')}</span>
                </button>
                <button type="button" className={card} onClick={() => { go('review'); }}>
                    <span className="text-text-secondary">Barcodes not yet confirmed</span>
                    <span className="text-3xl font-bold">{health.review.barcodes}</span>
                    <span className="text-text-secondary">
                        {plural(health.review.learned, 'learned', 'learned')}, {plural(health.review.conflicts, 'conflict', 'conflicts')}
                    </span>
                </button>
                <div className={card}>
                    <span className="text-text-secondary">Items sold with a barcode</span>
                    <span className="text-3xl font-bold">{health.barcodes.percent}%</span>
                    <span className="text-text-secondary">
                        {health.barcodes.with_barcode} of {plural(health.barcodes.items_sold, 'item', 'items')} sold so far
                    </span>
                </div>
            </div>

            <section aria-labelledby="nocode-h" className="flex flex-col gap-2">
                <h2 id="nocode-h" className="text-xl font-bold">
                    Best sellers with no barcode yet
                </h2>
                <p className="text-text-secondary">The counter learns a barcode the first time a cashier scans one while selling the item. Ask cashiers to scan these.</p>
                {health.no_barcode.length === 0 ? (
                    <p className="text-lg">Every item sold has a barcode.</p>
                ) : (
                    <div className="overflow-x-auto rounded-2xl border-2 border-border">
                        <table className="w-full min-w-[560px] text-left text-lg tabular-nums">
                            <thead className="text-base text-text-secondary">
                                <tr className="border-b border-border">
                                    <th scope="col" className="p-3 font-semibold">Item</th>
                                    <th scope="col" className="p-3 text-right font-semibold">Sold{health.since !== null && ` since ${shortDate(`${health.since}T12:00:00`)}`}</th>
                                    <th scope="col" className="p-3 font-semibold">Added at the counter by</th>
                                </tr>
                            </thead>
                            <tbody>
                                {health.no_barcode.map((r) => (
                                    <tr key={r.item_id} className="border-b border-border last:border-b-0">
                                        <th scope="row" className="p-3 font-semibold">{r.name}</th>
                                        <td className="p-3 text-right">{r.sold}</td>
                                        <td className="p-3 text-text-secondary">
                                            {[r.tiles > 0 && `Tiles ${String(r.tiles)}`, r.search > 0 && `search ${String(r.search)}`, r.other > 0 && `other ${String(r.other)}`].filter(Boolean).join(', ')}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {health.review.duplicates > 0 && (
                <div className="flex flex-wrap items-center gap-3 rounded-2xl border-2 border-border p-4">
                    <span className="flex-1 text-lg">{plural(health.review.duplicates, 'possible duplicate', 'possible duplicates')} · items that look the same, from typed brands and phone models</span>
                    <button type="button" className={styles.button} onClick={() => { go('review'); }}>
                        Review duplicates
                    </button>
                </div>
            )}
        </div>
    );
}
