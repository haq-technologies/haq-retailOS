import { postJson } from './api';

/**
 * A screen's own errors, sent to the server's log (finding LG3): in kiosk mode nobody opens Chrome's console,
 * so an error there was seen by no one. Only the error's message and stack and the page's path are sent,
 * never the cart, a PIN or anything typed. The server takes them only from an enrolled device.
 */
export type ErrorKind = 'crash' | 'browser';

export interface ErrorDetails {
    message: string;
    stack: string | null;
}

/** Browser noise that says nothing about the app. */
const IGNORED = [/^ResizeObserver loop/, /^Script error\.?$/];

/**
 * At most 5 reports a minute leave a page, and the same message at most once a minute, so a screen that
 * fails in a loop cannot flood the server's log (finding LG8).
 */
export class ReportLimiter {
    private sent: number[] = [];
    private readonly last = new Map<string, number>();

    constructor(
        private readonly perMinute = 5,
        private readonly sameEveryMs = 60_000,
    ) {}

    allow(message: string, now: number): boolean {
        this.sent = this.sent.filter((at) => now - at < 60_000);
        const previous = this.last.get(message);
        if (this.sent.length >= this.perMinute || (previous !== undefined && now - previous < this.sameEveryMs)) {
            return false;
        }
        this.sent.push(now);
        this.last.set(message, now);
        return true;
    }
}

/** JSON.stringify as it behaves: undefined for undefined or a function, and a throw for a circular object. */
const stringify: (value: unknown) => string | undefined = JSON.stringify;

/** An error's text: its name and message, or what was thrown instead, never itself a new error. */
function textOf(error: unknown): string {
    if (error instanceof Error) return `${error.name}: ${error.message}`;
    if (typeof error === 'string') return error;
    try {
        return stringify(error) ?? String(error);
    } catch {
        return String(error);
    }
}

export function describeError(error: unknown, extra?: string | null): ErrorDetails {
    const stack = error instanceof Error ? (error.stack ?? null) : null;
    const message = textOf(error);
    const joined = [stack, extra].filter((part): part is string => typeof part === 'string' && part !== '').join('\n');
    return { message: message.slice(0, 500), stack: joined === '' ? null : joined.slice(0, 4000) };
}

export function isIgnored(message: string): boolean {
    return IGNORED.some((pattern) => pattern.test(message));
}

const limiter = new ReportLimiter();

/** Sends one report; never throws. The answer's reference is what a person reads to HAQ support. */
export async function reportError(kind: ErrorKind, error: unknown, extra?: string | null): Promise<string | null> {
    const details = describeError(error, extra);
    if (isIgnored(details.message) || !limiter.allow(details.message, Date.now())) {
        return null;
    }
    try {
        const answer = await postJson<{ reference?: unknown }>('/api/device/errors', { kind, ...details, page: window.location.pathname.slice(0, 200) }, undefined, 5_000);
        return answer.ok && typeof answer.body.reference === 'string' ? answer.body.reference : null;
    } catch {
        return null;
    }
}

/** Errors that did not stop the screen: a failed promise nobody caught, a script error outside React. */
export function installErrorReporting(): void {
    window.addEventListener('error', (event) => {
        void reportError('browser', event.error ?? event.message);
    });
    window.addEventListener('unhandledrejection', (event) => {
        void reportError('browser', event.reason);
    });
}
