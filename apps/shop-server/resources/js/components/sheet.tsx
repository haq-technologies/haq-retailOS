import { useEffect, useRef } from 'react';

/**
 * A modal sheet over the counter. Esc cancels; Enter confirms the focused dialog (spec section 15).
 *
 * Focus moves into the sheet once, when it opens, and never again. The counter redraws every few seconds
 * as it polls, and each redraw hands the sheet a new onClose; a sheet that took focus again on each one
 * pulled it out of the field the cashier was typing in (D76).
 */
interface Props {
    title: string;
    onClose: () => void;
    children: React.ReactNode;
    wide?: boolean;
}

export function Sheet({ title, onClose, children, wide = false }: Props) {
    const ref = useRef<HTMLDivElement>(null);
    // The newest onClose, read when Esc is pressed, so a redraw above never re-runs the effects below.
    const close = useRef(onClose);
    useEffect(() => {
        close.current = onClose;
    }, [onClose]);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close.current();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('keydown', onKey);
        };
    }, []);

    // A field inside that asked for focus (autoFocus) keeps it.
    useEffect(() => {
        const dialog = ref.current;
        if (dialog !== null && !dialog.contains(document.activeElement)) dialog.focus();
    }, []);

    return (
        <div className="fixed inset-0 z-40 flex items-stretch justify-center bg-text/40 medium:items-center medium:p-6" role="presentation">
            <div
                ref={ref}
                role="dialog"
                aria-modal="true"
                aria-label={title}
                tabIndex={-1}
                className={`flex max-h-full w-full flex-col gap-4 overflow-y-auto bg-background p-4 outline-none medium:rounded-2xl medium:p-6 ${wide ? 'medium:max-w-4xl' : 'medium:max-w-lg'}`}
            >
                <header className="flex items-center justify-between gap-4">
                    <h2 className="text-2xl font-semibold">{title}</h2>
                    <button type="button" className="min-h-touch rounded-xl border-2 border-border px-4 text-lg" onClick={onClose}>
                        Cancel
                    </button>
                </header>
                {children}
            </div>
        </div>
    );
}
