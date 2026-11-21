/**
 * A poll that meets server errors waits longer each time, up to 30 seconds (finding LG8): one fault that
 * failed every poll wrote a 22 KB error each time and turned the server's 50 MB log over in under an hour.
 * Only a server error counts (a 500). No answer at all, or a restart's 503, keeps the poll's own pace, so a
 * counter shows the server as back the moment it is; any good answer brings the pace back at once.
 */
export const MAX_WAIT_MS = 30_000;

export class Backoff {
    private failures = 0;
    private skip = 0;

    constructor(private readonly paceMs: number) {}

    /** After each answer the server gave; 0 when it gave none. */
    answered(status: number): void {
        if (status >= 500 && status !== 503) {
            this.failures += 1;
            this.skip = Math.max(0, Math.round(this.delayMs() / this.paceMs) - 1);
        } else {
            this.failures = 0;
            this.skip = 0;
        }
    }

    /** For a poll on a timeout: how long to wait before the next one. */
    delayMs(): number {
        return this.failures === 0 ? this.paceMs : Math.min(MAX_WAIT_MS, this.paceMs * 2 ** this.failures);
    }

    /** For polls on an interval: whether this tick polls, or waits out the backoff. */
    tick(): boolean {
        if (this.skip > 0) {
            this.skip -= 1;
            return false;
        }
        return true;
    }
}
