import { postJson, Unreachable } from '@/lib/api';
import { journal, type JournalEntry } from './db';
import type { SaleSummary } from './ui/types';

/**
 * The counter journal after a restore (spec sections 4 and 9) [F1, S14, D44]. The counter reports the
 * highest receipt number it holds in each epoch; if the server lacks any of them, it sends those sales,
 * which a manager re-enters on the lost-window screen. The server raises this counter's epoch past
 * anything the journal shows, so no receipt number can repeat. It runs at start, on reconnecting, and
 * when Finish says the server was restored.
 */

const RECEIPT = /^([A-Z][0-9]{2})-E([0-9]+)-([0-9]+)$/;

/** What the journal keeps of a finished sale: enough to re-enter it, with no cost. */
export function journalEntry(sale: SaleSummary): JournalEntry {
    return {
        receipt_number: sale.receipt_number,
        at: sale.recorded_at,
        business_day: sale.business_day,
        cashier_id: sale.cashier_id,
        cashier: sale.cashier,
        customer_type: sale.customer_type,
        total_kobo: sale.total_kobo,
        lines: sale.lines.map((l) => ({ item_id: l.item_id, name: l.name, colour: l.colour, qty: l.qty, unit_price_kobo: l.unit_price_kobo })),
        payments: sale.payments.map((p) => ({ method: p.method, amount_kobo: p.amount_kobo, tendered_kobo: p.tendered_kobo, reference: p.reference })),
    };
}

/** The highest receipt number in each epoch, for this counter's own receipts only. */
export function highestByEpoch(entries: JournalEntry[], code: string): Record<string, number> {
    const highest: Record<string, number> = {};
    for (const e of entries) {
        const m = RECEIPT.exec(e.receipt_number);
        if (m?.[1] !== code || m[2] === undefined || m[3] === undefined) continue;
        highest[m[2]] = Math.max(highest[m[2]] ?? 0, Number(m[3]));
    }
    return highest;
}

/** The entries the server asked for: each epoch's sales after the number it still holds. */
export function entriesToSend(entries: JournalEntry[], code: string, send: { epoch: number; after: number }[]): JournalEntry[] {
    const after = new Map(send.map((s) => [String(s.epoch), s.after]));
    return entries.filter((e) => {
        const m = RECEIPT.exec(e.receipt_number);
        if (m?.[1] !== code || m[2] === undefined || m[3] === undefined) return false;
        const from = after.get(m[2]);
        return from !== undefined && Number(m[3]) > from;
    });
}

interface Answer {
    ok?: boolean;
    send?: { epoch: number; after: number }[];
    message?: string;
}

let running: Promise<boolean> | null = null;

/** Reports this counter's journal. True once the server has it; false if it could not be reached. */
export function reportJournal(code: string): Promise<boolean> {
    running ??= (async () => {
        try {
            const entries = await journal().catch(() => [] as JournalEntry[]);
            const highest = highestByEpoch(entries, code);
            let answer = await postJson<Answer>('/api/counter/journal', { highest });
            if (answer.ok && answer.body.send !== undefined) {
                answer = await postJson<Answer>('/api/counter/journal', { highest, entries: entriesToSend(entries, code, answer.body.send) }, undefined, 60_000);
            }
            return answer.ok;
        } catch (e) {
            if (e instanceof Unreachable) return false;
            throw e;
        } finally {
            running = null;
        }
    })();
    return running;
}
