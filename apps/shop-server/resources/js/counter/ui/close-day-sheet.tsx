import { useCallback, useEffect, useState } from 'react';
import { Keypad } from '@/components/keypad';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import { digitsToKobo, naira, typeDigits } from '../money';
import type { CounterApi } from '../use-api';
import { useKeypadKeys } from './drawer-shared';

interface Closed {
    shift: {
        drawer: string;
        business_day: string;
        expected_cash_kobo: number;
        counted_cash_kobo: number | null;
        variance_kobo: number | null;
        flagged: boolean;
        sales: { count: number; total_kobo: number };
    };
    printed: boolean;
    print_error: string | null;
}

/**
 * Closing the day (D68): a blind count, so the drawer's holder types what is in it before seeing what was
 * expected; then the difference, and the Z-report prints.
 */
export function CloseDaySheet({ api, onClose }: { api: CounterApi; onClose: () => void }) {
    const [state, setState] = useState<{ open: { business_day: string } | null; last_closed: string | null } | null>(null);
    const [digits, setDigits] = useState('');
    const [closed, setClosed] = useState<Closed | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const typeKey = useCallback(
        (key: string) => {
            if (closed === null) setDigits((d) => typeDigits(d, key));
        },
        [closed],
    );
    useKeypadKeys(typeKey);

    useEffect(() => {
        void api
            .get<{ open: { business_day: string } | null; last_closed: string | null }>('/api/counter/close')
            .then((a) => {
                if (a.ok) setState(a.body);
            })
            .catch(() => {
                setError('The counter cannot reach the server. Try again.');
            });
    }, [api]);

    const post = async (url: string, body: Record<string, unknown>) => {
        setBusy(true);
        setError(null);
        try {
            const a = await api.post<Closed & { message?: string }>(url, body, 30_000);
            if (a.ok) setClosed(a.body);
            else setError(a.body.message ?? 'That did not work. Try again.');
        } catch {
            setError('The server did not answer. Check whether the day closed before trying again.');
        } finally {
            setBusy(false);
        }
    };

    const v = closed?.shift.variance_kobo ?? null;
    return (
        <Sheet title="Close the day" onClose={onClose}>
            {closed === null && state?.open !== null && state !== null && (
                <div className="flex flex-col gap-3">
                    <p className="text-lg">Count all the cash in the drawer, then type it. You see what was expected after.</p>
                    <output aria-live="polite" aria-label="Cash counted" className="flex min-h-touch items-center rounded-xl border-2 border-action px-4 text-4xl font-bold">
                        {digits === '' ? <span className="text-text-secondary">₦</span> : naira(digitsToKobo(digits))}
                    </output>
                    <Keypad
                        onKey={(key) => {
                            setDigits((d) => typeDigits(d, key));
                        }}
                    />
                    <button
                        type="button"
                        disabled={digits === '' || busy}
                        className="min-h-touch rounded-xl bg-action text-lg font-bold text-white disabled:opacity-60"
                        onClick={() => void post('/api/counter/close', { counted_kobo: digitsToKobo(digits) })}
                    >
                        {busy ? 'Closing…' : 'Close the day'}
                    </button>
                </div>
            )}
            {closed === null && state !== null && state.open === null && (
                <div className="flex flex-col gap-3">
                    <p className="text-lg">There is no open shift on this counter. The next sale opens one.</p>
                    {state.last_closed !== null && (
                        <button type="button" disabled={busy} className="min-h-touch rounded-xl border-2 border-border text-lg" onClick={() => void post('/api/counter/close/print', {})}>
                            Print the last Z-report again
                        </button>
                    )}
                </div>
            )}
            {closed !== null && (
                <div className="flex flex-col gap-2 text-lg">
                    <p className="flex justify-between">
                        <span>Expected</span>
                        <strong>{naira(closed.shift.expected_cash_kobo)}</strong>
                    </p>
                    <p className="flex justify-between">
                        <span>Counted</span>
                        <strong>{naira(closed.shift.counted_cash_kobo ?? 0)}</strong>
                    </p>
                    <p className="flex justify-between">
                        <span>{v === 0 ? 'The drawer is right' : v !== null && v > 0 ? 'Over by' : 'Short by'}</span>
                        <strong>{v === 0 || v === null ? '' : naira(Math.abs(v))}</strong>
                    </p>
                    {closed.shift.flagged && <Notice tone="warning">The difference is more than the shop allows, so the owner sees it in Flags.</Notice>}
                    {closed.printed ? (
                        <p className="text-text-secondary">The Z-report is printing.</p>
                    ) : (
                        <>
                            <Notice tone="error">{closed.print_error ?? 'The Z-report did not print.'}</Notice>
                            <button type="button" disabled={busy} className="min-h-touch rounded-xl border-2 border-border text-lg" onClick={() => void post('/api/counter/close/print', {})}>
                                Print the Z-report again
                            </button>
                        </>
                    )}
                </div>
            )}
            {error !== null && <Notice tone="error">{error}</Notice>}
        </Sheet>
    );
}
