import { useCallback, useEffect, useState } from 'react';
import type { CounterApi } from '../use-api';
import type { ParkedEntry } from './types';

const sameJson = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

/**
 * The counter's background checks (spec sections 7 and 9): the sales waiting on every counter, the receipts
 * that did not print, and the printer. Each keeps its last answer when the new one is the same, so a poll
 * that found nothing new redraws nothing (D76).
 */
export function useCounterPolling(api: CounterApi) {
    const [printer, setPrinter] = useState('ready');
    const [pending, setPending] = useState<{ id: string; receipt_number: string }[]>([]);
    const [parked, setParked] = useState<ParkedEntry[]>([]);

    // An answer the same as the last one keeps the old list, so a poll that found nothing new redraws nothing.
    const refreshParked = useCallback(() => {
        void api
            .get<{ parked: ParkedEntry[] }>('/api/counter/parked')
            .then((a) => {
                if (a.ok) setParked((old) => (sameJson(old, a.body.parked) ? old : a.body.parked));
            })
            .catch(() => undefined);
    }, [api]);

    // Background checks, on one 3-second timer: waiting sales on every tick (3 s), receipts that did not
    // print on every second tick (6 s), the printer on every fifth (15 s).
    useEffect(() => {
        let tick = 0;
        const run = () => {
            refreshParked();
            if (tick % 2 === 0) {
                void api
                    .get<{ print_jobs: { id: string; receipt_number: string; kind: string }[] }>('/api/counter/print-jobs')
                    .then((a) => {
                        if (a.ok) setPending((old) => (sameJson(old, a.body.print_jobs) ? old : a.body.print_jobs));
                    })
                    .catch(() => undefined);
            }
            if (tick % 5 === 0) {
                void api
                    .get<{ status: string }>('/api/counter/printer')
                    .then((a) => {
                        if (a.ok) setPrinter(a.body.status);
                    })
                    .catch(() => {
                        setPrinter('unknown');
                    });
            }
            tick += 1;
        };
        run();
        const timer = window.setInterval(run, 3000);
        return () => {
            window.clearInterval(timer);
        };
    }, [api, refreshParked]);

    return { printer, pending, setPending, parked, refreshParked };
}
