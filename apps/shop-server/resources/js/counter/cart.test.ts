import { describe, expect, it } from 'vitest';
import { addLine, countLabel, emptyCart, finishPayload, finishSettles, paymentState, removeLine, restoreLine, setCustomerType, setPrice, setQty, totalKobo, type NewLine } from './cart';

let n = 0;
const id = () => `line-${String(++n)}`;
const now = () => '2026-11-06T10:00:00.000Z';
const line = (over: Partial<NewLine> = {}): NewLine => ({
    itemId: 'item-a',
    name: 'Oraimo FreePods 3C Earbuds',
    categoryId: 'cat',
    unitPriceKobo: 1_400_000,
    colour: null,
    asksColour: false,
    addedBy: 'tile',
    priceSource: 'set_price',
    barcode: null,
    searchTerm: null,
    ...over,
});

describe('the cart', () => {
    it('adds lines, merges a second scan of the same item, and totals in kobo', () => {
        let cart = emptyCart();
        cart = addLine(cart, line(), id, now).cart;
        cart = addLine(cart, line(), id, now).cart;
        cart = addLine(cart, line({ itemId: 'item-b', name: 'Cable', unitPriceKobo: 300_000 }), id, now).cart;
        expect(cart.lines).toHaveLength(2);
        expect(cart.lines[0]?.qty).toBe(2);
        expect(totalKobo(cart)).toBe(3_100_000);
        expect(countLabel(cart)).toBe('2 lines · 3 items');
        expect(cart.startedAt).toBe(now());
    });

    it('never merges a line that asks for colour', () => {
        let cart = emptyCart();
        cart = addLine(cart, line({ asksColour: true }), id, now).cart;
        cart = addLine(cart, line({ asksColour: true }), id, now).cart;
        expect(cart.lines).toHaveLength(2);
    });

    it('removes a line with undo, and a zero quantity removes it', () => {
        let cart = addLine(emptyCart(), line(), id, now).cart;
        const first = cart.lines[0]?.clientLineId ?? '';
        const { cart: without, removed } = removeLine(cart, first);
        expect(without.lines).toHaveLength(0);
        expect(without.startedAt).toBeNull();
        expect(removed).not.toBeNull();
        if (removed !== null) cart = restoreLine(without, removed);
        expect(cart.lines).toHaveLength(1);
        expect(setQty(cart, first, 0).lines).toHaveLength(0);
    });

    it('re-fills untyped prices when Retail or Wholesale flips, and keeps typed ones', () => {
        let cart = addLine(emptyCart(), line(), id, now).cart;
        cart = addLine(cart, line({ itemId: 'item-b', priceSource: 'typed', unitPriceKobo: 500_000 }), id, now).cart;
        cart = setCustomerType(cart, 'wholesale', (item) => (item === 'item-a' ? { price: 1_200_000, source: 'set_price' } : { price: 1, source: 'set_price' }));
        expect(cart.customerType).toBe('wholesale');
        expect(cart.lines.map((l) => l.unitPriceKobo)).toEqual([1_200_000, 500_000]);
    });

    it('voids a line\'s checks and approval when its price changes', () => {
        let cart = addLine(emptyCart(), line({ approvalToken: 'tok', confirmedOutsideRange: true }), id, now).cart;
        const lineId = cart.lines[0]?.clientLineId ?? '';
        cart = setPrice(cart, lineId, 900_000, 'typed');
        expect(cart.lines[0]).toMatchObject({ approvalToken: null, confirmedOutsideRange: false, priceSource: 'typed' });
    });

    it('keeps the idempotency key while nothing changes, and makes a new one when the cart changes', () => {
        let cart = addLine(emptyCart(), line(), id, now).cart;
        const payments = [{ method: 'cash' as const, amountKobo: 1_400_000, tenderedKobo: 1_500_000 }];
        let keys = 0;
        const key = () => `key-${String(++keys)}`;
        const first = finishPayload(cart, payments, key);
        const again = finishPayload(first.cart, payments, key);
        expect(again.payload.idempotency_key).toBe(first.payload.idempotency_key);
        cart = setQty(again.cart, cart.lines[0]?.clientLineId ?? '', 2);
        expect(finishPayload(cart, payments, key).payload.idempotency_key).not.toBe(first.payload.idempotency_key);
        expect(first.payload.payments[0]).toEqual({ method: 'cash', amount_kobo: 1_400_000, tendered_kobo: 1_500_000 });
    });

    it('treats only Finish\'s own answers as saying what became of a sale in doubt (D61)', () => {
        // The sale, or Finish's refusals, which come after the server has looked for the key.
        for (const status of [200, 409, 422]) expect(finishSettles(status)).toBe(true);
        // A sign-in that ran out, an expired page, a rate limit or a server error says nothing about the sale.
        for (const status of [401, 403, 404, 419, 429, 500, 503]) expect(finishSettles(status)).toBe(false);
    });

    it('works out what is left to pay and the change', () => {
        expect(paymentState(2_250_000, [{ method: 'cash', amountKobo: 1_000_000, tenderedKobo: 1_000_000 }, { method: 'transfer', amountKobo: 1_250_000, reference: '', alertSeen: true }])).toEqual({ paid: 2_250_000, left: 0, change: 0 });
        expect(paymentState(2_250_000, [{ method: 'cash', amountKobo: 2_250_000, tenderedKobo: 2_500_000 }]).change).toBe(250_000);
    });
});
