/**
 * The one-time enrolment code given in the page's address after #, as `haq enrol` and the Windows setup
 * open it: /enrol#code=123456 (D86). The part after # never reaches the server, so the code is in no log.
 * Only the characters a code can hold are kept, at most 20 of them, as the server accepts.
 */
export function codeFromHash(hash: string): string {
    const code = new URLSearchParams(hash.replace(/^#/, '')).get('code') ?? '';

    return code.replace(/[^A-Za-z0-9-]/g, '').slice(0, 20);
}
