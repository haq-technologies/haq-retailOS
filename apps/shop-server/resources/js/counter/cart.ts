import type { CustomerType } from './types';

/**
 * The sale in progress: a pure state, so every tap runs on local state and the totals preview never
 * waits for the server (spec section 4) [F25]. The server recalculates every total at Finish.
 */
export type AddedBy = 'scan' | 'learn' | 'search' | 'tile' | 'new';
export type PriceSource = 'set_price' | 'last_price' | 'median' | 'typed';

export interface CartLine {
    clientLineId: string;
    itemId: string;
    name: string;
    categoryId: string;
    qty: number;
    unitPriceKobo: number;
    colour: string | null;
    asksColour: boolean;
    addedBy: AddedBy;
    priceSource: PriceSource;
    barcode: string | null;
    searchTerm: string | null;
    confirmedOutsideRange: boolean;
    retyped: boolean;
    approvalToken: string | null;
}

export interface Cart {
    customerType: CustomerType;
    startedAt: string | null;
    parkedSaleId: string | null;
    lines: CartLine[];
    finishKey: { key: string; hash: string } | null;
    /**
     * A Finish the server never answered (D61): the exact request, kept until the server says whether that
     * sale exists. Until then the sale and its payment cannot change, so a sale the server did commit is
     * never sold again under a new key. It lives on the cart, so it survives the idle lock.
     */
    pending?: FinishPayload | null;
}

export type Payment =
    | { method: 'cash'; amountKobo: number; tenderedKobo: number }
    | { method: 'transfer'; amountKobo: number; reference: string; alertSeen: boolean }
    | { method: 'pos'; amountKobo: number };

export function emptyCart(customerType: CustomerType = 'retail'): Cart {
    return { customerType, startedAt: null, parkedSaleId: null, lines: [], finishKey: null };
}

export function totalKobo(cart: Cart): number {
    return cart.lines.reduce((sum, line) => sum + line.qty * line.unitPriceKobo, 0);
}

export function itemCount(cart: Cart): number {
    return cart.lines.reduce((sum, line) => sum + line.qty, 0);
}

/** "2 lines · 3 items", as the wireframe writes it. */
export function countLabel(cart: Cart): string {
    const lines = cart.lines.length;
    const items = itemCount(cart);
    return `${String(lines)} ${lines === 1 ? 'line' : 'lines'} · ${String(items)} ${items === 1 ? 'item' : 'items'}`;
}

export type NewLine = Omit<CartLine, 'clientLineId' | 'qty' | 'confirmedOutsideRange' | 'retyped' | 'approvalToken'> &
    Partial<Pick<CartLine, 'qty' | 'confirmedOutsideRange' | 'retyped' | 'approvalToken'>>;

/**
 * Adds a line. The same item at the same price, with no colour note and no approval, adds to the last
 * such line instead, as a second scan of the same item does.
 */
export function addLine(cart: Cart, line: NewLine, newId: () => string, now: () => string): { cart: Cart; clientLineId: string } {
    const startedAt = cart.startedAt ?? now();
    const last = cart.lines.at(-1);
    if (
        last !== undefined &&
        last.itemId === line.itemId &&
        last.unitPriceKobo === line.unitPriceKobo &&
        last.colour === null &&
        !line.asksColour &&
        last.approvalToken === null &&
        line.approvalToken == null
    ) {
        const lines = cart.lines.map((l) => (l === last ? { ...l, qty: l.qty + (line.qty ?? 1) } : l));
        return { cart: { ...cart, startedAt, lines, finishKey: null }, clientLineId: last.clientLineId };
    }
    const clientLineId = newId();
    const full: CartLine = {
        ...line,
        clientLineId,
        qty: line.qty ?? 1,
        confirmedOutsideRange: line.confirmedOutsideRange ?? false,
        retyped: line.retyped ?? false,
        approvalToken: line.approvalToken ?? null,
    };
    return { cart: { ...cart, startedAt, lines: [...cart.lines, full], finishKey: null }, clientLineId };
}

export function setQty(cart: Cart, clientLineId: string, qty: number): Cart {
    if (qty <= 0) {
        return removeLine(cart, clientLineId).cart;
    }
    return { ...cart, finishKey: null, lines: cart.lines.map((l) => (l.clientLineId === clientLineId ? { ...l, qty: Math.min(qty, 9999) } : l)) };
}

/** Removes a line and returns it with its place, so the counter can offer Undo (spec section 15). */
export function removeLine(cart: Cart, clientLineId: string): { cart: Cart; removed: { line: CartLine; index: number } | null } {
    const index = cart.lines.findIndex((l) => l.clientLineId === clientLineId);
    if (index < 0) {
        return { cart, removed: null };
    }
    const line = cart.lines[index];
    const lines = cart.lines.filter((l) => l.clientLineId !== clientLineId);
    return { cart: { ...cart, lines, finishKey: null, startedAt: lines.length === 0 ? null : cart.startedAt }, removed: line === undefined ? null : { line, index } };
}

export function restoreLine(cart: Cart, removed: { line: CartLine; index: number }): Cart {
    const lines = [...cart.lines];
    lines.splice(Math.min(removed.index, lines.length), 0, removed.line);
    return { ...cart, lines, finishKey: null, startedAt: cart.startedAt ?? new Date().toISOString() };
}

export function setColour(cart: Cart, clientLineId: string, colour: string | null): Cart {
    const value = colour === null || colour.trim() === '' ? null : colour.trim().slice(0, 40);
    return { ...cart, finishKey: null, lines: cart.lines.map((l) => (l.clientLineId === clientLineId ? { ...l, colour: value } : l)) };
}

/** A new price voids the line's checks and its approval (spec section 3) [S3]. */
export function setPrice(
    cart: Cart,
    clientLineId: string,
    unitPriceKobo: number,
    source: PriceSource,
    checks: { confirmedOutsideRange?: boolean; retyped?: boolean; approvalToken?: string | null } = {},
): Cart {
    return {
        ...cart,
        finishKey: null,
        lines: cart.lines.map((l) =>
            l.clientLineId === clientLineId
                ? {
                      ...l,
                      unitPriceKobo,
                      priceSource: source,
                      confirmedOutsideRange: checks.confirmedOutsideRange ?? false,
                      retyped: checks.retyped ?? false,
                      approvalToken: checks.approvalToken ?? null,
                  }
                : l,
        ),
    };
}

export function markChecked(cart: Cart, clientLineId: string, checks: { confirmedOutsideRange?: boolean; retyped?: boolean; approvalToken?: string }): Cart {
    return {
        ...cart,
        finishKey: null,
        lines: cart.lines.map((l) => (l.clientLineId === clientLineId ? { ...l, ...checks } : l)),
    };
}

/**
 * Retail or Wholesale covers the whole sale (D8). Flipping it re-fills each line the cashier did not type
 * with that type's price; a typed price stays.
 */
export function setCustomerType(
    cart: Cart,
    type: CustomerType,
    priceFor: (itemId: string, type: CustomerType) => { price: number; source: PriceSource } | null,
): Cart {
    const lines = cart.lines.map((l) => {
        if (l.priceSource === 'typed') {
            return l;
        }
        const next = priceFor(l.itemId, type);
        return next === null
            ? l
            : { ...l, unitPriceKobo: next.price, priceSource: next.source, confirmedOutsideRange: false, retyped: false, approvalToken: null };
    });
    return { ...cart, customerType: type, lines, finishKey: null };
}

export interface FinishPayload {
    idempotency_key: string;
    customer_type: CustomerType;
    started_at: string | null;
    parked_sale_id: string | null;
    lines: {
        client_line_id: string;
        item_id: string;
        qty: number;
        unit_price_kobo: number;
        colour: string | null;
        added_by: AddedBy;
        price_source: PriceSource;
        approval_token: string | null;
        confirmed_outside_range: boolean;
        retyped: boolean;
        barcode: string | null;
        search_term: string | null;
    }[];
    payments: { method: string; amount_kobo: number; tendered_kobo?: number; reference?: string | null; alert_seen?: boolean }[];
}

/**
 * The Finish request. The idempotency key stays the same while the cart and payments stay the same, so a
 * retry after a lost answer finishes the sale once (spec section 7) [F5]; any change gets a new key.
 */
export function finishPayload(cart: Cart, payments: Payment[], newKey: () => string): { payload: FinishPayload; cart: Cart } {
    const body: Omit<FinishPayload, 'idempotency_key'> = {
        customer_type: cart.customerType,
        started_at: cart.startedAt,
        parked_sale_id: cart.parkedSaleId,
        lines: cart.lines.map((l) => ({
            client_line_id: l.clientLineId,
            item_id: l.itemId,
            qty: l.qty,
            unit_price_kobo: l.unitPriceKobo,
            colour: l.colour,
            added_by: l.addedBy,
            price_source: l.priceSource,
            approval_token: l.approvalToken,
            confirmed_outside_range: l.confirmedOutsideRange,
            retyped: l.retyped,
            barcode: l.barcode,
            search_term: l.searchTerm,
        })),
        payments: payments.map((p) =>
            p.method === 'cash'
                ? { method: 'cash', amount_kobo: p.amountKobo, tendered_kobo: p.tenderedKobo }
                : p.method === 'transfer'
                  ? { method: 'transfer', amount_kobo: p.amountKobo, reference: p.reference.trim() === '' ? null : p.reference.trim(), alert_seen: p.alertSeen }
                  : { method: 'pos', amount_kobo: p.amountKobo },
        ),
    };
    const hash = JSON.stringify(body);
    const key = cart.finishKey !== null && cart.finishKey.hash === hash ? cart.finishKey.key : newKey();
    return { payload: { idempotency_key: key, ...body }, cart: { ...cart, finishKey: { key, hash } } };
}

/** What is still to pay, and the change, for a set of payments. */
export function paymentState(total: number, payments: Payment[]): { paid: number; left: number; change: number } {
    const paid = payments.reduce((s, p) => s + p.amountKobo, 0);
    const change = payments.reduce((s, p) => s + (p.method === 'cash' ? Math.max(0, p.tenderedKobo - p.amountKobo) : 0), 0);
    return { paid, left: Math.max(0, total - paid), change };
}
