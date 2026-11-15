import { useEffect, useRef } from 'react';

/**
 * A modal sheet over the counter. Esc cancels; Enter confirms the focused dialog (spec section 15).
 *
 * Focus moves into the sheet once, when it opens, and never again. The counter redraws every few seconds
 * as it polls, and each redraw hands the sheet a new onClose; a sheet that took focus again on each one
 * pulled it out of the field the cashier was typing in (D76).
 *
 * The title bar, with Cancel, stays in view and only the middle scrolls (D80). A sheet with a footer keeps
 * its main action in view under the middle. A sheet that lays out its own panes (ownScroll) gets the whole
 * middle and scrolls its own parts.
 */
interface Props {
    title: string;
    onClose: () => void;
    children: React.ReactNode;
    /** A list or a form, wider than a question. */
    wide?: boolean;
    /** Room for two panes side by side. */
    full?: boolean;
    footer?: React.ReactNode;
    ownScroll?: boolean;
}

export function Sheet({ title, onClose, children, wide = false, full = false, footer, ownScroll = false }: Props) {
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

    const width = full ? 'medium:max-w-6xl' : wide ? 'medium:max-w-4xl' : 'medium:max-w-lg';

    return (
        <div className="fixed inset-0 z-40 flex items-stretch justify-center bg-text/40 medium:items-center medium:p-6" role="presentation">
            <div
                ref={ref}
                role="dialog"
                aria-modal="true"
                aria-label={title}
                tabIndex={-1}
                className={`flex max-h-full w-full flex-col bg-background outline-none medium:rounded-2xl ${width}`}
            >
                <header className="flex shrink-0 items-center justify-between gap-4 px-4 pb-4 pt-4 medium:px-6 medium:pt-6">
                    <h2 className="text-2xl font-semibold">{title}</h2>
                    <button type="button" className="min-h-touch rounded-xl border-2 border-border px-4 text-lg" onClick={onClose}>
                        Cancel
                    </button>
                </header>
                {ownScroll ? (
                    <div className="flex min-h-0 flex-1 flex-col">{children}</div>
                ) : (
                    <div className={`flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 medium:px-6 ${footer === undefined ? 'pb-4 medium:pb-6' : 'pb-4'}`}>{children}</div>
                )}
                {footer !== undefined && <footer className="shrink-0 border-t border-border px-4 py-3 medium:px-6 medium:pb-6">{footer}</footer>}
            </div>
        </div>
    );
}
