import type { FeedTable } from './types';

/**
 * The counter's IndexedDB: its copy of the catalogue, the sale in progress and the seven-day journal
 * (spec section 4) [R4, F1]. A browser that refuses storage still sells; it only starts from a fresh
 * snapshot each time.
 */
const NAME = 'haq-counter';
const VERSION = 1;

export interface JournalEntry {
    receipt_number: string;
    at: string;
    total_kobo: number;
    lines: { name: string; qty: number; unit_price_kobo: number }[];
    payments: { method: string; amount_kobo: number }[];
}

let opening: Promise<IDBDatabase | null> | null = null;

function open(): Promise<IDBDatabase | null> {
    opening ??= new Promise((resolve) => {
        try {
            const request = indexedDB.open(NAME, VERSION);
            request.onupgradeneeded = () => {
                const db = request.result;
                db.createObjectStore('rows');
                db.createObjectStore('meta');
                db.createObjectStore('journal', { keyPath: 'receipt_number' }).createIndex('at', 'at');
            };
            request.onsuccess = () => {
                resolve(request.result);
            };
            request.onerror = () => {
                resolve(null);
            };
        } catch {
            resolve(null);
        }
    });
    return opening;
}

function done(tx: IDBTransaction): Promise<void> {
    return new Promise((resolve, reject) => {
        tx.oncomplete = () => {
            resolve();
        };
        tx.onerror = () => {
            reject(tx.error ?? new Error('IndexedDB write failed'));
        };
        tx.onabort = () => {
            reject(tx.error ?? new Error('IndexedDB write aborted'));
        };
    });
}

function result<T>(request: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => {
            resolve(request.result);
        };
        request.onerror = () => {
            reject(request.error ?? new Error('IndexedDB read failed'));
        };
    });
}

export async function putRows(rows: { table: FeedTable; row: { id: string } }[], removed: { table: FeedTable; id: string }[] = []): Promise<void> {
    const db = await open();
    if (db === null || (rows.length === 0 && removed.length === 0)) return;
    const tx = db.transaction('rows', 'readwrite');
    const store = tx.objectStore('rows');
    for (const { table, row } of rows) store.put({ table, row }, `${table}:${row.id}`);
    for (const { table, id } of removed) store.delete(`${table}:${id}`);
    await done(tx);
}

export async function clearRows(): Promise<void> {
    const db = await open();
    if (db === null) return;
    const tx = db.transaction(['rows', 'meta'], 'readwrite');
    tx.objectStore('rows').clear();
    tx.objectStore('meta').delete('seq');
    await done(tx);
}

export async function allRows(): Promise<{ table: FeedTable; row: unknown }[]> {
    const db = await open();
    if (db === null) return [];
    return result(db.transaction('rows').objectStore('rows').getAll() as IDBRequest<{ table: FeedTable; row: unknown }[]>);
}

export async function getMeta<T>(key: string): Promise<T | undefined> {
    const db = await open();
    if (db === null) return undefined;
    return result(db.transaction('meta').objectStore('meta').get(key) as IDBRequest<T | undefined>);
}

export async function setMeta(key: string, value: unknown): Promise<void> {
    const db = await open();
    if (db === null) return;
    const tx = db.transaction('meta', 'readwrite');
    tx.objectStore('meta').put(value, key);
    await done(tx);
}

/** The counter journal keeps seven days of finished sales, for restore recovery only [F1]. */
export async function addToJournal(entry: JournalEntry): Promise<void> {
    const db = await open();
    if (db === null) return;
    const tx = db.transaction('journal', 'readwrite');
    tx.objectStore('journal').put(entry);
    await done(tx);
}

export async function pruneJournal(now: Date = new Date()): Promise<void> {
    const db = await open();
    if (db === null) return;
    const cutoff = new Date(now.getTime() - 7 * 24 * 3600 * 1000).toISOString();
    const tx = db.transaction('journal', 'readwrite');
    const range = IDBKeyRange.upperBound(cutoff, true);
    const cursor = tx.objectStore('journal').index('at').openCursor(range);
    cursor.onsuccess = () => {
        const c = cursor.result;
        if (c !== null) {
            c.delete();
            c.continue();
        }
    };
    await done(tx);
}

export async function journal(): Promise<JournalEntry[]> {
    const db = await open();
    if (db === null) return [];
    return result(db.transaction('journal').objectStore('journal').getAll() as IDBRequest<JournalEntry[]>);
}
