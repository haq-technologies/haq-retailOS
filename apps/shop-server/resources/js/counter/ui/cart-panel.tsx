import { useState } from 'react';
import type { Cart } from '../cart';
import { countLabel, totalKobo } from '../cart';
import { naira } from '../money';

/**
 * The sale (wireframes CounterLarge and ColourRow): each line with − and + for the quantity, its colour note,
 * the total, Hold and the green Pay button with the amount. A new line in a category that asks for colour
 * shows the colour row, which is always optional (D9).
 */
interface Props {
    cart: Cart;
    colours: string[];
    colourFor: string | null;
    disabled: boolean;
    onQty: (lineId: string, qty: number) => void;
    onColour: (lineId: string, colour: string | null) => void;
    onEditPrice: (lineId: string) => void;
    onHold: () => void;
    onPay: () => void;
    undo: { name: string } | null;
    onUndo: () => void;
}

export function CartPanel({ cart, colours, colourFor, disabled, onQty, onColour, onEditPrice, onHold, onPay, undo, onUndo }: Props) {
    const total = totalKobo(cart);
    return (
        <aside aria-label="Sale" className="flex h-full min-h-0 flex-col bg-panel">
            <header className="flex items-baseline justify-between px-4 pt-4">
                <h2 className="text-2xl font-semibold">Sale</h2>
                <span className="text-text-secondary">{cart.lines.length === 0 ? 'Empty' : countLabel(cart)}</span>
            </header>
            <ol className="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto p-4">
                {cart.lines.length === 0 && <li className="text-lg text-text-secondary">Scan or tap an item to start a sale.</li>}
                {cart.lines.map((line) => (
                    <li key={line.clientLineId} className="flex flex-col gap-2 rounded-xl bg-background p-3">
                        <div className="flex items-start justify-between gap-3">
                            <span className="text-lg font-semibold">{line.name}</span>
                            <button
                                type="button"
                                aria-label={`Change the price of ${line.name}`}
                                className="min-h-touch shrink-0 rounded-xl px-2 text-right text-lg font-semibold"
                                onClick={() => {
                                    onEditPrice(line.clientLineId);
                                }}
                            >
                                {naira(line.qty * line.unitPriceKobo)}
                                {line.qty > 1 && <span className="block text-sm font-normal text-text-secondary">{naira(line.unitPriceKobo)} each</span>}
                            </button>
                        </div>
                        {line.colour !== null && <span className="text-text-secondary">Colour: {line.colour}</span>}
                        {colourFor === line.clientLineId && line.colour === null && (
                            <ColourRow
                                colours={colours}
                                onPick={(c) => {
                                    onColour(line.clientLineId, c);
                                }}
                            />
                        )}
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                aria-label={`One fewer ${line.name}`}
                                className="h-14 w-14 rounded-xl border-2 border-border text-2xl font-bold"
                                onClick={() => {
                                    onQty(line.clientLineId, line.qty - 1);
                                }}
                            >
                                −
                            </button>
                            <span className="w-10 text-center text-xl font-semibold" aria-label="Quantity">
                                {line.qty}
                            </span>
                            <button
                                type="button"
                                aria-label={`One more ${line.name}`}
                                className="h-14 w-14 rounded-xl border-2 border-border text-2xl font-bold"
                                onClick={() => {
                                    onQty(line.clientLineId, line.qty + 1);
                                }}
                            >
                                +
                            </button>
                        </div>
                    </li>
                ))}
            </ol>
            {undo !== null && (
                <div className="mx-4 mb-2 flex items-center justify-between gap-3 rounded-xl border-2 border-border bg-background p-3">
                    <span>Removed {undo.name}</span>
                    <button type="button" className="min-h-touch rounded-xl border-2 border-action px-4 font-semibold text-action-text" onClick={onUndo}>
                        Undo
                    </button>
                </div>
            )}
            <footer className="flex flex-col gap-3 border-t border-border p-4">
                <p className="flex items-baseline justify-between">
                    <span className="text-xl">Total</span>
                    <strong className="text-[40px] leading-none">{naira(total)}</strong>
                </p>
                <div className="flex gap-3">
                    <button
                        type="button"
                        disabled={disabled || cart.lines.length === 0}
                        className="min-h-[72px] w-[120px] rounded-xl border-2 border-border bg-background text-xl font-semibold disabled:opacity-60"
                        onClick={onHold}
                    >
                        Hold
                    </button>
                    <button
                        type="button"
                        disabled={disabled || cart.lines.length === 0}
                        className="min-h-[72px] flex-1 rounded-xl bg-pay text-2xl font-bold text-white disabled:opacity-60"
                        onClick={onPay}
                    >
                        Pay {cart.lines.length === 0 ? '' : naira(total)}
                    </button>
                </div>
                <p className="text-center text-sm text-text-secondary">Pay key F8 · Hold F4 · New item F2</p>
            </footer>
        </aside>
    );
}

function ColourRow({ colours, onPick }: { colours: string[]; onPick: (c: string) => void }) {
    const [all, setAll] = useState(false);
    const shown = all ? colours : colours.slice(0, 3);
    return (
        <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Colour, optional">
            <span className="font-semibold">
                Colour <span className="font-normal text-text-secondary">· optional</span>
            </span>
            {shown.map((c) => (
                <button
                    key={c}
                    type="button"
                    className="min-h-touch rounded-xl border-2 border-border px-3"
                    onClick={() => {
                        onPick(c);
                    }}
                >
                    {c}
                </button>
            ))}
            {!all && colours.length > 3 && (
                <button
                    type="button"
                    className="min-h-touch rounded-xl border-2 border-border px-3"
                    onClick={() => {
                        setAll(true);
                    }}
                >
                    More
                </button>
            )}
        </div>
    );
}
