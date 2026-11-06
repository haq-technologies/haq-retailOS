import { useState } from 'react';
import { Keypad } from '@/components/keypad';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import { digitsToKobo, naira, typeDigits } from '../money';

/** Hold: one key parks the sale, with an optional label (spec section 9) [F12]. */
export function HoldSheet({ onHold, onClose, error }: { onHold: (label: string) => void; onClose: () => void; error: string | null }) {
    const [label, setLabel] = useState('');
    return (
        <Sheet title="Hold this sale" onClose={onClose}>
            <label className="flex flex-col gap-1 text-lg">
                Label · optional
                <input
                    type="text"
                    autoFocus
                    value={label}
                    maxLength={60}
                    placeholder="Such as: man in blue"
                    className="min-h-touch rounded-xl border-2 border-border px-4"
                    onChange={(e) => {
                        setLabel(e.target.value);
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') onHold(label);
                    }}
                />
            </label>
            {error !== null && <Notice tone="error">{error}</Notice>}
            <p className="text-text-secondary">Every counter in this branch can resume it.</p>
            <button
                type="button"
                className="min-h-touch rounded-xl bg-action text-lg font-bold text-white"
                onClick={() => {
                    onHold(label);
                }}
            >
                Hold
            </button>
        </Sheet>
    );
}

/** No sale opens the drawer with a reason, and the drawer log keeps it [S21]. */
export function NoSaleSheet({ onOpen, onClose, error }: { onOpen: (reason: string) => void; onClose: () => void; error: string | null }) {
    const [reason, setReason] = useState('');
    const choices = ['Change for a customer', 'Count the drawer', 'Put money in'];
    return (
        <Sheet title="No sale: open the drawer" onClose={onClose}>
            <div className="flex flex-wrap gap-2">
                {choices.map((c) => (
                    <button
                        key={c}
                        type="button"
                        aria-pressed={reason === c}
                        className={`min-h-touch rounded-xl border-2 px-4 ${reason === c ? 'border-action font-semibold' : 'border-border'}`}
                        onClick={() => {
                            setReason(c);
                        }}
                    >
                        {c}
                    </button>
                ))}
            </div>
            <input
                type="text"
                aria-label="Reason"
                placeholder="Or type the reason"
                value={choices.includes(reason) ? '' : reason}
                maxLength={200}
                className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                onChange={(e) => {
                    setReason(e.target.value);
                }}
            />
            {error !== null && <Notice tone="error">{error}</Notice>}
            <p className="text-text-secondary">Every opening is logged with your name and the reason.</p>
            <button
                type="button"
                disabled={reason.trim().length < 3}
                className="min-h-touch rounded-xl bg-action text-lg font-bold text-white disabled:opacity-60"
                onClick={() => {
                    onOpen(reason.trim());
                }}
            >
                Open the drawer
            </button>
        </Sheet>
    );
}

/** Opening the shift with the counted float; skipping it never blocks a sale [F20]. */
export function ShiftSheet({ onOpen, onSkip, error }: { onOpen: (floatKobo: number) => void; onSkip: () => void; error: string | null }) {
    const [digits, setDigits] = useState('');
    return (
        <Sheet title="Open the shift" onClose={onSkip}>
            <p className="text-lg">Count the cash in the drawer and type it.</p>
            <output aria-live="polite" className="flex min-h-touch items-center rounded-xl border-2 border-action px-4 text-4xl font-bold">
                {digits === '' ? '₦0' : naira(digitsToKobo(digits))}
            </output>
            <Keypad
                onKey={(key) => {
                    setDigits((d) => typeDigits(d, key));
                }}
            />
            {error !== null && <Notice tone="error">{error}</Notice>}
            <div className="grid grid-cols-2 gap-3">
                <button type="button" className="min-h-touch rounded-xl border-2 border-border text-lg" onClick={onSkip}>
                    Not now
                </button>
                <button
                    type="button"
                    className="min-h-touch rounded-xl bg-action text-lg font-bold text-white"
                    onClick={() => {
                        onOpen(digitsToKobo(digits));
                    }}
                >
                    Open shift with {digits === '' ? '₦0' : naira(digitsToKobo(digits))}
                </button>
            </div>
            <p className="text-text-secondary">If you sell before opening, the counter opens the shift itself and the owner sees it flagged.</p>
        </Sheet>
    );
}

/** A barcode that points to several items: pick one, at most 5, most recently chosen first (spec section 6). */
export function PickerSheet({ code, options, onPick, onClose }: { code: string; options: { id: string; name: string }[]; onPick: (id: string) => void; onClose: () => void }) {
    return (
        <Sheet title="Which item is it?" onClose={onClose}>
            <p className="text-text-secondary">Code {code} belongs to more than one item.</p>
            <div className="flex flex-col gap-2">
                {options.map((o) => (
                    <button
                        key={o.id}
                        type="button"
                        className="min-h-touch rounded-xl border-2 border-border px-4 text-left text-lg font-semibold"
                        onClick={() => {
                            onPick(o.id);
                        }}
                    >
                        {o.name}
                    </button>
                ))}
            </div>
        </Sheet>
    );
}
