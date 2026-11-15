import { DotIcon, PrinterIcon, WarningIcon } from '@/components/icons';
import type { CustomerType } from '../types';

/**
 * The counter's top bar (wireframes CounterLarge, CounterTablet and CounterPhone): who is selling, retail or
 * wholesale, the transfers still awaited, and whether the server and the printer are there.
 */
interface Props {
    userName: string;
    customerType: CustomerType;
    awaitingCount: number;
    flaggedCount: number;
    online: boolean;
    printer: string;
    onType: (type: CustomerType) => void;
    onLock: () => void;
    onWaiting: () => void;
}

const initials = (name: string) =>
    name
        .split(/\s+/)
        .map((w) => w.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase();

export function CounterHeader({ userName, customerType, awaitingCount, flaggedCount, online, printer, onType, onLock, onWaiting }: Props) {
    const printerLabel = printer === 'ready' ? 'Printer ready' : printer === 'not_set' ? 'No printer set' : printer === 'offline' ? 'Printer off' : 'Printer: check it';

    return (
        <header className="flex min-h-[72px] flex-wrap items-center gap-3 border-b border-border px-4">
            <span className="hidden text-lg font-bold large:inline">HAQ Retail OS</span>
            <button type="button" className="flex min-h-touch items-center gap-2 rounded-full border-2 border-border px-2 pr-4" onClick={onLock} title="Switch person">
                <span className="flex h-10 w-10 items-center justify-center rounded-full bg-action font-bold text-white">{initials(userName)}</span>
                <span className="text-lg font-semibold">{userName}</span>
            </button>
            <div role="radiogroup" aria-label="Customer type" className="flex rounded-xl border-2 border-border p-1">
                {(['retail', 'wholesale'] as const).map((t) => (
                    <button
                        key={t}
                        type="button"
                        role="radio"
                        aria-checked={customerType === t}
                        className={`min-h-12 rounded-lg px-4 text-lg font-semibold ${customerType === t ? 'bg-action text-white' : ''}`}
                        onClick={() => {
                            onType(t);
                        }}
                    >
                        {t === 'retail' ? 'Retail' : 'Wholesale'}
                    </button>
                ))}
            </div>
            {awaitingCount > 0 && (
                <button type="button" className="flex min-h-12 items-center gap-2 rounded-full bg-warning px-4 font-semibold text-text" onClick={onWaiting}>
                    <WarningIcon className="h-5 w-5" /> {awaitingCount} awaiting transfer{flaggedCount > 0 ? `, ${String(flaggedCount)} over 30 min` : ''}
                </button>
            )}
            <span className="ml-auto flex items-center gap-2 text-lg">
                {online ? (
                    <>
                        <DotIcon className="h-3 w-3 text-pay" /> Online
                    </>
                ) : (
                    <span className="flex items-center gap-2 font-semibold text-warning-text">
                        <WarningIcon className="h-5 w-5" /> Reconnecting
                    </span>
                )}
            </span>
            <span className={`flex items-center gap-2 text-lg ${printer === 'ready' ? '' : 'font-semibold text-warning-text'}`}>
                {printer === 'ready' ? <PrinterIcon className="h-5 w-5" /> : <WarningIcon className="h-5 w-5" />} {printerLabel}
            </span>
        </header>
    );
}
