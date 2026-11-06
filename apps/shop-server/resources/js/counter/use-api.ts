import { useEffect, useMemo, useRef } from 'react';
import { getJson, postJson, type ApiResult } from '@/lib/api';

/**
 * The counter's calls as the signed-in person. A renewed acting-user token from the server replaces the
 * old one (spec section 3). The object stays the same for the whole session, so a renewal never restarts
 * the counter's polling.
 */
export interface CounterApi {
    get: <T>(url: string) => Promise<ApiResult<T>>;
    post: <T>(url: string, body: unknown, timeoutMs?: number) => Promise<ApiResult<T>>;
}

export function useCounterApi(token: string, onToken: (token: string) => void): CounterApi {
    const current = useRef(token);
    const notify = useRef(onToken);
    useEffect(() => {
        current.current = token;
        notify.current = onToken;
    }, [token, onToken]);

    return useMemo(() => {
        const renew = <T,>(result: ApiResult<T>): ApiResult<T> => {
            if (result.renewedToken !== null && result.renewedToken !== current.current) {
                current.current = result.renewedToken;
                notify.current(result.renewedToken);
            }
            return result;
        };
        return {
            get: async <T,>(url: string) => renew(await getJson<T>(url, current.current)),
            post: async <T,>(url: string, body: unknown, timeoutMs?: number) => renew(await postJson<T>(url, body, current.current, timeoutMs)),
        };
    }, []);
}
