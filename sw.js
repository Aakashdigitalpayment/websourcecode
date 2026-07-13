/*
  ══════════════════════════════════════════════════════════════════════
  SERVICE WORKER — Aakash Cooperative CMS  v6
  New in v6:
    • Cache bump for real PNG PWA icons (was JPEG mislabeled as PNG)
    • Maskable 512 icon included for installability
  New in v5:
    • Cache bump for pull-to-refresh + PWA register fixes
    • Install no longer auto skipWaiting (avoids mid-form reload)
  New in v4 (2026-06-25 — Premium UI 7.0):
    • Cache version bumped to force re-fetch of updated CSS/fonts
      (Inter + Plus Jakarta Sans replace Mukta; premium-ui.css added)
  v3 features retained:
    • Member portal offline support  — stale-while-revalidate for
      /member/*.php pages; served from PAGES_CACHE when offline
    • /member/offline.php custom fallback showing cached dashboard info
    • Separate PAGES_CACHE prevents evicting static assets
    • SKIP_WAITING message handler for the update bar
    • All old caches (hrm-cms-*, coop-*-v3) auto-deleted on activate
  ══════════════════════════════════════════════════════════════════════
*/

const STATIC_CACHE = 'coop-static-v6';
const PAGES_CACHE  = 'coop-pages-v6';
const API_CACHE    = 'coop-api-v6';
const ALL_CACHES   = [STATIC_CACHE, PAGES_CACHE, API_CACHE];

/* Pre-cache at install — must be publicly accessible (no auth required) */
const PRECACHE_REQUIRED = [
  '/offline.php',
  '/member/offline.php',
  '/assets/images/logo.png',
];

/* Pre-cache attempt — skip silently if unavailable */
const PRECACHE_OPTIONAL = [
  '/assets/images/icon-192x192.png',
  '/assets/images/icon-512x512.png',
  '/assets/images/icon-512x512-maskable.png',
  '/assets/css/app-member.css',
  '/assets/css/app-core.css',
  '/assets/js/coop-mobile.js',
  '/assets/js/pwa-register.js',
  '/assets/js/pull-to-refresh.js',
];

/* ── Install ─────────────────────────────────────────────────────── */
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache =>
        cache.addAll(PRECACHE_REQUIRED)
          .then(() =>
            Promise.all(
              PRECACHE_OPTIONAL.map(url =>
                cache.add(url).catch(err =>
                  console.log('[SW] Optional skip:', url, err.message)
                )
              )
            )
          )
          .catch(err => console.warn('[SW] Pre-cache partial fail:', err))
      )
      /* Do not skipWaiting here — waiting SW activates only after
         explicit SKIP_WAITING from the update bar, so mid-form
         pages are not force-reloaded by a background SW update. */
  );
});

/* ── Activate: purge old caches ──────────────────────────────────── */
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys =>
        Promise.all(
          keys.map(key => {
            if (!ALL_CACHES.includes(key)) {
              console.log('[SW] Deleting old cache:', key);
              return caches.delete(key);
            }
          })
        )
      )
      .then(() => self.clients.claim())
  );
});

/* ── Fetch ───────────────────────────────────────────────────────── */
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  if (request.method !== 'GET') return;
  if (!url.protocol.startsWith('http')) return;

  /* Member portal PHP pages — stale-while-revalidate */
  if (url.pathname.startsWith('/member/') && url.pathname.endsWith('.php')) {
    event.respondWith(memberPageStrategy(request));
    return;
  }

  /* Other PHP / API — network-first */
  if (url.pathname.endsWith('.php') || url.pathname.includes('/api/')) {
    event.respondWith(networkFirst(request, API_CACHE, '/offline.php'));
    return;
  }

  /* Static assets — cache-first */
  event.respondWith(cacheFirst(request, STATIC_CACHE));
});

/* ────────────────────────────────────────────────────────────────────
   memberPageStrategy
   Online  → fetch from network, update PAGES_CACHE in background
   Offline → try PAGES_CACHE, then /member/offline.php, then /offline.php
   ──────────────────────────────────────────────────────────────────── */
async function memberPageStrategy(request) {
  const cache = await caches.open(PAGES_CACHE);
  try {
    const networkRes = await fetch(request);
    if (networkRes && networkRes.status === 200) {
      cache.put(request, networkRes.clone()); /* background update */
    }
    return networkRes;
  } catch (_) {
    /* Offline — serve cached version of this exact page */
    const exactCached = await cache.match(request);
    if (exactCached) return exactCached;

    /* No cache for this page — member offline fallback */
    const memberOffline = await caches.match('/member/offline.php');
    if (memberOffline) return memberOffline;

    /* Last resort */
    const genericOffline = await caches.match('/offline.php');
    if (genericOffline) return genericOffline;

    return new Response(
      '<html><body style="font-family:sans-serif;padding:40px;text-align:center">' +
      '<h2>अफलाइन</h2><p>इन्टरनेट जडान उपलब्ध छैन।</p>' +
      '<button onclick="location.reload()">फेरि प्रयास</button></body></html>',
      { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    );
  }
}

/* ── Network-First ───────────────────────────────────────────────── */
async function networkFirst(request, cacheName, fallbackUrl) {
  try {
    const networkRes = await fetch(request);
    if (networkRes && networkRes.status === 200) {
      const cache = await caches.open(cacheName);
      cache.put(request, networkRes.clone());
    }
    return networkRes;
  } catch (_) {
    const cached = await caches.match(request);
    if (cached) return cached;
    if (fallbackUrl) {
      const fallback = await caches.match(fallbackUrl);
      if (fallback) return fallback;
    }
    const accept = request.headers.get('Accept') || '';
    if (request.mode === 'navigate' || accept.includes('text/html')) {
      return caches.match('/offline.php') ||
             new Response('Offline', { status: 503 });
    }
    return new Response(
      JSON.stringify({ error: 'offline' }),
      { status: 503, headers: { 'Content-Type': 'application/json' } }
    );
  }
}

/* ── Cache-First ─────────────────────────────────────────────────── */
async function cacheFirst(request, cacheName) {
  const cache  = await caches.open(cacheName);
  const cached = await cache.match(request);
  if (cached) return cached;
  try {
    const networkRes = await fetch(request);
    if (networkRes && networkRes.status === 200 && networkRes.type !== 'error') {
      cache.put(request, networkRes.clone());
    }
    return networkRes;
  } catch (_) {
    return new Response('', { status: 503 });
  }
}

/* ── Push Notifications ──────────────────────────────────────────── */
self.addEventListener('push', event => {
  /* Data may be empty (empty-body VAPID push) — show branded notification */
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (_) {}

  const title   = data.title || 'आकाश सहकारी';
  const body    = data.body  || 'नयाँ सूचना छ — Member Portal खोल्नुहोस्।';
  const options = {
    body,
    icon:             '/assets/images/icon-192x192.png',
    badge:            '/assets/images/badge-72x72.png',
    tag:              data.tag || 'coop-notification',
    requireInteraction: true,   /* stays visible until dismissed */
    vibrate:          [200, 100, 200],
    data: {
      url: data.url || '/member/notifications.php',
    },
    actions: [
      { action: 'view',    title: 'हेर्नुहोस्' },
      { action: 'dismiss', title: 'पछि'        },
    ],
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();

  if (event.action === 'dismiss') return;

  const target = (event.notification.data && event.notification.data.url)
                  ? event.notification.data.url
                  : '/member/notifications.php';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      /* Focus existing tab if already open */
      for (const c of list) {
        if (c.url.includes('/member/') && 'focus' in c) {
          c.navigate(target);
          return c.focus();
        }
      }
      if (clients.openWindow) return clients.openWindow(target);
    })
  );
});

/* ── Skip-waiting (from update bar) ─────────────────────────────── */
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});

console.log('[SW] Aakash Cooperative v3 — member offline support active');
