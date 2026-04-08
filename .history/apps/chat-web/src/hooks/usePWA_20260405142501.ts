import { useEffect, useRef, useState, useCallback } from 'react';
import { useApp } from '../store';
import { api } from '../api/client';
import {
  registerServiceWorker,
  subscribeToPush,
  registerPushDevice,
  getDeviceId,
  onDeepLink,
  onNetworkChange,
  isOnline,
  getOutboxCount,
  replayOfflineOutbox,
  pushSupported,
  pushPermission,
} from '../pwa/serviceWorker';

/**
 * Initialize service worker, push subscription, and network monitoring.
 * Should be called once at app root level.
 */
export function usePWA() {
  const { state, dispatch } = useApp();
  const [online, setOnline] = useState(() => isOnline());
  const [outboxCount, setOutboxCount] = useState(0);
  const [pushEnabled, setPushEnabled] = useState(false);
  const initializedRef = useRef(false);

  // Register service worker on mount
  useEffect(() => {
    if (initializedRef.current) return;
    initializedRef.current = true;
    registerServiceWorker();
  }, []);

  // Monitor network status
  useEffect(() => {
    return onNetworkChange((isNowOnline) => {
      setOnline(isNowOnline);
      if (isNowOnline) {
        // When coming back online, refresh data
        getOutboxCount().then(setOutboxCount).catch(() => {});
      }
    });
  }, []);

  // Check push subscription state
  useEffect(() => {
    if (pushSupported()) {
      setPushEnabled(pushPermission() === 'granted');
    }
  }, []);

  // Poll outbox count while offline
  useEffect(() => {
    if (online) {
      getOutboxCount().then(setOutboxCount).catch(() => {});
      return;
    }

    const interval = setInterval(() => {
      getOutboxCount().then(setOutboxCount).catch(() => {});
    }, 5000);

    return () => clearInterval(interval);
  }, [online]);

  // Enable push notifications
  const enablePush = useCallback(async () => {
    if (!state.spaceId) return false;

    const subscription = await subscribeToPush(state.spaceId);
    if (!subscription) return false;

    const deviceId = getDeviceId();
    const platform = (window as any).__TAURI__ ? 'desktop' : 'web';
    await registerPushDevice(state.spaceId, subscription, deviceId, platform as 'web' | 'desktop');

    setPushEnabled(true);
    return true;
  }, [state.spaceId]);

  // Retry outbox manually
  const retryOutbox = useCallback(async () => {
    await replayOfflineOutbox();
    const count = await getOutboxCount();
    setOutboxCount(count);
  }, []);

  return { online, outboxCount, pushEnabled, enablePush, retryOutbox };
}

/**
 * Handle deep links from push notification clicks and URL navigation.
 * Parses paths like /channel/:id/message/:id, /conversation/:id, etc.
 */
export function useDeepLinks() {
  const { state, dispatch } = useApp();

  useEffect(() => {
    const unsub = onDeepLink((path, notificationId) => {
      navigateToDeepLink(path, dispatch);

      // Mark notification as read
      if (notificationId) {
        api.notifications.markRead(notificationId).catch(() => {});
      }
    });

    return unsub;
  }, [dispatch]);

  // Also handle initial URL on page load
  useEffect(() => {
    const hash = window.location.hash.slice(1);
    if (hash) {
      navigateToDeepLink(hash, dispatch);
    }
  }, [dispatch]);

  // Listen for hash changes (deep links via URL)
  useEffect(() => {
    const handler = () => {
      const hash = window.location.hash.slice(1);
      if (hash) {
        navigateToDeepLink(hash, dispatch);
      }
    };
    window.addEventListener('hashchange', handler);
    return () => window.removeEventListener('hashchange', handler);
  }, [dispatch]);
}

/**
 * Parse a deep link path and dispatch the appropriate navigation action.
 */
function navigateToDeepLink(path: string, dispatch: any) {
  // /channel/:channelId
  const channelMatch = path.match(/^\/channel\/(\d+)/);
  if (channelMatch) {
    const channelId = parseInt(channelMatch[1], 10);
    dispatch({ type: 'SET_ACTIVE_CHANNEL', channelId });

    // /channel/:id/message/:messageId
    const msgMatch = path.match(/\/message\/(\d+)/);
    if (msgMatch) {
      dispatch({ type: 'JUMP_TO_MESSAGE', messageId: parseInt(msgMatch[1], 10) });
    }
    return;
  }

  // /conversation/:conversationId
  const convMatch = path.match(/^\/conversation\/(\d+)/);
  if (convMatch) {
    const conversationId = parseInt(convMatch[1], 10);
    dispatch({ type: 'SET_ACTIVE_CONVERSATION', conversationId });

    const msgMatch = path.match(/\/message\/(\d+)/);
    if (msgMatch) {
      dispatch({ type: 'JUMP_TO_MESSAGE', messageId: parseInt(msgMatch[1], 10) });
    }
    return;
  }
}

/**
 * Build a deep link URL for sharing/bookmarking.
 */
export function buildDeepLink(options: {
  channelId?: number;
  conversationId?: number;
  messageId?: number;
  threadId?: number;
}): string {
  const base = window.location.origin;
  let path = '#';

  if (options.channelId) {
    path += `/channel/${options.channelId}`;
  } else if (options.conversationId) {
    path += `/conversation/${options.conversationId}`;
  }

  if (options.messageId) {
    path += `/message/${options.messageId}`;
  }

  if (options.threadId) {
    path += `?thread=${options.threadId}`;
  }

  return base + path;
}

/**
 * Notification API client namespace extension.
 */
export const notifications = {
  markRead: (notificationId: number) =>
    fetch(`/api/notifications/${notificationId}/read`, {
      method: 'POST',
      credentials: 'include',
    }),
};
