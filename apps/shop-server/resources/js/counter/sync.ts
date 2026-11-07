import { getJson, Unreachable } from '@/lib/api';
import type { Catalogue } from './catalogue';
import { allRows, clearRows, getMeta, putRows, setMeta } from './db';
import type { ChangesPage, FeedRows, FeedTable, ItemPrices, Shape, SnapshotPage } from './types';
import { FEED_TABLES } from './types';

/**
 * Keeps the counter's copy of the catalogue: from IndexedDB at once, then a paged snapshot if it has
 * none, then the changes after its seq every 2 seconds, which is also its heartbeat (spec section 4)
 * [R5, F25]. The prices and 30-day sales ride along; the generated shapes are fetched again when the
 * categories, templates or pick-lists change. After a restore the server's feed has a new generation and
 * its change log is back at the backup, so the counter throws its copy away and starts again (D44).
 */
export interface SyncEvents {
    /** Items whose search entry changed; null means everything (a snapshot or a reload). */
    onItems: (ids: Set<string> | null) => void;
    onStatus: (online: boolean) => void;
}

const POLL_MS = 2000;

export class CatalogueSync {
    private timer: number | undefined;
    private stopped = false;
    private generation: string | null = null;

    constructor(
        private readonly cat: Catalogue,
        private readonly events: SyncEvents,
    ) {}

    async start(): Promise<void> {
        await this.load();
        if (this.cat.seq === 0) {
            await this.snapshot();
        }
        await this.fetchShapes();
        this.schedule(0);
    }

    stop(): void {
        this.stopped = true;
        window.clearTimeout(this.timer);
    }

    /** Applies rows learned outside the feed, such as an item this counter just created. */
    absorb(rows: FeedRows, prices: ItemPrices[]): void {
        this.cat.apply(rows);
        this.cat.setPrices(prices);
        void this.persist(rows, {}, prices);
        this.events.onItems(new Set([...(rows.items ?? []).map((i) => i.id), ...prices.map((p) => p.item_id)]));
    }

    private schedule(ms: number): void {
        if (this.stopped) return;
        this.timer = window.setTimeout(() => {
            void this.poll();
        }, ms);
    }

    private async poll(): Promise<void> {
        try {
            const generation = this.generation === null ? '' : `&generation=${encodeURIComponent(this.generation)}`;
            const answer = await getJson<ChangesPage>(`/api/counter/catalogue/changes?after=${String(this.cat.seq)}${generation}`);
            if (!answer.ok) throw new Unreachable(`The feed answered ${String(answer.status)}.`);
            const page = answer.body;
            this.events.onStatus(true);
            if (page.reset) {
                // The server was restored: everything this copy holds may be ahead of it.
                this.cat.clear();
                this.cat.seq = 0;
                await this.snapshot();
                await this.fetchShapes();
                this.schedule(0);
                return;
            }
            if (this.generation === null) {
                this.generation = page.generation;
                await setMeta('generation', page.generation);
            }
            if (page.seq !== this.cat.seq) {
                this.cat.apply(page.rows);
                this.cat.remove(page.removed);
                this.cat.setPrices(page.prices);
                this.cat.seq = page.seq;
                await this.persist(page.rows, page.removed, page.prices);
                await setMeta('seq', page.seq);
                const ids = new Set<string>([...(page.rows.items ?? []).map((i) => i.id), ...(page.rows.aliases ?? []).map((a) => a.item_id), ...page.prices.map((p) => p.item_id)]);
                for (const id of page.removed.items ?? []) ids.add(id);
                this.events.onItems(ids);
                if ((page.rows.categories?.length ?? 0) + (page.rows.category_templates?.length ?? 0) + (page.rows.picklist_values?.length ?? 0) > 0) {
                    await this.fetchShapes();
                }
            }
            this.schedule(page.more ? 0 : POLL_MS);
        } catch {
            this.events.onStatus(false);
            this.schedule(POLL_MS);
        }
    }

    private async load(): Promise<void> {
        try {
            const seq = await getMeta<number>('seq');
            if (seq === undefined) return;
            this.generation = (await getMeta<string>('generation')) ?? null;
            const rows = await allRows();
            const grouped: FeedRows = {};
            const prices: ItemPrices[] = [];
            for (const { table, row } of rows) {
                if ((table as string) === 'prices') {
                    prices.push(row as ItemPrices);
                } else {
                    ((grouped[table] ??= []) as unknown[]).push(row);
                }
            }
            this.cat.apply(grouped);
            this.cat.setPrices(prices);
            const shapes = await getMeta<Shape[]>('shapes');
            if (shapes !== undefined) this.cat.shapes = shapes;
            this.cat.seq = seq;
            this.events.onItems(null);
        } catch {
            this.cat.seq = 0;
        }
    }

    private async snapshot(): Promise<void> {
        let cursor: string | null = null;
        let first = true;
        for (;;) {
            try {
                const url: string = cursor === null ? '/api/counter/catalogue/snapshot' : `/api/counter/catalogue/snapshot?cursor=${encodeURIComponent(cursor)}`;
                const answer = await getJson<SnapshotPage>(url, undefined, 30_000);
                if (!answer.ok) throw new Unreachable('The snapshot failed.');
                if (first) {
                    await clearRows();
                    first = false;
                }
                const page = answer.body;
                this.cat.apply(page.rows);
                this.cat.setPrices(page.prices);
                await this.persist(page.rows, {}, page.prices);
                this.events.onStatus(true);
                if (page.next === null) {
                    this.cat.seq = page.seq;
                    this.generation = page.generation;
                    await setMeta('seq', page.seq);
                    await setMeta('generation', page.generation);
                    this.events.onItems(null);
                    return;
                }
                cursor = page.next;
            } catch {
                this.events.onStatus(false);
                await new Promise((resolve) => window.setTimeout(resolve, POLL_MS));
            }
        }
    }

    private async fetchShapes(): Promise<void> {
        try {
            const answer = await getJson<{ shapes: Shape[] }>('/api/counter/catalogue/shapes', undefined, 30_000);
            if (answer.ok) {
                this.cat.shapes = answer.body.shapes;
                await setMeta('shapes', answer.body.shapes);
                this.events.onItems(null);
            }
        } catch {
            // The shapes come back with the next change; the items still sell.
        }
    }

    private async persist(rows: FeedRows, removed: ChangesPage['removed'], prices: ItemPrices[]): Promise<void> {
        const put: { table: FeedTable; row: { id: string } }[] = [];
        for (const table of FEED_TABLES) {
            for (const row of rows[table] ?? []) put.push({ table, row });
        }
        for (const p of prices) put.push({ table: 'prices' as FeedTable, row: { ...p, id: p.item_id } });
        const gone: { table: FeedTable; id: string }[] = [];
        for (const [table, ids] of Object.entries(removed) as [FeedTable, string[]][]) {
            for (const id of ids) gone.push({ table, id });
        }
        try {
            await putRows(put, gone);
        } catch {
            // Storage refused: the copy in memory still serves this session.
        }
    }
}
