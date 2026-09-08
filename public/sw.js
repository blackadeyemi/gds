/**
 * GDS service worker — the PWA offline/asset layer.
 *
 * Design constraints for a multi-user, server-rendered Livewire ERP whose
 * data lives in a central MySQL:
 *   - NEVER cache dynamic HTML or Livewire round-trips. Pages are per-user and
 *     change constantly; a cached page would leak another view or show stale
 *     stock. Navigations go to the network; only when the network fails do we
 *     show a generic offline page (never a real, cached screen).
 *   - Livewire updates are POST /livewire/update — excluded automatically by
 *     the GET-only filter below, so interactions always hit Lagos live.
 *   - Static assets (css/js/fonts/images) are cached stale-while-revalidate:
 *     served instantly from cache (the big win on the slow Abuja link) while a
 *     fresh copy is fetched in the background for next time.
 *
 * Updates: bump VERSION. The new worker installs, skipWaiting() lets it take
 * over, activate clears older caches, and the page reloads once (see the
 * controllerchange guard in the registration snippet). That is the whole
 * "push an update" story — deploy new assets + a bumped VERSION.
 */
const VERSION = 'gds-v1';
const STATIC_CACHE = `${VERSION}-static`;
const OFFLINE_URL = '/offline.html';

// Precache the offline fallback and the app icons — small, stable, and needed
// exactly when the network is down.
const PRECACHE = [
    OFFLINE_URL,
    '/images/pwa/icon-192.png',
    '/images/pwa/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

// Is this a same-origin request for a cacheable static asset?
function isStaticAsset(url, request) {
    if (url.origin !== self.location.origin) return false;
    if (['style', 'script', 'font', 'image'].includes(request.destination)) return true;
    return /^\/(css|js|fonts|images)\//.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Let anything that isn't a GET pass straight through: Livewire POSTs,
    // form submits, logout — all must reach the live server untouched.
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Full-page navigations: network-first, offline page as the only fallback.
    // We deliberately do NOT cache the response — pages are per-user and live.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    // Static assets: stale-while-revalidate.
    if (isStaticAsset(url, request)) {
        event.respondWith(
            caches.open(STATIC_CACHE).then((cache) =>
                cache.match(request).then((cached) => {
                    const network = fetch(request)
                        .then((resp) => {
                            if (resp && resp.status === 200 && resp.type === 'basic') {
                                cache.put(request, resp.clone());
                            }
                            return resp;
                        })
                        .catch(() => cached);
                    return cached || network;
                })
            )
        );
        return;
    }

    // Everything else same-origin GET (e.g. Livewire GET, downloads): live,
    // uncached — just fall through to the network.
});
