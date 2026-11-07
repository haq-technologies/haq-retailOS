import type { Buckets } from './types';

/**
 * Revenue in three parts (spec section 8) [F14]: confirmed at sale (black), estimated (grey) and waiting
 * for a cost (hatched). Each part also carries words and a percentage, never colour alone.
 */
const hatch = 'repeating-linear-gradient(135deg, var(--color-white) 0 5px, var(--color-border) 5px 8px)';

export function ProfitBar({ profit }: { profit: Buckets }) {
    const { confirmed, estimated, pending } = profit;
    return (
        <div
            role="img"
            aria-label={`Revenue: ${String(confirmed.percent)}% confirmed at sale, ${String(estimated.percent)}% estimated, ${String(pending.percent)}% waiting for a cost`}
            className="flex h-5 overflow-hidden rounded-md border border-text-secondary"
        >
            <div className="bg-text" style={{ width: `${String(confirmed.percent)}%` }} />
            <div className="border-l-2 border-white bg-text-secondary" style={{ width: `${String(estimated.percent)}%` }} />
            <div className="border-l-2 border-white" style={{ width: `${String(pending.percent)}%`, background: hatch }} />
        </div>
    );
}

export function BucketKey({ kind }: { kind: 'confirmed' | 'estimated' | 'pending' }) {
    const style = kind === 'pending' ? { background: hatch } : undefined;
    const cls = kind === 'confirmed' ? 'bg-text' : kind === 'estimated' ? 'bg-text-secondary' : 'border border-text-secondary';
    return <span aria-hidden="true" className={`inline-block h-3.5 w-3.5 shrink-0 rounded-sm ${cls}`} style={style} />;
}
