/** The few icons the counter needs; each sits beside words, never instead of them (spec section 15). */
interface Props {
    className?: string;
}

const base = 'h-6 w-6 shrink-0';

export function WarningIcon({ className = base }: Props) {
    return (
        <svg aria-hidden="true" viewBox="0 0 24 24" className={className} fill="none" stroke="currentColor" strokeWidth="2.5">
            <path d="M12 3 2 21h20L12 3Z" strokeLinejoin="round" />
            <path d="M12 10v5M12 18h.01" strokeLinecap="round" />
        </svg>
    );
}

export function CheckIcon({ className = base }: Props) {
    return (
        <svg aria-hidden="true" viewBox="0 0 24 24" className={className} fill="none" stroke="currentColor" strokeWidth="2.5">
            <path d="m5 12 5 5 9-10" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

export function PrinterIcon({ className = base }: Props) {
    return (
        <svg aria-hidden="true" viewBox="0 0 24 24" className={className} fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M6 9V3h12v6M6 18H4v-7h16v7h-2M7 14h10v7H7z" strokeLinejoin="round" />
        </svg>
    );
}

export function DotIcon({ className = 'h-3 w-3 shrink-0' }: Props) {
    return (
        <svg aria-hidden="true" viewBox="0 0 12 12" className={className}>
            <circle cx="6" cy="6" r="5" fill="currentColor" />
        </svg>
    );
}

export function ClockIcon({ className = base }: Props) {
    return (
        <svg aria-hidden="true" viewBox="0 0 24 24" className={className} fill="none" stroke="currentColor" strokeWidth="2.5">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" strokeLinecap="round" />
        </svg>
    );
}
