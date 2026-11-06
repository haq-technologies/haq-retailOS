/**
 * The number pad the counter types prices and cash on (wireframes: PricePad, PayCash). Keys are at least
 * 56px tall, the touch minimum (spec section 15).
 */
interface Props {
    onKey: (key: string) => void;
    withDoubleZero?: boolean;
    disabled?: boolean;
}

export function Keypad({ onKey, withDoubleZero = true, disabled = false }: Props) {
    const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', withDoubleZero ? '00' : 'clear', '0', withDoubleZero ? 'clear' : 'delete'];
    return (
        <div className="grid grid-cols-3 gap-2">
            {keys.map((key) => (
                <button
                    key={key}
                    type="button"
                    disabled={disabled}
                    className="min-h-touch rounded-xl border-2 border-border bg-background text-2xl font-semibold disabled:opacity-60"
                    onClick={() => {
                        onKey(key);
                    }}
                >
                    {key === 'clear' ? 'Clear' : key === 'delete' ? 'Delete' : key}
                </button>
            ))}
        </div>
    );
}

/** Maps a physical key to a pad key: digits, Backspace and Delete. */
export function padKeyFrom(key: string): string | null {
    if (/^[0-9]$/.test(key)) return key;
    if (key === 'Backspace') return 'delete';
    if (key === 'Delete') return 'clear';
    return null;
}
