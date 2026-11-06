import type { SearchDoc } from './search';

/** Talks to the search worker; the newest query wins. */
export class SearchClient {
    private worker: Worker;
    private next = 0;
    private waiting = new Map<number, (ids: string[]) => void>();

    constructor() {
        this.worker = new Worker(new URL('./search.worker.ts', import.meta.url), { type: 'module' });
        this.worker.onmessage = (event: MessageEvent<{ id: number; ids: string[] }>) => {
            const resolve = this.waiting.get(event.data.id);
            this.waiting.delete(event.data.id);
            resolve?.(event.data.ids);
        };
    }

    index(docs: SearchDoc[]): void {
        this.worker.postMessage({ type: 'index', docs });
    }

    upsert(docs: SearchDoc[], remove: string[]): void {
        if (docs.length > 0) this.worker.postMessage({ type: 'upsert', docs });
        if (remove.length > 0) this.worker.postMessage({ type: 'remove', ids: remove });
    }

    query(query: string): Promise<string[]> {
        const id = ++this.next;
        return new Promise((resolve) => {
            this.waiting.set(id, resolve);
            this.worker.postMessage({ type: 'query', id, query });
        });
    }

    terminate(): void {
        this.worker.terminate();
    }
}
