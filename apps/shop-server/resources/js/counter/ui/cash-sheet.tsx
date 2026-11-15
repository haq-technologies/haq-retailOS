import { useCallback, useState } from 'react';
import { Keypad } from '@/components/keypad';
import { Notice } from '@/components/notice';
import { Sheet } from '@/components/sheet';
import type { Tile } from '@/components/sign-in-panel';
import { digitsToKobo, naira, typeDigits } from '../money';
import type { CounterApi } from '../use-api';
import { ManagerPad, choice, useApproved, useKeypadKeys } from './drawer-shared';
import { newId } from './types';

const cashTypes = [
    ['paid_out', 'Paid-out', 'A small expense from the till'],
    ['drop', 'Drop to the safe', 'Cash taken out to the safe'],
    ['top_up', 'Top-up', 'Change put into the drawer'],
] as const;

/** Cash out of or into the drawer beside a sale: a paid-out, a drop or a top-up, each with a reason (D68). */
export function CashSheet({ api, people, onClose, onDone }: { api: CounterApi; people: Tile[]; onClose: () => void; onDone: (message: string) => void }) {
    const [type, setType] = useState<string>('paid_out');
    const [digits, setDigits] = useState('');
    const [reason, setReason] = useState('');
    const [requestId] = useState(newId);
    const { send, approval, error, busy } = useApproved<{ drawer: string; amount_kobo: number }>(api);
    const amount = digitsToKobo(digits);
    const label = cashTypes.find(([value]) => value === type)?.[1] ?? '';
    const typeKey = useCallback((key: string) => {
        setDigits((d) => typeDigits(d, key));
    }, []);
    useKeypadKeys(typeKey);

    return (
        <Sheet title="Cash out or in" onClose={onClose}>
            <div className="grid grid-cols-3 gap-2">
                {cashTypes.map(([value, name]) => (
                    <button key={value} type="button" aria-pressed={type === value} className={choice(type === value)} onClick={() => { setType(value); }}>
                        {name}
                    </button>
                ))}
            </div>
            <p className="text-text-secondary">{cashTypes.find(([value]) => value === type)?.[2]}</p>
            <output aria-live="polite" className="flex min-h-touch items-center rounded-xl border-2 border-action px-4 text-4xl font-bold">
                {digits === '' ? <span className="text-text-secondary">₦</span> : naira(amount)}
            </output>
            <input
                type="text"
                aria-label="What it is for"
                placeholder="What it is for"
                value={reason}
                maxLength={200}
                className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                onChange={(e) => {
                    setReason(e.target.value);
                }}
            />
            {approval !== null && <ManagerPad people={people} api={api} asked={approval} />}
            {error !== null && <Notice tone="error">{error}</Notice>}
            <Keypad
                onKey={(key) => {
                    setDigits((d) => typeDigits(d, key));
                }}
            />
            <button
                type="button"
                disabled={amount <= 0 || reason.trim().length < 3 || busy}
                className="min-h-touch rounded-xl bg-action text-lg font-bold text-white disabled:opacity-60"
                onClick={() => {
                    void send('/api/counter/drawer/cash', { type, amount_kobo: amount, reason: reason.trim(), request_id: requestId }, (a) => {
                        const drawer = a.drawer === 'opened' ? ' The drawer opened.' : a.drawer === 'not_opened' ? ' The drawer did not open: use the key.' : '';
                        onDone(`${label} of ${naira(a.amount_kobo)} recorded.${drawer}`);
                    });
                }}
            >
                {busy ? 'Recording…' : `Record the ${label.toLowerCase()}`}
            </button>
        </Sheet>
    );
}
