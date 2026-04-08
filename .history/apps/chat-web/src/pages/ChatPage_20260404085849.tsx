import { useEffect } from 'react';
import { useApp } from '../store';
import { api } from '../api/client';
import { AppLayout } from '../layouts/AppLayout';

export function ChatPage() {
  const { state, dispatch } = useApp();

  useEffect(() => {
    api.channels.list().then(({ channels }) => {
      dispatch({ type: 'SET_CHANNELS', channels });
      if (channels.length > 0) {
        dispatch({ type: 'SET_ACTIVE_CHANNEL', channelId: channels[0].id });
      }
    });
    api.conversations.list().then(({ conversations }) => {
      dispatch({ type: 'SET_CONVERSATIONS', conversations });
    });
  }, [dispatch]);

  useEffect(() => {
    if (!state.activeChannelId) return;
    api.messages.forChannel(state.activeChannelId).then(({ messages }) => {
      dispatch({ type: 'SET_MESSAGES', messages });
    });
    api.channels.members(state.activeChannelId).then(({ members }) => {
      dispatch({ type: 'SET_MEMBERS', members });
    });
  }, [state.activeChannelId, dispatch]);

  useEffect(() => {
    if (!state.activeConversationId) return;
    api.messages.forConversation(state.activeConversationId).then(({ messages }) => {
      dispatch({ type: 'SET_MESSAGES', messages });
    });
  }, [state.activeConversationId, dispatch]);

  return <AppLayout />;
}

