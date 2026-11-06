/**
 * A message with an icon and words, never colour alone (spec section 15). Errors use the destructive
 * red; warnings the orange, with black text on its fill.
 */
interface Props {
    tone: 'error' | 'warning';
    children: React.ReactNode;
}

export function Notice({ tone, children }: Props) {
    const styles =
        tone === 'error'
            ? 'border-destructive text-destructive'
            : 'border-warning text-text bg-warning';
    return (
        <p role="alert" className={`flex items-start gap-3 rounded-xl border-2 px-4 py-3 text-lg ${styles}`}>
            <svg aria-hidden="true" viewBox="0 0 24 24" className="mt-0.5 h-6 w-6 shrink-0" fill="none" stroke="currentColor" strokeWidth="2.5">
                <circle cx="12" cy="12" r="10" />
                <path d="M12 7v6M12 17h.01" strokeLinecap="round" />
            </svg>
            <span>{children}</span>
        </p>
    );
}
