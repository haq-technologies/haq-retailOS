import { useEffect, useState } from 'react';
import { CheckIcon, PrinterIcon, WarningIcon } from '@/components/icons';
import { naira } from '../money';
import type { CounterApi } from '../use-api';
import type { SaleSummary } from './types';

/**
 * After Pay (wireframe AfterPay): the change to give, whether the receipt printed, Reprint, the drawer
 * note with Opened with key, and Next customer. Scanning the next item also starts a new sale.
 */
interface Props {
    sale: SaleSummary;
    api: CounterApi;
    onNext: () => void;
}

export function AfterPay({ sale, api, onNext }: Props) {
    const [print, setPrint] = useState<'printing' | 'printed' | 'failed'>(sale.print_status === 'printed' ? 'printed' : 'printing');
    const [note, setNote] = useState<string | null>(null);
    const cash = sale.payments.find((p) => p.method === 'cash');

    useEffect(() => {
        if (print !== 'printing') return;
        const timer = window.setTimeout(() => {
            void api
                .get<{ print_jobs: { sale_id: string; kind: string }[] }>('/api/counter/print-jobs')
                .then((answer) => {
                    const stuck = answer.ok && answer.body.print_jobs.some((j) => j.sale_id === sale.id && j.kind === 'receipt');
                    setPrint(stuck ? 'failed' : 'printed');
                })
                .catch(() => {
                    setPrint('failed');
                });
        }, 6000);
        return () => {
            window.clearTimeout(timer);
        };
    }, [print, api, sale.id]);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Enter' || event.key === 'Escape') {
                event.preventDefault();
                onNext();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('keydown', onKey);
        };
    }, [onNext]);

    return (
        <section aria-label="Sale complete" className="flex flex-col gap-5">
            <h2 className="flex items-center gap-2 text-3xl font-bold">
                <CheckIcon className="h-8 w-8 text-pay" /> Sale complete
            </h2>
            {cash !== undefined && (cash.change_kobo ?? 0) > 0 && (
                <div className="rounded-2xl border-2 border-pay p-5">
                    <p className="text-xl">Give change</p>
                    <p className="text-6xl font-bold">{naira(cash.change_kobo ?? 0)}</p>
                    <p className="text-lg text-text-secondary">
                        Cash received {naira(cash.tendered_kobo ?? 0)} for a total of {naira(sale.total_kobo)}
                    </p>
                </div>
            )}
            <div className="flex flex-wrap items-center gap-3 text-lg">
                {print === 'failed' ? (
                    <span className="flex items-center gap-2 font-semibold text-warning-text">
                        <WarningIcon /> The receipt did not print · {sale.receipt_number}
                    </span>
                ) : (
                    <span className="flex items-center gap-2">
                        <PrinterIcon /> {print === 'printed' ? 'Receipt printed' : 'Printing the receipt'} · {sale.receipt_number}
                    </span>
                )}
                <button
                    type="button"
                    className="min-h-touch rounded-xl border-2 border-border px-4"
                    onClick={() => {
                        void api.post(`/api/counter/sales/${sale.id}/reprint`, {}).then((answer) => {
                            setNote(answer.ok ? 'Reprinting, stamped DUPLICATE.' : 'The reprint did not start. Check the printer.');
                        });
                    }}
                >
                    Reprint
                </button>
            </div>
            {cash !== undefined && (
                <div className="flex flex-col gap-2 rounded-xl bg-panel p-4">
                    <p className="font-semibold">Drawer opened</p>
                    <p className="text-text-secondary">Did it stay shut? Open it with the key, then tap Opened with key.</p>
                    <button
                        type="button"
                        className="min-h-touch self-start rounded-xl border-2 border-border px-4 text-lg"
                        onClick={() => {
                            void api.post('/api/counter/drawer/key-open', {}).then((answer) => {
                                setNote(answer.ok ? 'The key opening is logged.' : 'That did not save. Try again.');
                            });
                        }}
                    >
                        Opened with key
                    </button>
                </div>
            )}
            {note !== null && <p className="text-lg">{note}</p>}
            <button type="button" className="min-h-[72px] rounded-xl bg-action text-2xl font-bold text-white" onClick={onNext}>
                Next customer
            </button>
            <p className="text-center text-text-secondary">Or scan the next item to start a new sale.</p>
        </section>
    );
}

/** The sale just paid, beside After Pay (wireframe AfterPay): its number, lines, payments and total. */
export function PaidSummary({ sale }: { sale: SaleSummary }) {
    const method = (m: string) => (m === 'pos' ? 'POS' : m.charAt(0).toUpperCase() + m.slice(1));
    return (
        <aside aria-label="Paid" className="flex h-full min-h-0 flex-col bg-panel">
            <header className="flex items-baseline justify-between px-4 pt-4">
                <h2 className="text-2xl font-semibold">Paid</h2>
                <span className="text-text-secondary">{sale.receipt_number}</span>
            </header>
            <ol className="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto p-4">
                {sale.lines.map((line) => (
                    <li key={line.client_line_id} className="flex items-start justify-between gap-3 rounded-xl bg-background p-3">
                        <span className="flex flex-col">
                            <span className="text-lg font-semibold">{line.name}</span>
                            <span className="text-text-secondary">
                                {line.colour === null ? '' : `Colour: ${line.colour} · `}
                                {line.qty > 1 ? `${String(line.qty)} × ${naira(line.unit_price_kobo)}` : '1'}
                            </span>
                        </span>
                        <strong className="text-lg">{naira(line.line_total_kobo)}</strong>
                    </li>
                ))}
            </ol>
            <footer className="flex flex-col gap-2 border-t border-border p-4 text-lg">
                {sale.payments.map((p, i) => (
                    <p key={i} className="flex justify-between">
                        <span>{method(p.method)}</span>
                        <span>{naira(p.method === 'cash' ? (p.tendered_kobo ?? p.amount_kobo) : p.amount_kobo)}</span>
                    </p>
                ))}
                {sale.change_kobo > 0 && (
                    <p className="flex justify-between">
                        <span>Change</span>
                        <span>{naira(sale.change_kobo)}</span>
                    </p>
                )}
                <p className="flex items-baseline justify-between">
                    <span className="text-xl">Total</span>
                    <strong className="text-[40px] leading-none">{naira(sale.total_kobo)}</strong>
                </p>
            </footer>
        </aside>
    );
}
