import { describeValues, when } from '@/back-office/format';

/** One audit entry, as the back office's Audit log and the counter's My history show it (D47). */
export interface AuditEntry {
    id: string;
    at: string;
    action: string;
    what: string;
    who: string | null;
    device: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    held_back: boolean;
    sensitive?: { before: Record<string, unknown> | null; after: Record<string, unknown> | null };
}

export function AuditRow({ e }: { e: AuditEntry }) {
    const before = describeValues(e.before);
    const after = describeValues(e.after);
    const secret = e.sensitive === undefined ? '' : describeValues(e.sensitive.after ?? e.sensitive.before);
    return (
        <li className="flex flex-col gap-1 border-b border-border py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-lg font-semibold">{e.what}</span>
                <span className="text-text-secondary">{when(e.at)}</span>
            </div>
            <span className="text-text-secondary">
                {e.who ?? 'The server'}
                {e.device === null ? '' : ` · ${e.device}`}
            </span>
            {before !== '' && <span>Before: {before}</span>}
            {after !== '' && <span>{before === '' ? '' : 'After: '}{after}</span>}
            {secret !== '' && <span className="font-semibold">Owner only: {secret}</span>}
            {e.held_back && <span className="text-text-secondary">A value only the owner may see is held back.</span>}
        </li>
    );
}
