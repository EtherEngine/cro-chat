/**
 * Service Worker registration + Push subscription management.
 *
 * Handles:
 * - SW registration and updates
 * - Push notification permission + subscription
 * - Offline outbox queueing via IndexedDB
 * - SW message relay (deep links, outbox replay)
 */

import { api } from '../api/client';

// ── Service Worker Registration ────────────────────────────────

let swRegistration: ServiceWorkerRegistration | null = null;

export async function registerServiceWorker(): Promise<ServiceWorkerRegistration | null> {
  if (!('serviceWorker' in navigator)) {
    console.warn('[sw] Service workers not supported');
    return null;
  }

  // Don't register SW in development — it intercepts Vite's module requests
  const isDev = location.hostname === 'localhost' && location.port === '5173';
  if (isDev) {
    // Unregister any previously registered SW to fix broken state
    const regs = await navigator.serviceWorker.getRegistrations();
    for (const reg of regs) {
      await reg.unregister();
      console.log('[sw] Unregistered dev SW');
    }
    return null;
  }

  try {
    const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    swRegistration = reg;

    // Check for updates periodically
    setInterval(() => reg.update(), 60 * 60 * 1000); // hourly

    // Handle updates
    reg.addEventListener('updatefound', () => {
      const newWorker = reg.installing;
      if (!newWorker) return;

      newWorker.addEventListener('statechange', () => {
        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
          // New version available — activate immediately
          newWorker.postMessage({ type: 'SKIP_WAITING' });
        }
      });
    });

    console.log('[sw] Registered successfully');
    return reg;
  } catch (err) {
    console.error('[sw] Registration failed:', err);
    return null;
  }
}

export function getRegistration(): ServiceWorkerRegistration | null {
  return swRegistration;
}

// ── Push Notifications ─────────────────────────────────────────

/**
 * Check if push notifications are supported and permitted.
 */
export function pushSupported(): boolean {
  return 'PushManager' in window && 'Notification' in window;
}

export function pushPermission(): NotificationPermission {
  return Notification.permission;
}

/**
 * Request push notification permission and subscribe to push.
 * Returns the subscription details to send to the backend.
 */
export async function subscribeToPush(spaceId: number): Promise<PushSubscription | null> {
  if (!pushSupported()) return null;

  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    console.warn('[push] Permission denied');
    return null;
  }

  const reg = swRegistration || await registerServiceWorker();
  if (!reg) return null;

  // Get the VAPID public key from the server
  const { public_key: vapidKey } = await api.push.vapidKey(spaceId);

  // Convert VAPID key from URL-safe Base64 to Uint8Array
  const applicationServerKey = urlBase64ToUint8Array(vapidKey).buffer as ArrayBuffer;

  // Subscribe
  const subscription = await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey,
  });

  return subscription;
}

/**
 * Register a push subscription with the backend.
 */
export async function registerPushDevice(
  spaceId: number,
  subscription: PushSubscription,
  deviceId: string,
  platform: 'web' | 'desktop' = 'web'
): Promise<void> {
  const keys = subscription.toJSON().keys || {};
  const deviceName = getDeviceName();

  await api.push.register({
    device_id: deviceId,
    space_id: spaceId,
    platform,
    device_name: deviceName,
    endpoint: subscription.endpoint,
    p256dh_key: keys.p256dh || '',
    auth_key: keys.auth || '',
  });
}

/**
 * Unsubscribe from push notifications.
 */
export async function unsubscribeFromPush(subscriptionId: number): Promise<void> {
  const reg = swRegistration;
  if (reg) {
    const sub = await reg.pushManager.getSubscription();
    if (sub) {
      await sub.unsubscribe();
    }
  }

  await api.push.unregister(subscriptionId);
}

/**
 * Check if the user currently has an active push subscription.
 */
export async function getCurrentPushSubscription(): Promise<PushSubscription | null> {
  const reg = swRegistration || await navigator.serviceWorker?.getRegistration();
  if (!reg) return null;
  return reg.pushManager.getSubscription();
}

// ── Deep Link Handling ─────────────────────────────────────────

type DeepLinkHandler = (path: string, notificationId?: number) => void;
const deepLinkHandlers = new Set<DeepLinkHandler>();

export function onDeepLink(handler: DeepLinkHandler): () => void {
  deepLinkHandlers.add(handler);

  // Listen for SW messages
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', handleSwMessage);
  }

  return () => {
    deepLinkHandlers.delete(handler);
  };
}

function handleSwMessage(event: MessageEvent) {
  const { data } = event;

  if (data?.type === 'DEEP_LINK') {
    for (const handler of deepLinkHandlers) {
      handler(data.path, data.notification_id);
    }
  }

  if (data?.type === 'QUEUE_REQUEST') {
    queueOfflineRequest(data.request);
  }

  if (data?.type === 'REPLAY_OUTBOX') {
    replayOfflineOutbox();
  }
}

// ── Offline Outbox (IndexedDB) ─────────────────────────────────

const DB_NAME = 'cro-offline';
const DB_VERSION = 1;
const OUTBOX_STORE = 'outbox';

function openDB(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(OUTBOX_STORE)) {
        db.createObjectStore(OUTBOX_STORE, { keyPath: 'id', autoIncrement: true });
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

interface QueuedRequest {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: string;
  timestamp: number;
}

export async function queueOfflineRequest(req: QueuedRequest): Promise<void> {
  const db = await openDB();
  const tx = db.transaction(OUTBOX_STORE, 'readwrite');
  tx.objectStore(OUTBOX_STORE).add(req);
  await new Promise<void>((resolve, reject) => {
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

export async function replayOfflineOutbox(): Promise<void> {
  const db = await openDB();
  const tx = db.transaction(OUTBOX_STORE, 'readwrite');
  const store = tx.objectStore(OUTBOX_STORE);

  const items: (QueuedRequest & { id: number })[] = await new Promise((resolve, reject) => {
    const req = store.getAll();
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });

  for (const item of items) {
    try {
      await fetch(item.url, {
        method: item.method,
        headers: item.headers,
        body: item.body,
        credentials: 'include',
      });
      // Success — remove from outbox
      const delTx = db.transaction(OUTBOX_STORE, 'readwrite');
      delTx.objectStore(OUTBOX_STORE).delete(item.id);
    } catch {
      // Still offline — leave in outbox
      break;
    }
  }
}

export async function getOutboxCount(): Promise<number> {
  const db = await openDB();
  const tx = db.transaction(OUTBOX_STORE, 'readonly');
  return new Promise((resolve, reject) => {
    const req = tx.objectStore(OUTBOX_STORE).count();
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

// ── Network Status ─────────────────────────────────────────────

type NetworkHandler = (online: boolean) => void;
const networkHandlers = new Set<NetworkHandler>();

export function onNetworkChange(handler: NetworkHandler): () => void {
  networkHandlers.add(handler);

  const onOnline = () => {
    for (const h of networkHandlers) h(true);
    // Replay outbox when coming back online
    replayOfflineOutbox().catch(() => {});
    // Request background sync
    if (swRegistration && 'sync' in swRegistration) {
      (swRegistration as any).sync.register('cro-outbox-sync').catch(() => {});
    }
  };

  const onOffline = () => {
    for (const h of networkHandlers) h(false);
  };

  window.addEventListener('online', onOnline);
  window.addEventListener('offline', onOffline);

  return () => {
    networkHandlers.delete(handler);
    window.removeEventListener('online', onOnline);
    window.removeEventListener('offline', onOffline);
  };
}

export function isOnline(): boolean {
  return navigator.onLine;
}

// ── Device ID ──────────────────────────────────────────────────

const DEVICE_ID_KEY = 'cro-device-id';

/**
 * Get a stable device ID (persisted in localStorage).
 */
export function getDeviceId(): string {
  let id = localStorage.getItem(DEVICE_ID_KEY);
  if (!id) {
    id = crypto.randomUUID();
    localStorage.setItem(DEVICE_ID_KEY, id);
  }
  return id;
}

/**
 * Get a human-readable device name.
 */
export function getDeviceName(): string {
  const ua = navigator.userAgent;
  if (ua.includes('Windows')) return `Chrome on Windows`;
  if (ua.includes('Mac')) return `Safari on macOS`;
  if (ua.includes('Linux')) return `Browser on Linux`;
  if (ua.includes('Android')) return `Chrome on Android`;
  if (ua.includes('iPhone') || ua.includes('iPad')) return `Safari on iOS`;
  return 'Unknown Device';
}

// ── Helpers ────────────────────────────────────────────────────

function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; i++) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}
