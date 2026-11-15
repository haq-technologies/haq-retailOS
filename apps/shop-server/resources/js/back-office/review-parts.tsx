import { useEffect, useState } from 'react';
import { Sheet } from '@/components/sheet';
import type { CounterApi } from '@/counter/use-api';
import { naira, plural } from './format';
import { styles } from './pickers';
import type { Named, Side } from './review-types';

/* Two parts of Review with a state of their own: a pair of likely duplicates, and a category for a typed word. */
const { button, primary } = styles;

export function Pair({ pair, busy, answer }: { pair: { keep: Side; merge: Side; reason: string }; busy: boolean; answer: (url: string, body: unknown, done: string) => Promise<void> }) {
    const [swapped, setSwapped] = useState(false);
    const keep = swapped ? pair.merge : pair.keep;
    const merge = swapped ? pair.keep : pair.merge;
    const describe = (s: Side) =>
        `${s.source === 'starter' ? 'From the starter catalogue' : s.source === 'owner' ? 'Added in the back office' : 'Added at the counter'} · ${plural(s.sales, 'sale', 'sales')} · ${plural(s.barcodes, 'barcode', 'barcodes')} · ${s.set_price_kobo === null ? 'no set price' : `set price ${naira(s.set_price_kobo)}`}`;

    return (
        <section aria-label={`${keep.name} and ${merge.name}`} className="flex flex-col gap-4 rounded-2xl border-2 border-border p-5">
            <div className="flex flex-col gap-1">
                <h2 className="text-xl font-bold">These look like the same item</h2>
                <p className="text-text-secondary">{pair.reason}</p>
            </div>
            <div className="grid gap-3 large:grid-cols-2">
                <div className="flex flex-col gap-1 rounded-xl bg-panel p-4">
                    <span className="text-text-secondary">Keep this one</span>
                    <span className="text-lg font-semibold">{keep.name}</span>
                    <span className="text-text-secondary">{describe(keep)}</span>
                </div>
                <div className="flex flex-col gap-1 rounded-xl border-2 border-border p-4">
                    <span className="text-text-secondary">Merges into the one you keep</span>
                    <span className="text-lg font-semibold">{merge.name}</span>
                    <span className="text-text-secondary">{describe(merge)}</span>
                </div>
            </div>
            <p className="text-text-secondary">
                No sale changes, and receipts keep what they printed. Reports count {merge.name}'s {plural(merge.sales, 'sale', 'sales')} under {keep.name}, and scanning its barcode adds {keep.name}. You can undo it later.
            </p>
            <div className="flex flex-wrap justify-end gap-2">
                <button type="button" disabled={busy} className={button} onClick={() => void answer('/api/back-office/review/duplicates/not-same', { item_id: keep.id, other_id: merge.id }, 'Kept apart; they will not be suggested again.')}>
                    Not the same
                </button>
                <button
                    type="button"
                    className={button}
                    onClick={() => {
                        setSwapped(!swapped);
                    }}
                >
                    Keep the other one
                </button>
                <button type="button" disabled={busy} className={primary} onClick={() => void answer(`/api/back-office/review/items/${merge.id}/merge`, { into_id: keep.id }, `${merge.name} now counts under ${keep.name}.`)}>
                    Merge into {keep.name}
                </button>
            </div>
        </section>
    );
}

/** The owner names a new category for a typed product word, and the word its receipts print. */
export function NewCategory({ api, word, busy, onCreate, onClose }: { api: CounterApi; word: string; busy: boolean; onCreate: (body: { typed: string; word: string; name: string; parent_id: string | null }) => void; onClose: () => void }) {
    const [name, setName] = useState(word);
    const [printed, setPrinted] = useState(word);
    const [parent, setParent] = useState('');
    const [groups, setGroups] = useState<Named[]>([]);

    useEffect(() => {
        void api.get<Named[]>('/api/back-office/review/groups').then((a) => {
            if (a.ok) setGroups(a.body);
        });
    }, [api]);

    return (
        <Sheet title={`A category for “${word}”`} onClose={onClose}>
            <label className="flex flex-col gap-2 text-lg font-semibold">
                Category name
                <input value={name} maxLength={60} className="min-h-touch rounded-xl border-2 border-border px-3 text-lg font-normal" onChange={(e) => { setName(e.target.value); }} />
            </label>
            <label className="flex flex-col gap-2 text-lg font-semibold">
                Word the receipt prints
                <input value={printed} maxLength={40} className="min-h-touch rounded-xl border-2 border-border px-3 text-lg font-normal" onChange={(e) => { setPrinted(e.target.value); }} />
            </label>
            <label className="flex flex-col gap-2 text-lg font-semibold">
                Under
                <select value={parent} className="min-h-touch rounded-xl border-2 border-border bg-background px-3 text-lg font-normal" onChange={(e) => { setParent(e.target.value); }}>
                    <option value="">Its own tile at the top</option>
                    {groups.map((g) => (
                        <option key={g.id} value={g.id}>
                            {g.name}
                        </option>
                    ))}
                </select>
            </label>
            <p className="text-text-secondary">Every item waiting with “{word}” moves into it. Brands can then be picked for it at the counter. Only the owner creates categories, and it goes in the audit log.</p>
            <div className="flex justify-end">
                <button type="button" disabled={busy || name.trim().length < 3 || printed.trim().length < 3} className={styles.primary} onClick={() => { onCreate({ typed: word, word: printed, name, parent_id: parent === '' ? null : parent }); }}>
                    Create the category
                </button>
            </div>
        </Sheet>
    );
}
