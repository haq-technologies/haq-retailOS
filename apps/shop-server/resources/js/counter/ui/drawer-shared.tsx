import { useCallback, useEffect, useState } from 'react';
import { padKeyFrom } from '@/components/keypad';
import { Notice } from '@/components/notice';
import type { Tile } from '@/components/sign-in-panel';
import type { CounterApi } from '../use-api';
import { ApprovalPad } from './approval-pad';

/*
 * The go-live basics at the counter (spec section 9; D68): Returns and voids, cash out of or into the drawer
 * beside a sale, and closing the day. Each asks for a manager's PIN on the same screen when the server says
 * so, and keeps its request ID until it is done, so a retry after a lost answer is never recorded twice.
 */

export type Approval = { action: string; subject_id: string; item_id: string | null; price_kobo: number | null };
export type Answer<T> = { message?: string; approval?: Approval } & T;

/** The keyboard as a fallback for the on-screen keypad (spec section 4), ignoring typing in a text field. */
export function useKeypadKeys(onKey: (key: string) => void) {
    useEffect(() => {
        const listen = (event: KeyboardEvent) => {
            if (event.target instanceof HTMLInputElement && event.target.type === 'text') return;
            const key = padKeyFrom(event.key);
            if (key !== null) {
                event.preventDefault();
                onKey(key);
            }
        };
        window.addEventListener('keydown', listen);
        return () => {
            window.removeEventListener('keydown', listen);
        };
    }, [onKey]);
}

export const choice = (on: boolean) => `min-h-touch rounded-xl border-2 px-4 ${on ? 'border-action font-semibold' : 'border-border'}`;

/** Sends a request; when a manager must approve, shows the pad, then sends it again with the token. */
export function useApproved<T>(api: CounterApi) {
    const [pending, setPending] = useState<{ approval: Approval; message: string; url: string; body: Record<string, unknown>; onDone: (answer: Answer<T>) => void } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const send = useCallback(
        async (url: string, body: Record<string, unknown>, onDone: (answer: Answer<T>) => void) => {
            setBusy(true);
            setError(null);
            try {
                const a = await api.post<Answer<T>>(url, body, 30_000);
                if (a.ok) {
                    setPending(null);
                    onDone(a.body);
                } else if (a.body.approval !== undefined) {
                    setPending({ approval: a.body.approval, message: a.body.message ?? 'A manager must approve this.', url, body, onDone });
                } else {
                    setError(a.body.message ?? 'That did not work. Try again.');
                }
            } catch {
                setError('The server did not answer. Try again: it is never recorded twice.');
            } finally {
                setBusy(false);
            }
        },
        [api],
    );
    // The same request again, with the manager's token, so it binds to exactly what was approved.
    const resend = useCallback(
        (token: string) => {
            if (pending !== null) void send(pending.url, { ...pending.body, approval_token: token }, pending.onDone);
        },
        [pending, send],
    );
    const approval = pending === null ? null : { approval: pending.approval, message: pending.message, resend };
    return { send, approval, error, setError, busy };
}

export function ManagerPad({ people, api, asked }: { people: Tile[]; api: CounterApi; asked: { approval: Approval; message: string; resend: (token: string) => void } }) {
    return (
        <div className="flex flex-col gap-3 rounded-xl border-2 border-warning-text p-3">
            <Notice tone="warning">{asked.message}</Notice>
            <ApprovalPad
                people={people}
                api={api}
                action={asked.approval.action}
                subjectId={asked.approval.subject_id}
                itemId={asked.approval.item_id}
                priceKobo={asked.approval.price_kobo}
                onApproved={asked.resend}
            />
        </div>
    );
}
