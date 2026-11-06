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

export async function postJson<T>(url: string, body: unknown, token?: string): Promise<ApiResult<T>> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': xsrfToken(),
    };
    if (token !== undefined) {
        headers[ACTING_TOKEN_HEADER] = token;
    }
    const response = await fetch(url, { method: 'POST', headers, body: JSON.stringify(body), credentials: 'same-origin' });
    return {
        ok: response.ok,
        status: response.status,
        body: (await response.json()) as T,
        renewedToken: response.headers.get(ACTING_TOKEN_HEADER),
    };
}
