/**
 * What a cashier does beside a sale, in a row under the tiles: a new item, the held sales, the drawer, their
 * own history, returns, cash out or in, and closing the day. Each opens its sheet.
 */
export type CounterAction = 'new-item' | 'waiting' | 'no-sale' | 'history' | 'returns' | 'cash' | 'close';

interface Props {
    heldCount: number;
    onOpen: (action: CounterAction) => void;
}

export function CounterActions({ heldCount, onOpen }: Props) {
    const actions: [CounterAction, string][] = [
        ['new-item', 'New item'],
        ['waiting', `Held sales (${String(heldCount)})`],
        ['no-sale', 'No sale'],
        ['history', 'My history'],
        ['returns', 'Returns and voids'],
        ['cash', 'Cash out or in'],
        ['close', 'Close the day'],
    ];
    return (
        <div className="mt-auto flex flex-wrap gap-3">
            {actions.map(([action, label]) => (
                <button
                    key={action}
                    type="button"
                    className="min-h-touch rounded-xl border-2 border-border px-4 text-lg"
                    onClick={() => {
                        onOpen(action);
                    }}
                >
                    {label}
                </button>
            ))}
        </div>
    );
}
