import { useCallback, useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import type { CounterApi } from '@/counter/use-api';
import { AuditRow, type AuditEntry } from '@/components/audit-row';
import { styles } from './pickers';

/**
 * The audit log (spec sections 3 and 10; check D5) [F10, D47]: every action, who did it, on which device and
 * what changed. A manager reads the branch's actions with sensitive values held back; the owner reads them
 * too. Nobody can change or delete an entry here.
 */
interface Page {
    entries: AuditEntry[];
    next: string | null;
    sensitive: boolean;
    people: { id: string; name: string; role: string }[];
}

const { button } = styles;

export function AuditLog({ api }: { api: CounterApi }) {
    const [page, setPage] = useState<Page | null>(null);
    const [entries, setEntries] = useState<AuditEntry[]>([]);
    const [day, setDay] = useState('');
    const [person, setPerson] = useState('');
    const [problem, setProblem] = useState<string | null>(null);

    const load = useCallback(
        (before: string | null) => {
            const q = new URLSearchParams();
            if (day !== '') q.set('day', day);
            if (person !== '') q.set('person', person);
            if (before !== null) q.set('before', before);
            void api
                .get<Page>(`/api/back-office/audit?${q.toString()}`)
                .then((a) => {
                    if (!a.ok) {
                        setProblem('The audit log could not load.');
                        return;
                    }
                    setPage(a.body);
                    setEntries((old) => (before === null ? a.body.entries : [...old, ...a.body.entries]));
                })
                .catch(() => {
                    setProblem('The device cannot reach the shop server. Try again.');
                });
        },
        [api, day, person],
    );

    useEffect(() => {
        load(null);
    }, [load]);

    return (
        <div className="flex flex-col gap-5">
            <header className="flex flex-col gap-1">
                <h1 className="text-3xl font-bold">Audit log</h1>
                <p className="text-lg text-text-secondary">
                    Every action, who did it and on which device. Nothing here can be changed or deleted{page?.sensitive === true ? '' : '; values only the owner may see are held back'}.
                </p>
            </header>
            {problem !== null && <Notice tone="error">{problem}</Notice>}
            <div className="flex flex-wrap gap-3">
                <label className="flex flex-col gap-1 text-base font-semibold">
                    Day
                    <input
                        type="date"
                        value={day}
                        className="min-h-touch rounded-xl border-2 border-border px-3 text-lg font-normal"
                        onChange={(e) => {
                            setDay(e.target.value);
                        }}
                    />
                </label>
                <label className="flex flex-col gap-1 text-base font-semibold">
                    Person
                    <select
                        value={person}
                        className="min-h-touch rounded-xl border-2 border-border px-3 text-lg font-normal"
                        onChange={(e) => {
                            setPerson(e.target.value);
                        }}
                    >
                        <option value="">Everyone</option>
                        {(page?.people ?? []).map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            {page !== null && entries.length === 0 && <p className="text-lg">Nothing was recorded for that choice.</p>}
            <ul className="flex flex-col">
                {entries.map((e) => (
                    <AuditRow key={e.id} e={e} />
                ))}
            </ul>
            {page?.next != null && (
                <button
                    type="button"
                    className={`${button} self-start`}
                    onClick={() => {
                        load(page.next);
                    }}
                >
                    Show older
                </button>
            )}
        </div>
    );
}
