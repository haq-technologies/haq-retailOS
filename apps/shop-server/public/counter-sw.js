/*
 * The counter's service worker (spec section 4) [R4]: the app shell, so a reload while the shop server
 * restarts still opens the counter, which then shows Reconnecting with its sale intact, also while nginx
 * answers with its "Starting, please wait" page (D46). Built assets carry
 * a content hash, so they are kept as they are; the counter page is fetched fresh whenever the server
 * answers. API calls are never cached.
 */
const ASSETS = 'haq-assets-v1';
const SHELL = 'haq-shell-v1';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== ASSETS && k !== SHELL).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.open(ASSETS).then(async (cache) => {
                const hit = await cache.match(request);
                if (hit) return hit;
                const response = await fetch(request);
                if (response.ok) cache.put(request, response.clone());
                return response;
            }),
        );
        return;
    }

    // While the server starts (no answer, or 502 to 504 with the starting page), the counter opens from its
    // cached shell and shows Reconnecting with its sale kept; with no shell yet, the starting page shows.
    if (request.mode === 'navigate' && url.pathname === '/counter') {
        event.respondWith(
            fetch(request)
                .then(async (response) => {
                    if (response.ok) {
                        (await caches.open(SHELL)).put('/counter', response.clone());
                        return response;
                    }
                    if (response.status >= 502 && response.status <= 504) {
                        return (await (await caches.open(SHELL)).match('/counter')) ?? response;
                    }
                    return response;
                })
                .catch(async () => (await (await caches.open(SHELL)).match('/counter')) ?? Response.error()),
        );
    }
});
