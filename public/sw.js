// holo-learn service worker: installability plus an offline page, nothing else.
// Pages, Inertia/JSON requests and /build assets are never cached here: pages carry the
// signed-in user's data, and hashed /build files are left to the HTTP cache.
const CACHE = 'holo-learn-v1';
const OFFLINE = '/offline.html';
const PRECACHE = [
    OFFLINE,
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/favicon.svg',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key !== CACHE)
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') {
        return;
    }

    // page loads: always the network; only when it fails, the cached offline page
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));

        return;
    }

    const url = new URL(request.url);
    if (
        url.origin === self.location.origin &&
        PRECACHE.includes(url.pathname)
    ) {
        event.respondWith(
            caches.match(request).then((cached) => cached ?? fetch(request)),
        );
    }
    // everything else: no respondWith, the browser handles it as usual
});
