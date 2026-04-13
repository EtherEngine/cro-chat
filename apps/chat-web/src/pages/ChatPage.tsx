import { useEffect } from 'react';
import { useApp } from '../store';
import { api } from '../api/client';
import { AppLayout } from '../layouts/AppLayout';
import { usePresenceHeartbeat, usePresencePolling } from '../features/presence';
import { useRealtime, useResyncOnVisibility } from '../realtime/useRealtime';

export function ChatPage() {
  const { state, dispatch } = useApp();

  // ── Load spaces → channels → conversations on mount ──
  useEffect(() => {
    api.spaces.list().then(({ spaces }) => {
      if (spaces.length === 0) return;
      const space = spaces[0];
      dispatch({ type: 'SET_SPACE', spaceId: space.id, role: space.role });

      api.channels.list(space.id).then(({ channels }) => {
        dispatch({ type: 'SET_CHANNELS', channels });
        if (channels.length > 0) {
          dispatch({ type: 'SET_ACTIVE_CHANNEL', channelId: channels[0].id });
        }
      });

      // Load conversations (backend auto-creates "Saved Messages" self-conversation)
      api.conversations.list().then(({ conversations }) => {
        dispatch({ type: 'SET_CONVERSATIONS', conversations });
      });
    });
    // Initial unread fetch
    api.unread.counts().then((unread) => {
      dispatch({ type: 'SET_UNREAD', unread });
    }).catch(() => {});
    // Initial notifications fetch
    api.notifications.list({ limit: 30 }).then(({ notifications }) => {
      dispatch({ type: 'SET_NOTIFICATIONS', notifications });
    }).catch(() => {});
    api.notifications.unreadCount().then(({ count }) => {
      dispatch({ type: 'SET_NOTIFICATION_UNREAD', count });
    }).catch(() => {});
  }, [dispatch]);

  // ── Initial message + member load when active channel changes ──
  useEffect(() => {
    if (!state.activeChannelId) return;
    api.messages.forChannel(state.activeChannelId, { limit: 50 }).then(({ messages }) => {
      dispatch({ type: 'SET_MESSAGES', messages });
      // Mark channel as read up to the latest message
      if (messages.length > 0) {
        const lastMsg = messages[messages.length - 1];
        api.unread.markChannelRead(state.activeChannelId!, lastMsg.id).catch(() => {});
        dispatch({ type: 'CLEAR_UNREAD_CHANNEL', channelId: state.activeChannelId! });
      }
    });
    api.channels.members(state.activeChannelId).then(({ members }) => {
      dispatch({ type: 'SET_MEMBERS', members });
    });
  }, [state.activeChannelId, dispatch]);

  // ── Initial message load when active conversation changes ──
  useEffect(() => {
    if (!state.activeConversationId) return;
    api.messages.forConversation(state.activeConversationId, { limit: 50 }).then(({ messages }) => {
      dispatch({ type: 'SET_MESSAGES', messages });
      // Mark conversation as read up to the latest message
      if (messages.length > 0) {
        const lastMsg = messages[messages.length - 1];
        api.unread.markConversationRead(state.activeConversationId!, lastMsg.id).catch(() => {});
        dispatch({ type: 'CLEAR_UNREAD_CONVERSATION', conversationId: state.activeConversationId! });
      }
    });
  }, [state.activeConversationId, dispatch]);

  // ── Realtime: WebSocket for message deltas, REST for resync ──
  useRealtime();
  useResyncOnVisibility();
  usePresenceHeartbeat();
  usePresencePolling();

  return <AppLayout />;
}
