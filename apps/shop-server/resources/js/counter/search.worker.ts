/// <reference lib="webworker" />
import type MiniSearch from 'minisearch';
import { buildIndex, search, upsert, type SearchDoc } from './search';

/** Search runs in a Web Worker, so typing never waits for it (spec section 4) [R4]. */
type Message =
    | { type: 'index'; docs: SearchDoc[] }
    | { type: 'upsert'; docs: SearchDoc[] }
    | { type: 'remove'; ids: string[] }
    | { type: 'query'; id: number; query: string };

let index: MiniSearch<SearchDoc> = buildIndex([]);

self.onmessage = (event: MessageEvent<Message>) => {
    const message = event.data;
    if (message.type === 'index') {
        index = buildIndex(message.docs);
    } else if (message.type === 'upsert') {
        for (const doc of message.docs) upsert(index, doc);
    } else if (message.type === 'remove') {
        for (const id of message.ids) if (index.has(id)) index.discard(id);
    } else {
        self.postMessage({ id: message.id, ids: search(index, message.query) });
    }
};
