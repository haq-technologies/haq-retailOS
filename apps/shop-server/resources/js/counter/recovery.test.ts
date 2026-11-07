import { describe, expect, it } from 'vitest';
import type { JournalEntry } from './db';
import { entriesToSend, highestByEpoch, journalEntry } from './recovery';
import type { SaleSummary } from './ui/types';

function entry(receipt: string): JournalEntry {
    return { receipt_number: receipt, at: '2026-11-07T10:00:00Z', total_kobo: 5000, lines: [{ name: 'Cable', qty: 1, unit_price_kobo: 5000 }], payments: [{ method: 'cash', amount_kobo: 5000 }] };
}

describe('the counter journal after a restore', () => {
    const journal = [entry('T01-E1-000130'), entry('T01-E1-000132'), entry('T01-E1-000131'), entry('T01-E2-001004'), entry('T02-E1-000900')];

    it('reports the highest receipt number in each epoch, for this counter only', () => {
        expect(highestByEpoch(journal, 'T01')).toEqual({ '1': 132, '2': 1004 });
        expect(highestByEpoch(journal, 'T02')).toEqual({ '1': 900 });
        expect(highestByEpoch([], 'T01')).toEqual({});
    });

    it('sends only the sales after the number the server still holds', () => {
        const sent = entriesToSend(journal, 'T01', [{ epoch: 1, after: 130 }]);
        expect(sent.map((e) => e.receipt_number).sort()).toEqual(['T01-E1-000131', 'T01-E1-000132']);
        expect(entriesToSend(journal, 'T01', [{ epoch: 3, after: 0 }])).toEqual([]);
    });

    it('keeps what a re-entry needs from a finished sale, and no cost', () => {
        const sale: SaleSummary = {
            id: '01jbz',
            receipt_number: 'T01-E1-000001',
            business_day: '2026-11-07',
            recorded_at: '2026-11-07T10:00:00Z',
            customer_type: 'retail',
            cashier: 'Amina',
            cashier_id: '01jbzcashier',
            total_kobo: 10000,
            change_kobo: 0,
            lines: [{ client_line_id: 'l1', item_id: '01jbzitem', name: 'Oraimo Cable', colour: 'Black', qty: 2, unit_price_kobo: 5000, line_total_kobo: 10000 }],
            payments: [{ method: 'transfer', amount_kobo: 10000, tendered_kobo: null, change_kobo: null, reference: 'GTB 44' }],
            print_status: 'printed',
            retried: false,
        };
        const kept = journalEntry(sale);
        expect(kept).toMatchObject({ receipt_number: 'T01-E1-000001', business_day: '2026-11-07', cashier_id: '01jbzcashier', customer_type: 'retail' });
        expect(kept.lines[0]).toEqual({ item_id: '01jbzitem', name: 'Oraimo Cable', colour: 'Black', qty: 2, unit_price_kobo: 5000 });
        expect(kept.payments[0]).toEqual({ method: 'transfer', amount_kobo: 10000, tendered_kobo: null, reference: 'GTB 44' });
        expect(JSON.stringify(kept)).not.toContain('cost');
    });
});
