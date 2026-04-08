/// <reference lib="webworker" />

/**
 * crø Service Worker
 *
 * Handles:
 * 1. Push notifications (display + click handling with deep links)
 * 2. Offline caching (app shell + API responses)
 * 3. Background sync for failed outgoing requests
 */

const SW_VERSION = '1.0.0';
const CACHE_NAME = `cro-cache-v${SW_VERSION}`;
const API_CACHE_NAME = `cro-api-cache-v${SW_VERSION}`;

/** App shell files to precache */
const PRECACHE_URLS = [
  '/',
  '/index.html',
  '/manifest.json',
];

/** API paths that should be cached for offline access */
const CACHEABLE_API_PATHS = [
  '/api/auth/me',
  '/api/spaces',
  '/api/presence/status',
];

/** API paths for read endpoints (GET) that use stale-while-revalidate */
const SWR_API_PATTERNS = [
  /\/api\/spaces\/\d+\/channels$/,
  /\/api\/channels\/\d+\/messages/,
  /\/api\/conversations\/\d+\/messages/,
  /\/api\/conversations$/,
  /\/api\/unread$/,
  /\/api\/channels\/\d+\/members$/,
];

// ── Install ─────────────────────────────────────────────────────

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS))
  );
  // Activate immediately
  self.skipWaiting();
});

// ── Activate ────────────────────────────────────────────────────

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== CACHE_NAME && key !== API_CACHE_NAME)
          .map((key) => caches.delete(key))
      )
    )
  );
  // Claim all clients immediately
  self.clients.claim();
});

// ── Fetch (offline strategy) ────────────────────────────────────

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests for caching (they go through background sync)
  if (request.method !== 'GET') {
    // For POST/PUT/DELETE, try network and queue on failure
    if (url.pathname.startsWith('/api/') && request.method !== 'GET') {
      event.respondWith(networkWithOfflineQueue(request));
    }
    return;
  }

  // API requests: stale-while-revalidate for known patterns
  if (url.pathname.startsWith('/api/')) {
    const isSWR = SWR_API_PATTERNS.some((p) => p.test(url.pathname));
    const isCacheable = CACHEABLE_API_PATHS.some((p) => url.pathname.endsWith(p));

    if (isSWR || isCacheable) {
      event.respondWith(staleWhileRevalidate(request));
      return;
    }

    // Other API: network-first
    event.respondWith(networkFirst(request));
    return;
  }

  // App shell: cache-first
  event.respondWith(cacheFirst(request));
});

// ── Caching strategies ──────────────────────────────────────────

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;

  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    // Offline fallback
    return caches.match('/index.html') || new Response('Offline', { status: 503 });
  }
}

async function networkFirst(request) {
  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(API_CACHE_NAME);
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    const cached = await caches.match(request);
    return cached || new Response(JSON.stringify({ error: 'offline' }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' },
    });
  }
}

async function staleWhileRevalidate(request) {
  const cache = await caches.open(API_CACHE_NAME);
  const cached = await cache.match(request);

  const fetchPromise = fetch(request).then((response) => {
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  }).catch(() => null);

  // Return cached immediately, update in background
  if (cached) {
    // Fire and forget the revalidation
    fetchPromise;
    return cached;
  }

  // No cache — must wait for network
  const response = await fetchPromise;
  if (response) return response;

  return new Response(JSON.stringify({ error: 'offline' }), {
    status: 503,
    headers: { 'Content-Type': 'application/json' },
  });
}

// ── Background sync for offline mutations ───────────────────────

const OUTBOX_STORE = 'cro-outbox';

async function networkWithOfflineQueue(request) {
  try {
    return await fetch(request.clone());
  } catch {
    // Queue for later
    await queueRequest(request);
    return new Response(JSON.stringify({ queued: true, offline: true }), {
      status: 202,
      headers: { 'Content-Type': 'application/json' },
    });
  }
}

async function queueRequest(request) {
  // Store in IDB via message to client (SW can't use IDB directly in all cases)
  const clients = await self.clients.matchAll({ type: 'window' });
  const body = await request.text();

  const serialized = {
    url: request.url,
    method: request.method,
    headers: Object.fromEntries(request.headers.entries()),
    body,
    timestamp: Date.now(),
  };

  for (const client of clients) {
    client.postMessage({ type: 'QUEUE_REQUEST', request: serialized });
  }
}

// Sync event: replay queued requests
self.addEventListener('sync', (event) => {
  if (event.tag === 'cro-outbox-sync') {
    event.waitUntil(replayOutbox());
  }
});

async function replayOutbox() {
  const clients = await self.clients.matchAll({ type: 'window' });
  for (const client of clients) {
    client.postMessage({ type: 'REPLAY_OUTBOX' });
  }
}

// ── Push notifications ──────────────────────────────────────────

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data?.json() || {};
  } catch {
    data = { title: 'crø', body: event.data?.text() || 'Neue Benachrichtigung' };
  }

  const title = data.title || 'crø';
  const options = {
    body: data.body || '',
    icon: data.icon || '/icons/icon-192.png',
    badge: data.badge || '/icons/badge-72.png',
    tag: data.tag || 'cro-notification',
    renotify: true,
    data: data.data || {},
    actions: [
      { action: 'open', title: 'Öffnen' },
      { action: 'dismiss', title: 'Schließen' },
    ],
    vibrate: [100, 50, 100],
    requireInteraction: data.data?.type === 'dm' || data.data?.type === 'mention',
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

// ── Notification click → deep link ──────────────────────────────

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  if (event.action === 'dismiss') return;

  const deepLink = event.notification.data?.deep_link || '/';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      // Try to focus an existing window
      for (const client of windowClients) {
        const clientUrl = new URL(client.url);
        if (clientUrl.origin === self.location.origin) {
          client.postMessage({
            type: 'DEEP_LINK',
            path: deepLink,
            notification_id: event.notification.data?.notification_id,
          });
          return client.focus();
        }
      }
      // No existing window — open a new one
      return self.clients.openWindow(deepLink);
    })
  );
});

// ── Notification close ──────────────────────────────────────────

self.addEventListener('notificationclose', (event) => {
  // Could send analytics or mark-as-seen here
});

// ── Message handling (from main thread) ─────────────────────────

self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
