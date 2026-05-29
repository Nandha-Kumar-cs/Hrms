/**
 * MagDyn HRMS - Service Worker
 * Provides offline caching and push notification support
 */

const CACHE_VERSION = 'hrms-v1';
const STATIC_CACHE  = `${CACHE_VERSION}-static`;
const DYNAMIC_CACHE = `${CACHE_VERSION}-dynamic`;

// Assets to cache on install
const STATIC_ASSETS = [
    './',
    './index.php',
    './login.php',
    './assets/css/magdyn-base.css',
    './assets/css/hrms.css',
    './assets/js/hrms-core.js',
    'https://code.jquery.com/jquery-3.7.1.min.js',
    'https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css',
    'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
    './manifest.json',
    './assets/icons/icon-192.png',
    './assets/icons/icon-512.png',
];

// Pages to cache dynamically
const DYNAMIC_ROUTES = [
    '/modules/attendance/',
    '/modules/payroll/',
    '/modules/employee/',
];

// ─── Install ───────────────────────────────────────────────────────────────
self.addEventListener('install', event => {
    console.log('[SW] Installing...');
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(cache => {
                console.log('[SW] Caching static assets');
                return Promise.allSettled(
                    STATIC_ASSETS.map(url =>
                        cache.add(url).catch(err => console.warn('[SW] Failed to cache:', url, err))
                    )
                );
            })
            .then(() => self.skipWaiting())
    );
});

// ─── Activate ──────────────────────────────────────────────────────────────
self.addEventListener('activate', event => {
    console.log('[SW] Activating...');
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys
                    .filter(k => k.startsWith('hrms-') && k !== STATIC_CACHE && k !== DYNAMIC_CACHE)
                    .map(k => { console.log('[SW] Deleting old cache:', k); return caches.delete(k); })
            )
        ).then(() => self.clients.claim())
    );
});

// ─── Fetch ─────────────────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET, external, or API requests
    if (request.method !== 'GET') return;
    if (!url.origin.includes(self.location.origin)) return;
    if (url.pathname.startsWith('/api/')) return;

    // Network-first for PHP pages (dynamic content)
    if (url.pathname.endsWith('.php') || url.pathname === '/') {
        event.respondWith(networkFirst(request));
        return;
    }

    // Cache-first for static assets
    event.respondWith(cacheFirst(request));
});

async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) return cached;
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(STATIC_CACHE);
            cache.put(request, response.clone());
        }
        return response;
    } catch {
        return new Response('<h2>Offline</h2><p>This resource is not available offline.</p>',
            { headers: { 'Content-Type': 'text/html' } });
    }
}

async function networkFirst(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(DYNAMIC_CACHE);
            cache.put(request, response.clone());
        }
        return response;
    } catch {
        const cached = await caches.match(request);
        if (cached) return cached;
        return caches.match('./') || new Response(`
            <!DOCTYPE html>
            <html>
            <head><meta charset="UTF-8"><title>HRMS — Offline</title>
            <style>body{font-family:sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;height:100vh;background:#0f172a;color:#fff;margin:0;}
            .icon{font-size:4rem;margin-bottom:1rem;}.title{font-size:1.5rem;font-weight:bold;color:#60a5fa;}
            .sub{color:#94a3b8;text-align:center;max-width:300px;}</style></head>
            <body>
                <div class="icon">📶</div>
                <div class="title">You're Offline</div>
                <div class="sub">Please check your internet connection to use HRMS.</div>
            </body></html>`,
            { headers: { 'Content-Type': 'text/html' } });
    }
}

// ─── Push Notifications ────────────────────────────────────────────────────
self.addEventListener('push', event => {
    console.log('[SW] Push received');
    let data = { title: 'HRMS Notification', body: 'You have a new notification.', icon: './assets/icons/icon-192.png', badge: './assets/icons/icon-72.png', tag: 'hrms-notif', url: '/' };

    if (event.data) {
        try { Object.assign(data, event.data.json()); } catch { data.body = event.data.text(); }
    }

    const options = {
        body:    data.body,
        icon:    data.icon,
        badge:   data.badge,
        tag:     data.tag,
        data:    { url: data.url },
        actions: [
            { action: 'open',    title: 'View' },
            { action: 'dismiss', title: 'Dismiss' },
        ],
        vibrate:   [100, 50, 100],
        requireInteraction: false,
    };

    event.waitUntil(self.registration.showNotification(data.title, options));
});

// ─── Notification Click ────────────────────────────────────────────────────
self.addEventListener('notificationclick', event => {
    console.log('[SW] Notification clicked:', event.action);
    event.notification.close();

    if (event.action === 'dismiss') return;

    const targetUrl = event.notification.data?.url || '/';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
            for (const client of clientList) {
                if (client.url.includes(targetUrl) && 'focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) return clients.openWindow(targetUrl);
        })
    );
});

// ─── Background Sync ───────────────────────────────────────────────────────
self.addEventListener('sync', event => {
    if (event.tag === 'sync-attendance') {
        console.log('[SW] Background sync: attendance');
        // Could sync offline attendance data here
    }
});

// ─── Message Handler ───────────────────────────────────────────────────────
self.addEventListener('message', event => {
    if (event.data === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    if (event.data === 'CLEAR_CACHE') {
        caches.keys().then(keys => keys.forEach(k => caches.delete(k)));
    }
});
