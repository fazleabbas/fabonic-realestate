/**
 * FIS Real Estate — Service Worker (Part 15.1)
 * Cache-first for static assets, network-first for API calls, offline fallback.
 */

const CACHE_VERSION = 'fis-re-v1';
const BASE_PATH = self.registration.scope;

// Static assets to pre-cache on install
const PRECACHE_ASSETS = [
    BASE_PATH,
    BASE_PATH + 'offline.html',
    BASE_PATH + 'assets/css/style.css',
    BASE_PATH + 'assets/js/main.js',
    BASE_PATH + 'assets/js/offline-db.js',
    BASE_PATH + 'assets/js/sync-manager.js',
    BASE_PATH + 'assets/js/pwa.js',
    BASE_PATH + 'manifest.json',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css',
    'https://code.jquery.com/jquery-3.7.1.min.js',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'
];

// ============================================================
// Install — pre-cache static assets
// ============================================================
self.addEventListener('install', (event) => {
    console.log('[SW] Installing — ' + CACHE_VERSION);
    event.waitUntil(
        caches.open(CACHE_VERSION)
            .then((cache) => cache.addAll(PRECACHE_ASSETS))
            .then(() => self.skipWaiting())
            .catch((err) => console.error('[SW] Install failed:', err))
    );
});

// ============================================================
// Activate — clean up old caches
// ============================================================
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating — ' + CACHE_VERSION);
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(
                names.filter((n) => n !== CACHE_VERSION).map((n) => caches.delete(n))
            ))
            .then(() => self.clients.claim())
    );
});

// ============================================================
// Fetch — routing strategy
// ============================================================
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Only handle GET requests
    if (event.request.method !== 'GET') return;

    // Skip cross-origin except CDN
    const isSameOrigin = url.origin === self.location.origin;
    const isCDN = url.hostname === 'cdn.jsdelivr.net' || url.hostname === 'code.jquery.com';
    if (!isSameOrigin && !isCDN) return;

    const isAPI = url.pathname.includes('/api/') || url.pathname.includes('/ajax/');
    const isPHP = url.pathname.endsWith('.php') || url.pathname.endsWith('/');
    const isStaticAsset = /\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf|eot|json)(\?.*)?$/i.test(url.pathname);

    // ---- API calls: network-only (offline handled by IndexedDB) ----
    if (isAPI) {
        event.respondWith(
            fetch(event.request).catch(() => {
                return new Response(JSON.stringify({
                    success: false,
                    offline: true,
                    message: 'You are offline. Changes will sync when connection is restored.'
                }), {
                    status: 503,
                    headers: { 'Content-Type': 'application/json' }
                });
            })
        );
        return;
    }

    // ---- Static assets: cache-first ----
    if (isStaticAsset || isCDN) {
        event.respondWith(
            caches.match(event.request).then((cached) => {
                return cached || fetch(event.request).then((response) => {
                    if (response.status === 200) {
                        const clone = response.clone();
                        caches.open(CACHE_VERSION).then((c) => c.put(event.request, clone));
                    }
                    return response;
                }).catch(() => {
                    // For font files, return empty response instead of error
                    if (/\.(woff2?|ttf|eot)$/i.test(url.pathname)) {
                        return new Response('', { status: 200 });
                    }
                    throw new Error('Network unavailable');
                });
            })
        );
        return;
    }

    // ---- PHP pages: network-first with offline fallback ----
    if (isPHP || event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    if (response.status === 200) {
                        const clone = response.clone();
                        caches.open(CACHE_VERSION).then((c) => c.put(event.request, clone));
                    }
                    return response;
                })
                .catch(() => {
                    return caches.match(event.request).then((cached) => {
                        return cached || caches.match(BASE_PATH + 'offline.html');
                    });
                })
        );
        return;
    }

    // ---- Everything else: cache-first ----
    event.respondWith(
        caches.match(event.request).then((cached) => {
            return cached || fetch(event.request);
        })
    );
});

// ============================================================
// Background Sync
// ============================================================
self.addEventListener('sync', (event) => {
    console.log('[SW] Background sync:', event.tag);
    if (event.tag === 'sync-queue') {
        event.waitUntil(
            self.clients.matchAll().then((clients) => {
                clients.forEach((client) => {
                    client.postMessage({ type: 'TRIGGER_SYNC' });
                });
            })
        );
    }
});

// ============================================================
// Messages from page
// ============================================================
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    if (event.data && event.data.type === 'CLEAR_CACHE') {
        event.waitUntil(
            caches.keys().then((names) =>
                Promise.all(names.map((n) => caches.delete(n)))
            ).then(() => {
                self.clients.matchAll().then((clients) => {
                    clients.forEach((c) => c.postMessage({ type: 'CACHE_CLEARED' }));
                });
            })
        );
    }
});

console.log('[SW] Script loaded — FIS Real Estate PWA — ' + CACHE_VERSION);
