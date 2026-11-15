import { clock, longDay, naira, plural, shortDate } from './format';
import { verdicts, Mark, seconds, Measure, button } from './pilot-parts';
import type { Card } from './pilot-types';

export function Scorecard({ card }: { card: Card }) {
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

export function DaysTable({ card, onOpen }: { card: Card; onOpen: (day: string) => void }) {
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
