import { useCallback, useEffect, useState } from 'react';
import { Keypad, padKeyFrom } from '@/components/keypad';
import type { Tile } from '@/components/sign-in-panel';
import { WarningIcon } from '@/components/icons';
import { digitsToKobo, koboToDigits, naira, typeDigits } from '../money';
import { checkPrice, usualLabel } from '../price-rules';
import type { CounterSettings, CustomerType, PriceRange } from '../types';
import type { CounterApi } from '../use-api';
import { ApprovalPad } from './approval-pad';

/**
 * The price pad (spec section 7) [D7, S4, S5]: pre-filled from the set price or the last price (or the
 * median), one tap adds it, and the cashier can type another. A price outside the usual range is
 * confirmed, one far above it is typed twice, and one below the floor needs a manager. Wireframes:
 * PricePad, PriceUnusual, PriceRetype, PriceManager.
 */
export interface PriceChoice {
    priceKobo: number;
    typed: boolean;
    confirmedOutsideRange: boolean;
    retyped: boolean;
    approvalToken: string | null;
}

interface Props {
    name: string;
    crumbs: string[];
    customerType: CustomerType;
    prefill: number | null;
    prefillSource: 'set_price' | 'last_price' | 'median' | null;
    range: PriceRange | null;
    settings: CounterSettings;
    people: Tile[];
    api: CounterApi;
    lineId: string;
    itemId: string;
    onAdd: (choice: PriceChoice) => void;
    onCancel: () => void;
}

type Stage = 'enter' | 'unusual' | 'retype' | 'manager';

export function PricePad({ name, crumbs, customerType, prefill, prefillSource, range, settings, people, api, lineId, itemId, onAdd, onCancel }: Props) {
    const [digits, setDigits] = useState(koboToDigits(prefill));
    const [typed, setTyped] = useState(false);
    const [stage, setStage] = useState<Stage>('enter');
    const [again, setAgain] = useState('');
    const price = digitsToKobo(digits);
    const verdict = checkPrice(price, range, settings.floorPercent, settings.retypeMultiple);
    const label = customerType === 'retail' ? 'Retail price' : 'Wholesale price';

    const add = useCallback(() => {
        if (price <= 0) return;
        const isTyped = typed || prefillSource === null;
        if (stage === 'enter') {
            if (verdict.kind === 'confirm') {
                setStage('unusual');
                return;
            }
            if (verdict.kind === 'retype') {
                setAgain('');
                setStage('retype');
                return;
            }
            if (verdict.kind === 'approval') {
                setStage('manager');
                return;
            }
            onAdd({ priceKobo: price, typed: isTyped, confirmedOutsideRange: false, retyped: false, approvalToken: null });
        } else if (stage === 'unusual') {
            onAdd({ priceKobo: price, typed: isTyped, confirmedOutsideRange: true, retyped: false, approvalToken: null });
        } else if (stage === 'retype' && digitsToKobo(again) === price) {
            onAdd({ priceKobo: price, typed: isTyped, confirmedOutsideRange: true, retyped: true, approvalToken: null });
        }
    }, [price, typed, prefillSource, stage, verdict.kind, again, onAdd]);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                if (stage === 'enter') onCancel();
                else setStage('enter');
                return;
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                add();
                return;
            }
            if (stage === 'manager') return;
            const key = padKeyFrom(event.key);
            if (key !== null) {
                event.preventDefault();
                if (stage === 'retype') setAgain((d) => typeDigits(d, key));
                else {
                    setDigits((d) => typeDigits(typed ? d : '', key));
                    setTyped(true);
                    setStage('enter');
                }
            }
        };
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('keydown', onKey);
        };
    }, [stage, typed, add, onCancel]);

    const hint =
        prefillSource === 'set_price'
            ? 'This is the set price; type to replace it.'
            : prefillSource === 'median'
              ? 'Filled in from the middle of the last 10 sales; type to replace it. This item has no set price yet.'
              : prefillSource === 'last_price'
                ? 'Filled in from the last sale; type to replace it. This item has no set price yet.'
                : 'No sale of this item yet. Type the price.';

    return (
        <div className="fixed inset-0 z-40 flex justify-center overflow-y-auto bg-text/40 medium:items-center medium:p-6" role="presentation">
            <div role="dialog" aria-modal="true" aria-label="Price" className="flex w-full flex-col gap-4 bg-background p-4 medium:max-w-xl medium:rounded-2xl medium:p-6">
                <nav aria-label="Where this item is" className="flex flex-wrap items-center gap-2 text-text-secondary">
                    <button type="button" className="min-h-touch rounded-xl border-2 border-border px-4 text-lg text-text" onClick={onCancel}>
                        Back
                    </button>
                    {crumbs.map((c, i) => (
                        <span key={`${c}-${String(i)}`}>
                            {i > 0 && '› '}
                            {c}
                        </span>
                    ))}
                </nav>
                <p className="text-text-secondary">
                    Prints as <strong className="block text-xl text-text">{name}</strong>
                </p>

                {stage !== 'retype' && (
                    <div className="flex flex-col gap-1">
                        <span className="text-lg font-semibold">{label}</span>
                        <output aria-live="polite" className="flex min-h-touch items-center rounded-xl border-2 border-action px-4 text-4xl font-bold">
                            {digits === '' ? <span className="text-text-secondary">₦</span> : naira(price)}
                        </output>
                        {stage === 'enter' && <span className="text-text-secondary">{hint}</span>}
                        {range !== null && stage === 'enter' && <span className="text-lg">{usualLabel(range, naira).replace('Usual', 'Usual price')}</span>}
                    </div>
                )}

                {stage === 'unusual' && range !== null && (
                    <div role="alert" className="flex flex-col gap-2 rounded-xl border-2 border-warning bg-warning p-4 text-text">
                        <p className="flex items-center gap-2 text-xl font-semibold">
                            <WarningIcon /> {usualLabel(range, naira)}. Sell at {naira(price)}?
                        </p>
                        <p>{price < range.low ? 'This is below the usual price for this item.' : 'This is above the usual price for this item.'}</p>
                        {price < range.low && <p>After 3 of these in one shift, a manager must approve.</p>}
                    </div>
                )}

                {stage === 'retype' && range !== null && (
                    <div className="flex flex-col gap-2">
                        <p className="text-lg">You typed</p>
                        <p role="alert" className="flex items-center gap-2 rounded-xl border-2 border-warning bg-warning p-4 text-xl font-semibold text-text">
                            <WarningIcon /> {naira(price)} is far above the usual {naira(range.low)} to {naira(range.high)}.
                        </p>
                        <p>Type the price again to confirm it.</p>
                        <output aria-live="polite" className="flex min-h-touch items-center rounded-xl border-2 border-action px-4 text-4xl font-bold">
                            {again === '' ? <span className="text-text-secondary">Type the price again</span> : naira(digitsToKobo(again))}
                        </output>
                        <span className="text-text-secondary">Confirm works once both prices match.</span>
                    </div>
                )}

                {stage === 'manager' && (
                    <div className="flex flex-col gap-3">
                        <p role="alert" className="flex items-center gap-2 rounded-xl border-2 border-warning bg-warning p-4 text-lg font-semibold text-text">
                            <WarningIcon /> {naira(price)} is below the lowest price a cashier can give.
                        </p>
                        <p>A manager or the owner approves this line at this price only.</p>
                        <ApprovalPad
                            people={people}
                            api={api}
                            action="price_below_floor"
                            subjectId={lineId}
                            itemId={itemId}
                            priceKobo={price}
                            onApproved={(token) => {
                                onAdd({ priceKobo: price, typed: true, confirmedOutsideRange: false, retyped: false, approvalToken: token });
                            }}
                        />
                    </div>
                )}

                {stage !== 'manager' && (
                    <Keypad
                        onKey={(key) => {
                            if (stage === 'retype') {
                                setAgain((d) => typeDigits(d, key));
                                return;
                            }
                            setDigits((d) => typeDigits(typed ? d : '', key));
                            setTyped(true);
                            setStage('enter');
                        }}
                    />
                )}

                <div className="grid grid-cols-2 gap-3">
                    <button
                        type="button"
                        className="min-h-touch rounded-xl border-2 border-border text-lg"
                        onClick={() => {
                            if (stage === 'enter') onCancel();
                            else setStage('enter');
                        }}
                    >
                        {stage === 'enter' ? 'Cancel' : 'Change price'}
                    </button>
                    {stage !== 'manager' && (
                        <button
                            type="button"
                            disabled={price <= 0 || (stage === 'retype' && digitsToKobo(again) !== price)}
                            className="min-h-touch rounded-xl bg-action text-lg font-bold text-white disabled:opacity-60"
                            onClick={add}
                        >
                            {stage === 'unusual' ? `Sell at ${naira(price)}` : stage === 'retype' ? 'Confirm' : `Add at ${naira(price)}`}
                        </button>
                    )}
                </div>
                {stage === 'enter' && <p className="text-center text-text-secondary">Enter adds · Esc cancels</p>}
            </div>
        </div>
    );
}
