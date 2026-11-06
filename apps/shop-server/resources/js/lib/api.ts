/**
 * JSON requests to the shop server. The acting-user token travels on every request in its own header,
 * never in a cookie, so each request is attributed to whoever sent it (spec section 3).
 */
export const ACTING_TOKEN_HEADER = 'X-Acting-Token';

function xsrfToken(): string {
    const match = /(?:^|;\s*)XSRF-TOKEN=([^;]+)/.exec(document.cookie);
    return match?.[1] === undefined ? '' : decodeURIComponent(match[1]);
}

export interface ApiResult<T> {
    ok: boolean;
    status: number;
    body: T;
    renewedToken: string | null;
}

/** The server did not answer: the cable, the server or a restart. The counter shows Reconnecting. */
export class Unreachable extends Error {}

async function request<T>(method: 'GET' | 'POST', url: string, body: unknown, token: string | undefined, timeoutMs: number): Promise<ApiResult<T>> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() };
    if (method === 'POST') {
        headers['Content-Type'] = 'application/json';
    }
    if (token !== undefined) {
        headers[ACTING_TOKEN_HEADER] = token;
    }
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
        controller.abort();
    }, timeoutMs);
    let response: Response;
    try {
        response = await fetch(url, {
            method,
            headers,
            body: method === 'POST' ? JSON.stringify(body) : undefined,
            credentials: 'same-origin',
            signal: controller.signal,
        });
    } catch {
        throw new Unreachable('The server did not answer.');
    } finally {
        window.clearTimeout(timer);
    }
    if (response.status >= 502 && response.status <= 504) {
        throw new Unreachable('The server is restarting.');
    }
    let parsed: unknown;
    try {
        parsed = await response.json();
    } catch {
        parsed = {};
    }
    return { ok: response.ok, status: response.status, body: parsed as T, renewedToken: response.headers.get(ACTING_TOKEN_HEADER) };
}

export function postJson<T>(url: string, body: unknown, token?: string, timeoutMs = 15_000): Promise<ApiResult<T>> {
    return request<T>('POST', url, body, token, timeoutMs);
}

export function getJson<T>(url: string, token?: string, timeoutMs = 10_000): Promise<ApiResult<T>> {
    return request<T>('GET', url, undefined, token, timeoutMs);
}
