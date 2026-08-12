/**
 * SNAPSMACK - conservative public PWA service worker
 * SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
 */

'use strict';
const CACHE = 'snapsmack-pwa-0.7.517';
const PUBLIC_SHELL = [
    './offline.php',
    './assets/pwa/icon-192.png',
    './assets/pwa/icon-512.png',
    './assets/pwa/apple-touch-icon.png'
];

self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(PUBLIC_SHELL)));
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(caches.keys().then(keys => Promise.all(
        keys.filter(key => key.startsWith('snapsmack-pwa-') && key !== CACHE).map(key => caches.delete(key))
    )).then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Private/admin responses, user media and JSON endpoints are always network-only.
    if (/\/(?:app|smack-|snap-in|api|process-|img_uploads|media_assets)/i.test(url.pathname)
        || url.searchParams.has('format') || url.searchParams.has('ajax')) return;

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('./offline.php')));
        return;
    }

    // Only immutable, explicitly public app assets use cache-first behaviour.
    if (/\/assets\/pwa\/(?:icon-(?:192|512)|apple-touch-icon)\.png$/i.test(url.pathname)) {
        event.respondWith(caches.match(request).then(hit => hit || fetch(request)));
    }
});

// ===== SNAPSMACK EOF =====
