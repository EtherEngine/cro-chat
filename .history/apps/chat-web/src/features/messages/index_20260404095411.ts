import { useCallback, useRef } from 'react';
import { useApp } from '../../store';
import { api } from '../../api/client';
import { usePolling } from '../../hooks/usePolling';
import type { Message } from '../../types';

const POLL_INTERVAL = 2_500;
const HIDDEN_FACTOR = 4;

/**
 * Polls for new messages in the currently active channel or conversation.
 * Uses cursor-based `after` parameter to fetch only deltas.
 */
export function useMessagePolling() {
  const { state, dispatch } = useApp();
  const inflight = useRef(false);

  const poll = useCallback(async () => {
    if (inflight.current) return;
    const { activeChannelId, activeConversationId, messages } = state;
    if (!activeChannelId && !activeConversationId) return;

    const lastId = messages.length > 0 ? messages[messages.length - 1].id : undefined;

    inflight.current = true;
    try {
      let incoming: Message[] = [];
      if (activeChannelId) {
        const res = await api.messages.forChannel(activeChannelId, {
          after: lastId,
          limit: 50,
        });
        incoming = res.messages;
      } else if (activeConversationId) {
        const res = await api.messages.forConversation(activeConversationId, {
          after: lastId,
          limit: 50,
        });
        incoming = res.messages;
      }

      if (incoming.length > 0) {
        dispatch({ type: 'APPEND_MESSAGES', messages: incoming });
        // Mark the latest incoming message as read
        const newest = incoming[incoming.length - 1];
        if (activeChannelId) {
          api.unread.markChannelRead(activeChannelId, newest.id).catch(() => {});
          dispatch({ type: 'CLEAR_UNREAD_CHANNEL', channelId: activeChannelId });
        } else if (activeConversationId) {
          api.unread.markConversationRead(activeConversationId, newest.id).catch(() => {});
          dispatch({ type: 'CLEAR_UNREAD_CONVERSATION', conversationId: activeConversationId });
        }
      }
    } catch {
      // Silently ignore — will retry next tick
    } finally {
      inflight.current = false;
    }
  }, [state.activeChannelId, state.activeConversationId, state.messages, dispatch]);

  usePolling(poll, {
    interval: POLL_INTERVAL,
    hiddenFactor: HIDDEN_FACTOR,
    enabled: !!(state.activeChannelId || state.activeConversationId),
  });
}