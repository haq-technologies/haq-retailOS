import { useCallback, useEffect, useState } from 'react';
import { CheckIcon, ClockIcon, WarningIcon } from '@/components/icons';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import type { CounterApi } from '@/counter/use-api';
import type { ApiResult } from '@/lib/api';
import { DataExport } from './data-export';
import { clock, longDay, naira, nairaToKobo, plural, shortDate } from './format';
import { styles } from './pickers';
import { SaleSheet } from './sale-sheet';

/**
 * The measured pilot (Phase 5; spec section 14, Pilot success thresholds) [D56]. For 14 trading days every
 * counter runs beside the notebook. Each evening a manager or the owner types the notebook's totals and
 * traces each difference to the notebook or to the app; the five measures are read live; at gate G5 the
 * owner records continue or stop. Totals and percentages only, never cost.
 */
type Status = 'passed' | 'failed' | 'open';

interface DayRow {
    n: number;
    day: string;
    today: boolean;
    app_kobo: number;
    notebook_kobo: number | null;
    left_kobo: number;
    verdict: string;
}

interface Decision {
    decision: 'continue' | 'stop';
    note: string | null;
    at: string;
    by: string;
    notebook_stops: string | null;
    changes: number;
}

interface Card {
    started: boolean;
    today: string;
    start: { first_day: string; at: string; by: string; note: string | null; restarts: number } | null;
    measured?: number;
    complete?: boolean;
    days?: DayRow[];
    totals?: { status: Status; matching: number; not_matching: number; waiting: number; needed: number; of: number };
    receipts?: { status: Status; sales: number; duplicates: number; gaps: { counter: string; epoch: number; missing: number; low: number; high: number }[]; no_receipt: number; pending_print: string[]; lost_waiting: number; recovered: number };
    speed?: { status: Status; timed: number; days_with: number; days_under: number; cashiers: { cashier: string; count: number; median_ms: number | null; under_target: number }[] };
    coverage?: { status: Status; counted_at: string | null; percent: number; confirmed_percent: number; estimated_percent: number; pending_percent: number; now_percent: number | null };
    passed?: number;
    decision: Decision | null;
    can_decide: boolean;
    can_export: boolean;
    rules: { days: number; match_days: number; speed_target_ms: number; speed_min_sales: number; coverage_percent: number; notebook_stops_after_days: number };
}

interface MethodRow {
    method: string;
    app_kobo: number;
    notebook_kobo: number | null;
    difference_kobo: number | null;
    traced_kobo: number;
    left_kobo: number | null;
}

interface Trace {
    id: string;
    method: string;
    amount_kobo: number;
    side: 'notebook' | 'app';
    what: string;
    receipt_number: string | null;
    by: string;
    at: string;
}

interface Day {
    day: string;
    n: number | null;
    today: boolean;
    open: boolean;
    app_kobo: number;
    notebook_kobo: number | null;
    entered: { by: string; at: string } | null;
    methods: MethodRow[];
    traces: Trace[];
    verdict: string;
    sales: { id: string; receipt_number: string; at: string; back_entry: boolean; total_kobo: number; counter: string; cashier: string; cash_kobo: number; transfer_kobo: number; pos_kobo: number }[];
}

const { button, primary } = styles;
const field = 'min-h-touch rounded-xl border-2 border-border bg-background px-3 text-lg font-normal';
const methodName: Record<string, string> = { cash: 'Cash', transfer: 'Transfer', pos: 'POS' };

/** A day's verdict in words, with an icon: never colour alone (spec section 15). */
const verdicts: Record<string, { label: string; tone: 'good' | 'bad' | 'wait' }> = {
    matches: { label: 'Matches', tone: 'good' },
    matches_traced: { label: 'Matches once traced', tone: 'good' },
    app_wrong: { label: 'Does not match', tone: 'bad' },
    to_trace: { label: 'Still to trace', tone: 'wait' },
    no_notebook: { label: 'Notebook not entered', tone: 'wait' },
};

const statuses: Record<Status, { label: string; tone: 'good' | 'bad' | 'wait' }> = {
    passed: { label: 'Met', tone: 'good' },
    failed: { label: 'Not met', tone: 'bad' },
    open: { label: 'In progress', tone: 'wait' },
};

function Mark({ tone, label }: { tone: 'good' | 'bad' | 'wait'; label: string }) {
    const colour = tone === 'good' ? 'text-pay' : tone === 'bad' ? 'text-destructive' : 'text-text-secondary';
    const Icon = tone === 'good' ? CheckIcon : tone === 'bad' ? WarningIcon : ClockIcon;
    return (
        <span className={`inline-flex items-center gap-2 font-semibold ${colour}`}>
            <Icon className="h-5 w-5 shrink-0" />
            {label}
        </span>
    );
}

function seconds(ms: number | null): string {
    return ms === null ? '–' : `${String(Math.round(ms / 1000))} s`;
}

/** The difference as words: "₦2,000 more in the app". */
function difference(kobo: number): string {
    if (kobo === 0) return 'Same';
    return `${naira(Math.abs(kobo))} more in the ${kobo > 0 ? 'app' : 'notebook'}`;
}

function Measure({ title, status, children }: { title: string; status: Status; children: React.ReactNode }) {
    const s = statuses[status];
    return (
        <section aria-label={title} className={`flex flex-col gap-2 rounded-2xl p-4 ${status === 'failed' ? 'border-2 border-destructive bg-background' : 'bg-panel'}`}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-lg font-bold">{title}</h2>
                <Mark tone={s.tone} label={s.label} />
            </div>
            {children}
        </section>
    );
}

export function MeasuredPilot({ api, onChanged }: { api: CounterApi; onChanged: () => void }) {
    const [card, setCard] = useState<Card | null>(null);
    const [open, setOpen] = useState<string | null>(null);
    const [message, setMessage] = useState<{ tone: 'error' | 'ok'; text: string } | null>(null);

    const load = useCallback(() => {
        void api
            .get<Card>('/api/back-office/pilot')
            .then((a) => {
                if (a.ok) setCard(a.body);
            })
            .catch(() => {
                setMessage({ tone: 'error', text: 'The pilot could not load. Check the connection.' });
            });
    }, [api]);

    useEffect(() => {
        load();
    }, [load]);

    const changed = () => {
        load();
        onChanged();
    };

    if (card === null) return message === null ? <p className="text-lg text-text-secondary">Loading…</p> : <Notice tone="error">{message.text}</Notice>;
    const r = card.rules;

    return (
        <div className="flex flex-col gap-6">
            <header className="flex flex-col gap-1">
                <h1 className="text-3xl font-bold">Measured pilot</h1>
                <p className="text-lg text-text-secondary">
                    {card.started && card.start !== null
                        ? `${card.complete === true ? `All ${String(r.days)} days measured` : `Day ${String(card.measured ?? 0)} of ${String(r.days)}`} · from ${longDay(card.start.first_day)}${card.start.restarts > 0 ? ` · started again ${plural(card.start.restarts, 'time', 'times')}` : ''}`
                        : `${String(r.days)} trading days beside the notebook, then the owner decides.`}
                </p>
            </header>

            {message !== null && (message.tone === 'error' ? <Notice tone="error">{message.text}</Notice> : <p role="status" className="rounded-xl bg-panel p-4 text-lg">{message.text}</p>)}

            {!card.started ? (
                <StartCard api={api} card={card} onDone={changed} />
            ) : (
                <>
                    <Scorecard card={card} />
                    <DaysTable card={card} onOpen={setOpen} />
                    {card.decision === null && card.can_decide && <StartCard api={api} card={card} onDone={changed} again />}
                    <DecisionCard api={api} card={card} onDone={changed} />
                    {/* Continue or stop, the owner takes all the shop's data (spec section 14; D57). */}
                    {card.can_export && <DataExport api={api} />}
                </>
            )}

            {open !== null && (
                <DaySheet
                    api={api}
                    day={open}
                    onClose={() => {
                        setOpen(null);
                    }}
                    onChanged={changed}
                />
            )}
        </div>
    );
}

function StartCard({ api, card, onDone, again = false }: { api: CounterApi; card: Card; onDone: () => void; again?: boolean }) {
    const [day, setDay] = useState(card.today);
    const [note, setNote] = useState('');
    const [shown, setShown] = useState(!again);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const r = card.rules;

    if (!card.can_decide) {
        return <p className="rounded-2xl bg-panel p-5 text-lg">The owner starts the {r.days} days. Until then there is nothing to measure.</p>;
    }
    if (!shown) {
        return (
            <button type="button" className={`${button} self-start`} onClick={() => { setShown(true); }}>
                Start the {r.days} days again
            </button>
        );
    }

    const begun = card.start !== null && card.start.first_day <= card.today;
    const submit = async () => {
        setBusy(true);
        setError(null);
        try {
            const a = await api.post<{ message?: string }>('/api/back-office/pilot/start', { first_day: day, note: note.trim() === '' ? null : note.trim() });
            if (a.ok) {
                setShown(!again);
                setNote('');
                onDone();
            } else setError(a.body.message ?? 'That did not work. Try again.');
        } catch {
            setError('The device cannot reach the shop server. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <section aria-labelledby="pilot-start" className="flex flex-col gap-3 rounded-2xl border-2 border-border p-5">
            <h2 id="pilot-start" className="text-xl font-bold">
                {again ? `Start the ${String(r.days)} days again` : `Start the ${String(r.days)} days`}
            </h2>
            {again ? (
                <p className="text-text-secondary">
                    {begun ? 'Only if something stopped a fair measure, such as the counters not being set up. The days so far stay on record, and the new start is today or later.' : 'The first day has not come yet, so it can still move.'}
                </p>
            ) : (
                <ul className="flex list-disc flex-col gap-1 pl-6 text-lg">
                    <li>Every counter sells beside the notebook. Each evening, type the notebook&apos;s totals for cash, transfer and POS.</li>
                    <li>Trace each difference: was the notebook wrong, or the app?</li>
                    <li>
                        After {r.days} trading days you decide: continue with the app as the shop&apos;s record, or stop. Either way you take all your data.
                    </li>
                </ul>
            )}
            {error !== null && <Notice tone="error">{error}</Notice>}
            <div className="flex flex-wrap items-end gap-3">
                <label className="flex flex-col gap-1 text-lg font-semibold">
                    First day
                    <input type="date" value={day} min={card.today} className={field} onChange={(e) => { setDay(e.target.value); }} />
                </label>
                {again && begun && (
                    <label className="flex min-w-64 flex-1 flex-col gap-1 text-lg font-semibold">
                        Why they start again
                        <input type="text" value={note} maxLength={200} className={field} onChange={(e) => { setNote(e.target.value); }} />
                    </label>
                )}
                <button type="button" disabled={busy || day === ''} className={primary} onClick={() => void submit()}>
                    {busy ? 'Starting…' : again ? 'Start again' : `Start the ${String(r.days)} days`}
                </button>
                {again && (
                    <button type="button" className={button} onClick={() => { setShown(false); }}>
                        Cancel
                    </button>
                )}
            </div>
        </section>
    );
}

function Scorecard({ card }: { card: Card }) {
    const r = card.rules;
    const { totals, receipts, speed, coverage } = card;
    if (totals === undefined || receipts === undefined || speed === undefined || coverage === undefined) return null;
    const problems = [
        ...receipts.gaps.map((g) => `${g.counter} epoch ${String(g.epoch)}: ${plural(g.missing, 'number', 'numbers')} missing between ${String(g.low)} and ${String(g.high)}`),
        ...(receipts.duplicates > 0 ? [`${plural(receipts.duplicates, 'receipt number', 'receipt numbers')} used twice`] : []),
        ...(receipts.no_receipt > 0 ? [`${plural(receipts.no_receipt, 'sale', 'sales')} with no receipt`] : []),
        ...(receipts.lost_waiting > 0 ? [`${plural(receipts.lost_waiting, 'lost sale', 'lost sales')} still to re-enter on Lost sales`] : []),
    ];

    return (
        <div className="grid gap-4 medium:grid-cols-2">
            <Measure title="Daily totals" status={totals.status}>
                <p className="text-3xl font-bold">
                    {totals.matching} of {totals.of} days match
                </p>
                <p className="text-text-secondary">
                    The goal: at least {totals.needed}. {totals.not_matching > 0 && `${plural(totals.not_matching, 'day does', 'days do')} not match. `}
                    {totals.waiting > 0 && `${plural(totals.waiting, 'day waits', 'days wait')} for the notebook or for tracing.`}
                </p>
            </Measure>

            <Measure title="Receipts" status={receipts.status}>
                <p className="text-3xl font-bold">{plural(receipts.sales, 'sale', 'sales')}</p>
                {problems.length === 0 ? (
                    <p className="text-text-secondary">
                        Every receipt number used once, with no gap; every sale printed or waiting to print; no sale lost.
                        {receipts.recovered > 0 && ` ${plural(receipts.recovered, 'sale was', 'sales were')} re-entered after a restore.`}
                    </p>
                ) : (
                    <ul className="flex list-disc flex-col gap-1 pl-6 font-semibold">
                        {problems.map((p) => (
                            <li key={p}>{p}</li>
                        ))}
                    </ul>
                )}
                {receipts.pending_print.length > 0 && <p className="text-text-secondary">Waiting to print: {receipts.pending_print.slice(0, 5).join(', ')}{receipts.pending_print.length > 5 ? ' …' : ''}</p>}
            </Measure>

            <Measure title="Counter speed" status={speed.status}>
                <p className="text-3xl font-bold">
                    {speed.days_under} of {speed.days_with} days
                </p>
                <p className="text-text-secondary">
                    Days whose typical 3-item sale took under {String(r.speed_target_ms / 1000)} seconds. The goal: {r.match_days} of every {r.days}, over at least {r.speed_min_sales} timed 3-item sales ({speed.timed} so far).
                </p>
                {speed.cashiers.length > 0 && (
                    <ul className="flex flex-col gap-1">
                        {speed.cashiers.map((c) => (
                            <li key={c.cashier} className="flex justify-between gap-2">
                                <span>{c.cashier}</span>
                                <span className="text-text-secondary">
                                    typical {seconds(c.median_ms)} · {c.under_target} of {c.count} under {String(r.speed_target_ms / 1000)} s
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Measure>

            <Measure title="Revenue with a cost" status={coverage.status}>
                <p className="text-3xl font-bold">{coverage.percent}%</p>
                <p className="text-text-secondary">
                    Cost at sale {coverage.confirmed_percent}% · estimated {coverage.estimated_percent}% · waiting {coverage.pending_percent}%. The goal: {r.coverage_percent}% by the end of day {r.days}
                    {coverage.counted_at !== null ? `, counted at ${clock(coverage.counted_at)} on ${shortDate(coverage.counted_at)}.` : '.'}
                    {coverage.now_percent !== null && coverage.now_percent !== coverage.percent && ` Now ${String(coverage.now_percent)}%, which does not change the measure.`}
                </p>
            </Measure>
        </div>
    );
}

function DaysTable({ card, onOpen }: { card: Card; onOpen: (day: string) => void }) {
    const days = card.days ?? [];
    if (days.length === 0) return <p className="text-lg text-text-secondary">No trading day yet. The first sale or notebook total from {longDay(card.start?.first_day ?? card.today)} starts day 1.</p>;
    return (
        <section aria-labelledby="pilot-days" className="flex flex-col gap-2">
            <h2 id="pilot-days" className="text-xl font-bold">
                The days
            </h2>
            <div className="overflow-x-auto rounded-2xl border-2 border-border">
                <table className="w-full min-w-[640px] text-left text-lg tabular-nums">
                    <thead className="text-base text-text-secondary">
                        <tr className="border-b border-border">
                            <th scope="col" className="p-3 font-semibold">Day</th>
                            <th scope="col" className="p-3 text-right font-semibold">App</th>
                            <th scope="col" className="p-3 text-right font-semibold">Notebook</th>
                            <th scope="col" className="p-3 font-semibold">Verdict</th>
                            <th scope="col" className="p-3"><span className="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {days.map((d) => {
                            const v = verdicts[d.verdict] ?? { label: d.verdict, tone: 'wait' as const };
                            return (
                                <tr key={d.day} className="border-b border-border last:border-b-0">
                                    <td className="p-3">
                                        <span className="font-semibold">Day {d.n}</span> · {shortDate(`${d.day}T12:00:00`)}
                                        {d.today && <span className="text-text-secondary"> · today</span>}
                                    </td>
                                    <td className="p-3 text-right">{naira(d.app_kobo)}</td>
                                    <td className="p-3 text-right">{d.notebook_kobo === null ? '–' : naira(d.notebook_kobo)}</td>
                                    <td className="p-3">
                                        <Mark tone={v.tone} label={v.label} />
                                        {d.verdict !== 'no_notebook' && d.left_kobo > 0 && <span className="block text-base text-text-secondary">{naira(d.left_kobo)} to trace</span>}
                                    </td>
                                    <td className="p-3 text-right">
                                        <button type="button" className={button} onClick={() => { onOpen(d.day); }}>
                                            Open
                                        </button>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function DaySheet({ api, day, onClose, onChanged }: { api: CounterApi; day: string; onClose: () => void; onChanged: () => void }) {
    const [data, setData] = useState<Day | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [amounts, setAmounts] = useState<Record<string, string>>({ cash: '', transfer: '', pos: '' });
    const [tracing, setTracing] = useState<MethodRow | null>(null);
    const [sale, setSale] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const fetchDay = useCallback(() => api.get<Day>(`/api/back-office/pilot/days/${day}`), [api, day]);
    const show = useCallback((a: ApiResult<Day>) => {
        if (!a.ok) return;
        setData(a.body);
        setAmounts(Object.fromEntries(a.body.methods.map((m) => [m.method, m.notebook_kobo === null ? '' : String(m.notebook_kobo / 100)])));
    }, []);

    useEffect(() => {
        void fetchDay()
            .then(show)
            .catch(() => {
                setError('The day could not load. Check the connection.');
            });
    }, [fetchDay, show]);

    const send = async (url: string, body: unknown): Promise<boolean> => {
        setBusy(true);
        setError(null);
        try {
            const a = await api.post<{ message?: string }>(url, body);
            if (!a.ok) {
                setError(a.body.message ?? 'That did not work. Try again.');
                return false;
            }
            // Shown before the form closes, so no button from before the change can be tapped.
            show(await fetchDay());
            onChanged();
            return true;
        } catch {
            setError('The device cannot reach the shop server. Try again.');
            return false;
        } finally {
            setBusy(false);
        }
    };

    const saveNotebook = () => {
        const kobo = Object.fromEntries(Object.entries(amounts).map(([m, text]) => [m, nairaToKobo(text)]));
        if (Object.values(kobo).some((k) => k === null)) {
            setError('Type each total in naira, such as 12500; type 0 for none.');
            return;
        }
        void send('/api/back-office/pilot/notebook', { day, cash_kobo: kobo.cash, transfer_kobo: kobo.transfer, pos_kobo: kobo.pos });
    };

    const title = data?.n !== null && data?.n !== undefined ? `Day ${String(data.n)} · ${longDay(day)}` : longDay(day);
    if (sale !== null) return <SaleSheet api={api} saleIds={[sale]} onClose={() => { setSale(null); }} />;

    return (
        <Sheet title={title} onClose={onClose} wide>
            {data === null ? (
                error === null ? <p className="text-lg text-text-secondary">Loading…</p> : <Notice tone="error">{error}</Notice>
            ) : (
                <div className="flex flex-col gap-5">
                    <p className="text-lg">
                        <Mark tone={verdicts[data.verdict]?.tone ?? 'wait'} label={verdicts[data.verdict]?.label ?? data.verdict} />
                        {data.today && <span className="text-text-secondary"> · still trading today</span>}
                        {data.entered !== null && <span className="text-text-secondary"> · notebook typed by {data.entered.by} at {clock(data.entered.at)}</span>}
                    </p>
                    {error !== null && <Notice tone="error">{error}</Notice>}

                    <div className="overflow-x-auto rounded-2xl border-2 border-border">
                        <table className="w-full min-w-[600px] text-left text-lg tabular-nums">
                            <thead className="text-base text-text-secondary">
                                <tr className="border-b border-border">
                                    <th scope="col" className="p-3 font-semibold">Payment</th>
                                    <th scope="col" className="p-3 text-right font-semibold">App</th>
                                    <th scope="col" className="p-3 text-right font-semibold">Notebook</th>
                                    <th scope="col" className="p-3 font-semibold">Difference</th>
                                    <th scope="col" className="p-3"><span className="sr-only">Trace</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.methods.map((m) => (
                                    <tr key={m.method} className="border-b border-border last:border-b-0">
                                        <td className="p-3 font-semibold">{methodName[m.method] ?? m.method}</td>
                                        <td className="p-3 text-right">{naira(m.app_kobo)}</td>
                                        <td className="p-3 text-right">
                                            {data.open ? (
                                                <label>
                                                    <span className="sr-only">Notebook {methodName[m.method]}</span>
                                                    <input
                                                        inputMode="decimal"
                                                        value={amounts[m.method] ?? ''}
                                                        placeholder="₦"
                                                        className={`${field} w-36 text-right`}
                                                        onChange={(e) => { setAmounts({ ...amounts, [m.method]: e.target.value }); }}
                                                    />
                                                </label>
                                            ) : m.notebook_kobo === null ? '–' : naira(m.notebook_kobo)}
                                        </td>
                                        <td className="p-3">
                                            {m.difference_kobo === null ? '–' : difference(m.difference_kobo)}
                                            {m.left_kobo !== null && m.left_kobo !== 0 && m.left_kobo !== m.difference_kobo && <span className="block text-base text-text-secondary">{naira(Math.abs(m.left_kobo))} left to trace</span>}
                                        </td>
                                        <td className="p-3 text-right">
                                            {data.open && m.left_kobo !== null && m.left_kobo !== 0 && (
                                                <button type="button" className={button} onClick={() => { setTracing(m); }}>
                                                    Trace
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {data.open && (
                        <div className="flex flex-wrap items-center gap-3">
                            <button type="button" disabled={busy} className={primary} onClick={saveNotebook}>
                                {data.notebook_kobo === null ? 'Save the notebook’s totals' : 'Correct the notebook’s totals'}
                            </button>
                            <span className="text-text-secondary">Type them as the notebook shows them, before looking at the app&apos;s.</span>
                        </div>
                    )}

                    {tracing !== null && (
                        <TraceForm
                            row={tracing}
                            busy={busy}
                            onCancel={() => { setTracing(null); }}
                            onSave={async (body) => {
                                if (await send('/api/back-office/pilot/traces', { day, method: tracing.method, ...body })) setTracing(null);
                            }}
                        />
                    )}

                    {data.traces.length > 0 && (
                        <section aria-labelledby="pilot-traces" className="flex flex-col gap-2">
                            <h3 id="pilot-traces" className="text-lg font-bold">
                                Traced and written down
                            </h3>
                            <ul className="flex flex-col gap-2">
                                {data.traces.map((t) => (
                                    <li key={t.id} className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-border px-4 py-3">
                                        <span className="flex-1">
                                            <strong>
                                                {methodName[t.method]} {naira(Math.abs(t.amount_kobo))}
                                            </strong>{' '}
                                            · {t.side === 'notebook' ? 'the notebook was wrong' : 'the app was wrong'} · {t.what}
                                            {t.receipt_number !== null && ` · ${t.receipt_number}`}
                                            <span className="block text-base text-text-secondary">
                                                {t.by}, {clock(t.at)}
                                            </span>
                                        </span>
                                        {data.open && (
                                            <button type="button" disabled={busy} className={button} onClick={() => void send(`/api/back-office/pilot/traces/${t.id}/withdraw`, {})}>
                                                Withdraw
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <section aria-labelledby="pilot-sales" className="flex flex-col gap-2">
                        <h3 id="pilot-sales" className="text-lg font-bold">
                            The day&apos;s sales ({data.sales.length})
                        </h3>
                        <p className="text-text-secondary">Tick them against the notebook to find a difference. Open one to see its items.</p>
                        {data.sales.length > 0 && (
                            <div className="max-h-96 overflow-auto rounded-2xl border-2 border-border">
                                <table className="w-full min-w-[600px] text-left text-base tabular-nums">
                                    <thead className="sticky top-0 bg-background text-text-secondary">
                                        <tr className="border-b border-border">
                                            <th scope="col" className="p-2 font-semibold">Receipt</th>
                                            <th scope="col" className="p-2 font-semibold">Time</th>
                                            <th scope="col" className="p-2 font-semibold">Cashier</th>
                                            <th scope="col" className="p-2 text-right font-semibold">Cash</th>
                                            <th scope="col" className="p-2 text-right font-semibold">Transfer</th>
                                            <th scope="col" className="p-2 text-right font-semibold">POS</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {data.sales.map((s) => (
                                            <tr key={s.id} className="border-b border-border last:border-b-0">
                                                <td className="p-2">
                                                    <button type="button" className="min-h-touch font-semibold text-action-text" onClick={() => { setSale(s.id); }}>
                                                        {s.receipt_number}
                                                    </button>
                                                    {s.back_entry && <span className="text-text-secondary"> · re-entered</span>}
                                                </td>
                                                <td className="p-2">{clock(s.at)}</td>
                                                <td className="p-2">{s.cashier}</td>
                                                <td className="p-2 text-right">{s.cash_kobo === 0 ? '' : naira(s.cash_kobo)}</td>
                                                <td className="p-2 text-right">{s.transfer_kobo === 0 ? '' : naira(s.transfer_kobo)}</td>
                                                <td className="p-2 text-right">{s.pos_kobo === 0 ? '' : naira(s.pos_kobo)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                </div>
            )}
        </Sheet>
    );
}

function TraceForm({ row, busy, onCancel, onSave }: { row: MethodRow; busy: boolean; onCancel: () => void; onSave: (body: { amount_kobo: number; side: string; what: string; receipt_number: string | null }) => Promise<void> }) {
    const left = row.left_kobo ?? 0;
    const [amount, setAmount] = useState(String(Math.abs(left) / 100));
    const [side, setSide] = useState<'notebook' | 'app' | null>(null);
    const [what, setWhat] = useState('');
    const [receipt, setReceipt] = useState('');
    const [error, setError] = useState<string | null>(null);

    const save = () => {
        const kobo = nairaToKobo(amount);
        if (kobo === null || kobo <= 0) {
            setError('Type the amount this explains, in naira.');
            return;
        }
        if (side === null) {
            setError('Choose whose record was wrong.');
            return;
        }
        if (what.trim().length < 3) {
            setError('Write down what happened, in a few words.');
            return;
        }
        setError(null);
        void onSave({ amount_kobo: kobo, side, what: what.trim(), receipt_number: receipt.trim() === '' ? null : receipt.trim() });
    };

    return (
        <section aria-labelledby="pilot-trace" className="flex flex-col gap-3 rounded-2xl border-2 border-action p-4">
            <h3 id="pilot-trace" className="text-lg font-bold">
                Trace {methodName[row.method]}: {difference(left)}
            </h3>
            {error !== null && <Notice tone="error">{error}</Notice>}
            <fieldset className="flex flex-col gap-2">
                <legend className="mb-1 text-lg font-semibold">Whose record was wrong?</legend>
                {(
                    [
                        ['notebook', 'The notebook', 'A sale never written down, written wrongly, or added up wrongly. The day can still match.'],
                        ['app', 'The app', 'A sale never rung, rung twice, at the wrong price or as the wrong payment. The day does not match.'],
                    ] as const
                ).map(([value, label, hint]) => (
                    <label key={value} className={`flex min-h-touch cursor-pointer items-start gap-3 rounded-xl border-2 px-4 py-3 ${side === value ? 'border-action' : 'border-border'}`}>
                        <input type="radio" name="side" className="mt-1 h-5 w-5" checked={side === value} onChange={() => { setSide(value); }} />
                        <span>
                            <span className="block font-semibold">{label}</span>
                            <span className="text-text-secondary">{hint}</span>
                        </span>
                    </label>
                ))}
            </fieldset>
            <div className="flex flex-wrap gap-3">
                <label className="flex flex-col gap-1 text-lg font-semibold">
                    Amount it explains
                    <input inputMode="decimal" value={amount} className={`${field} w-40`} onChange={(e) => { setAmount(e.target.value); }} />
                </label>
                <label className="flex flex-col gap-1 text-lg font-semibold">
                    Receipt number (if one sale)
                    <input value={receipt} maxLength={20} placeholder="T01-E1-000123" className={`${field} w-56`} onChange={(e) => { setReceipt(e.target.value); }} />
                </label>
            </div>
            <label className="flex flex-col gap-1 text-lg font-semibold">
                What happened
                <input value={what} maxLength={200} className={field} onChange={(e) => { setWhat(e.target.value); }} />
            </label>
            <div className="flex flex-wrap gap-3">
                <button type="button" disabled={busy} className={primary} onClick={save}>
                    Write it down
                </button>
                <button type="button" className={button} onClick={onCancel}>
                    Cancel
                </button>
            </div>
        </section>
    );
}

function DecisionCard({ api, card, onDone }: { api: CounterApi; card: Card; onDone: () => void }) {
    const [choice, setChoice] = useState<'continue' | 'stop' | null>(null);
    const [note, setNote] = useState('');
    const [changing, setChanging] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const d = card.decision;
    const r = card.rules;

    const record = async () => {
        if (choice === null) return;
        setBusy(true);
        setError(null);
        try {
            const a = await api.post<{ message?: string }>('/api/back-office/pilot/decision', { decision: choice, note: note.trim() === '' ? null : note.trim() });
            if (a.ok) {
                setConfirm(false);
                setChanging(false);
                setChoice(null);
                setNote('');
                onDone();
            } else setError(a.body.message ?? 'That did not work. Try again.');
        } catch {
            setError('The device cannot reach the shop server. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <section aria-labelledby="pilot-decision" className="flex flex-col gap-3 rounded-2xl border-2 border-border p-5">
            <h2 id="pilot-decision" className="text-xl font-bold">
                The owner&apos;s decision
            </h2>
            {d !== null && (
                <div className="flex flex-col gap-1 text-lg">
                    <p>
                        <Mark tone={d.decision === 'continue' ? 'good' : 'wait'} label={d.decision === 'continue' ? 'Continue with the app' : 'Stop the pilot'} />
                        <span className="text-text-secondary">
                            {' '}
                            · {d.by}, {longDay(d.at.slice(0, 10))}
                            {d.changes > 0 && ` · changed ${plural(d.changes, 'time', 'times')}`}
                        </span>
                    </p>
                    {d.note !== null && <p>&ldquo;{d.note}&rdquo;</p>}
                    {d.notebook_stops !== null && (
                        <p>
                            The app is now the shop&apos;s record. Keep the notebook going until {longDay(d.notebook_stops)}, then stop it.
                        </p>
                    )}
                    <p className="text-text-secondary">The pilot&apos;s record is closed, with the scorecard as it stood when the decision was made.</p>
                </div>
            )}
            {d === null && !card.can_decide && <p className="text-lg text-text-secondary">After the {r.days} days the owner decides here: continue or stop.</p>}
            {card.can_decide && (d === null || changing) && (
                <>
                    {d === null && card.complete !== true && <Notice tone="warning">Only {plural(card.measured ?? 0, 'day is', 'days are')} measured of {r.days}. The decision closes the record, so it is usually made after day {r.days}.</Notice>}
                    {error !== null && <Notice tone="error">{error}</Notice>}
                    <div className="grid gap-2 medium:grid-cols-2">
                        {(
                            [
                                ['continue', 'Continue', `The app becomes the shop's record now; the notebook stops ${String(r.notebook_stops_after_days)} days later.`],
                                ['stop', 'Stop', 'The pilot ends. You take all the shop’s data with you.'],
                            ] as const
                        ).map(([value, label, hint]) => (
                            <label key={value} className={`flex min-h-touch cursor-pointer items-start gap-3 rounded-xl border-2 px-4 py-3 ${choice === value ? 'border-action' : 'border-border'}`}>
                                <input type="radio" name="decision" className="mt-1 h-5 w-5" checked={choice === value} onChange={() => { setChoice(value); setConfirm(false); }} />
                                <span>
                                    <span className="block font-semibold">{label}</span>
                                    <span className="text-text-secondary">{hint}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                    <label className="flex flex-col gap-1 text-lg font-semibold">
                        {d === null ? 'Your words on it (optional)' : 'Why the decision changes'}
                        <input value={note} maxLength={200} className={field} onChange={(e) => { setNote(e.target.value); }} />
                    </label>
                    <div className="flex flex-wrap gap-3">
                        {!confirm ? (
                            <button type="button" disabled={choice === null} className={primary} onClick={() => { setConfirm(true); }}>
                                Record the decision
                            </button>
                        ) : (
                            <button type="button" disabled={busy} className={primary} onClick={() => void record()}>
                                {busy ? 'Recording…' : `Yes, record "${choice === 'continue' ? 'Continue' : 'Stop'}"`}
                            </button>
                        )}
                        {d !== null && (
                            <button type="button" className={button} onClick={() => { setChanging(false); setConfirm(false); }}>
                                Cancel
                            </button>
                        )}
                    </div>
                </>
            )}
            {card.can_decide && d !== null && !changing && (
                <button type="button" className={`${button} self-start`} onClick={() => { setChanging(true); }}>
                    Change the decision
                </button>
            )}
        </section>
    );
}
