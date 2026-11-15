import { useCallback, useEffect, useState } from 'react';
import { Notice } from '@/components/notice';
import type { CounterApi } from '@/counter/use-api';
import { DataExport } from './data-export';
import { longDay, plural } from './format';
import { DaySheet } from './pilot-day';
import { DecisionCard } from './pilot-decision';
import { DaysTable, Scorecard } from './pilot-scorecard';
import { StartCard } from './pilot-start';
import type { Card } from './pilot-types';

/**
 * The measured pilot (Phase 5; spec section 14, Pilot success thresholds) [D56]. For 14 trading days every
 * counter runs beside the notebook. Each evening a manager or the owner types the notebook's totals and
 * traces each difference to the notebook or to the app; the five measures are read live; at gate G5 the
 * owner records continue or stop. Totals and percentages only, never cost.
 */
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
