import { useEffect, useRef, useCallback, useState } from 'react';
import { useApp } from '../store';
import { api } from '../api/client';
import { RealtimeClient, type RealtimeEvent } from './socket';
import type { Message, PresenceStatus, AppNotification } from '../types';

/** Singleton — shared across components via the hook. */
let globalClient: RealtimeClient | null = null;

function getClient(): RealtimeClient {
  if (!globalClient) {
    globalClient = new RealtimeClient();
  }
  return globalClient;
}

/** Export the singleton getter so other modules (useCall) can share it. */
export function getSharedRealtimeClient(): RealtimeClient {
  return getClient();
}

/** For testing only — inject a mock client instead of the real singleton. */
export function setSharedRealtimeClient(client: RealtimeClient): void {
  globalClient = client;
}

/**
 * Main realtime hook.
 * - Connects WebSocket on mount, disconnects on unmount.
 * - Manages room subscriptions based on active channel/conversation.
 * - Dispatches incoming domain events into the store.
 * - Performs delta resync via REST after reconnect.
 * - Monitors network status and forces reconnect on online.
 */
export function useRealtime() {
  const { state, dispatch } = useApp();
  const { activeChannelId, activeConversationId, messages } = state;
  const [wsConnected, setWsConnected] = useState(false);

  // Track last known message ID for delta resync
  const lastMessageIdRef = useRef<number | undefined>();
  useEffect(() => {
    if (messages.length > 0) {
      lastMessageIdRef.current = messages[messages.length - 1].id;
    }
  }, [messages]);

  // ── Connect on mount ──
  useEffect(() => {
    const client = getClient();
    client.connect();

    // Track connection state
    const unsubConn = client.onConnection((connected) => {
      setWsConnected(connected);
    });

    return () => {
      unsubConn();
      client.disconnect();
      globalClient = null;
    };
  }, []);

  // ── Network-aware reconnect ──
  useEffect(() => {
    const onOnline = () => {
      const client = getClient();
      if (!client.isConnected()) {
        console.log('[realtime] Network online — forcing reconnect');
        client.forceReconnect();
      }
    };

    window.addEventListener('online', onOnline);
    return () => window.removeEventListener('online', onOnline);
  }, []);

  // ── Handle incoming events ──
  const handleEvent = useCallback(
    (event: RealtimeEvent) => {
      const { type, payload, room } = event;

      // Only process events for the currently active room
      const activeRoom = activeChannelId
        ? `channel:${activeChannelId}`
        : activeConversationId
          ? `conversation:${activeConversationId}`
          : null;

      if (type === 'message.created' && room === activeRoom) {
        dispatch({ type: 'APPEND_MESSAGES', messages: [payload as Message] });
      } else if (type === 'message.updated' && room === activeRoom) {
        dispatch({ type: 'APPEND_MESSAGES', messages: [payload as Message] });
      } else if (type === 'message.deleted' && room === activeRoom) {
        // payload = { id: number }, mark as deleted in store
        dispatch({
          type: 'APPEND_MESSAGES',
          messages: [{
            ...payload,
            body: null,
            deleted_at: new Date().toISOString(),
          } as Message],
        });
      }

      // If it's a message event for a non-active room, it means an unread
      if (type === 'message.created' && room !== activeRoom) {
        // Refresh unread counts
        api.unread.counts().then((unread) => {
          dispatch({ type: 'SET_UNREAD', unread });
        }).catch(() => {});
      }

      // ── Instant presence updates from call-state changes ──
      if (type === 'presence.changed') {
        const p = payload as { user_id: number; status: PresenceStatus };
        if (p.user_id && p.status) {
          dispatch({ type: 'SET_PRESENCE', presence: { [p.user_id]: p.status } });
        }
      }

      // ── Notifications (delivered to user:{id} room) ──
      if (type === 'notification.created') {
        const notif = payload as AppNotification;
        dispatch({ type: 'ADD_NOTIFICATION', notification: notif });

        // Browser notification for call events when tab is not focused
        if (document.visibilityState !== 'visible' && 'Notification' in window && Notification.permission === 'granted') {
          const callTypes = ['call_incoming', 'call_missed', 'call_rejected'];
          if (callTypes.includes(notif.type)) {
            const titles: Record<string, string> = {
              call_incoming: `📞 Eingehender Anruf von ${notif.actor.display_name}`,
              call_missed: `📵 Verpasster Anruf von ${notif.actor.display_name}`,
              call_rejected: `🚫 ${notif.actor.display_name} hat den Anruf abgelehnt`,
            };
            new Notification(titles[notif.type] ?? 'Anruf', {
              icon: '/icons/icon-192.png',
              tag: `call-notif-${notif.id}`,
              requireInteraction: notif.type === 'call_incoming',
            });
          }
        }
      }
    },
    [activeChannelId, activeConversationId, dispatch],
  );

  useEffect(() => {
    const client = getClient();
    const unsub = client.onEvent(handleEvent);
    return unsub;
  }, [handleEvent]);

  // ── Manage room subscriptions ──
  useEffect(() => {
    const client = getClient();
    const rooms: string[] = [];

    // Subscribe to the space room for presence broadcasts
    if (state.spaceId) {
      rooms.push(`space:${state.spaceId}`);
    }

    // Subscribe to user-scoped room for notifications
    if (state.user?.id) {
      rooms.push(`user:${state.user.id}`);
    }

    if (activeChannelId) {
      rooms.push(`channel:${activeChannelId}`);
    }
    if (activeConversationId) {
      rooms.push(`conversation:${activeConversationId}`);
    }

    client.setSubscriptions(rooms);
  }, [state.spaceId, state.user?.id, activeChannelId, activeConversationId]);

  // ── Delta resync after reconnect ──
  // When the WebSocket reconnects, fetch any messages that were missed.
  const prevConnectedRef = useRef(false);

  useEffect(() => {
    const wasDisconnected = !prevConnectedRef.current;
    prevConnectedRef.current = wsConnected;

    // Reconnect detected: was disconnected, now connected, and we have a last message ID
    if (wsConnected && wasDisconnected && lastMessageIdRef.current) {
      const lastId = lastMessageIdRef.current;

      const fetchMissed = async () => {
        try {
          let incoming: Message[] = [];
          if (activeChannelId) {
            const res = await api.messages.forChannel(activeChannelId, { after: lastId, limit: 50 });
            incoming = res.messages;
          } else if (activeConversationId) {
            const res = await api.messages.forConversation(activeConversationId, { after: lastId, limit: 50 });
            incoming = res.messages;
          }

          if (incoming.length > 0) {
            dispatch({ type: 'APPEND_MESSAGES', messages: incoming });
          }

          // Also refresh unread counts
          api.unread.counts().then((unread) => {
            dispatch({ type: 'SET_UNREAD', unread });
          }).catch(() => {});
        } catch {
          // Will catch up via next reconnect or visibility change
        }
      };

      fetchMissed();
    }
  }, [wsConnected, activeChannelId, activeConversationId, dispatch]);

  return { wsConnected };
}

/**
 * Hook to resync messages via REST after_id when the tab becomes visible again.
 * Complements the WebSocket for messages that arrived while disconnected.
 */
export function useResyncOnVisibility() {
  const { state, dispatch } = useApp();
  const lastIdRef = useRef<number | undefined>();

  useEffect(() => {
    if (state.messages.length > 0) {
      lastIdRef.current = state.messages[state.messages.length - 1].id;
    }
  }, [state.messages]);

  useEffect(() => {
    const onVisible = async () => {
      if (document.visibilityState !== 'visible') return;
      const lastId = lastIdRef.current;
      if (!lastId) return;

      try {
        let incoming: Message[] = [];
        if (state.activeChannelId) {
          const res = await api.messages.forChannel(state.activeChannelId, { after: lastId, limit: 50 });
          incoming = res.messages;
        } else if (state.activeConversationId) {
          const res = await api.messages.forConversation(state.activeConversationId, { after: lastId, limit: 50 });
          incoming = res.messages;
        }

        if (incoming.length > 0) {
          dispatch({ type: 'APPEND_MESSAGES', messages: incoming });
        }
      } catch {
        // Will retry on next visibility change
      }
    };

    document.addEventListener('visibilitychange', onVisible);
    return () => document.removeEventListener('visibilitychange', onVisible);
  }, [state.activeChannelId, state.activeConversationId, dispatch]);
}
