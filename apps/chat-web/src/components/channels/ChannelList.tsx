import { useState } from 'react';
import { useApp } from '../../store';
import { ChannelListItem } from './ChannelListItem';
import { NewDmModal } from '../conversations/NewDmModal';
import { NewChannelModal } from './NewChannelModal';
import type { PresenceStatus } from '../../types';

/** Human-readable label for call-related presence states. */
const PRESENCE_LABELS: Partial<Record<PresenceStatus, string>> = {
  ringing: 'Wird angerufen …',
  in_call: 'Im Gespräch',
  dnd: 'Nicht stören',
};

export function ChannelList() {
  const { state, dispatch } = useApp();
  const [showNewDm, setShowNewDm] = useState(false);
  const [showNewChannel, setShowNewChannel] = useState(false);

  return (
    <>
      <div className="section-header">
        <span>Channels</span>
        {(state.spaceRole === 'admin' || state.spaceRole === 'owner') && (
          <button className="add-btn" title="Add channel" onClick={() => setShowNewChannel(true)}>
            +
          </button>
        )}
      </div>
      {showNewChannel && <NewChannelModal onClose={() => setShowNewChannel(false)} />}
      <ul className="channel-list">
        {state.channels.map((ch) => (
          <ChannelListItem
            key={ch.id}
            channel={ch}
            active={ch.id === state.activeChannelId}
            unreadCount={state.unread.channels[ch.id] ?? 0}
            onClick={() =>
              dispatch({ type: 'SET_ACTIVE_CHANNEL', channelId: ch.id })
            }
          />
        ))}
      </ul>

      <div className="section-header">
        <span>Direct Chats</span>
        <button className="add-btn" title="New direct message" onClick={() => setShowNewDm(true)}>
          +
        </button>
      </div>
      {showNewDm && <NewDmModal onClose={() => setShowNewDm(false)} />}
      <ul className="channel-list">
        {state.conversations
          .slice()
          .sort((a, b) => {
            const me = state.user!.id;
            const aIsSelf = a.users.length === 1 && a.users[0].id === me;
            const bIsSelf = b.users.length === 1 && b.users[0].id === me;
            if (aIsSelf && !bIsSelf) return -1;
            if (!aIsSelf && bIsSelf) return 1;
            return 0;
          })
          .map((conv) => {
          const me = state.user!.id;
          const isSelf = conv.users.length === 1 && conv.users[0].id === me;
          const other = isSelf ? conv.users[0] : (conv.users.find((u) => u.id !== me) ?? conv.users[0]);
          if (!other) return null;

          if (isSelf) {
            return (
              <li
                key={conv.id}
                className={`dm-item${
                  conv.id === state.activeConversationId ? ' active' : ''
                }${(state.unread.conversations[conv.id] ?? 0) > 0 ? ' unread' : ''}`}
                onClick={() =>
                  dispatch({
                    type: 'SET_ACTIVE_CONVERSATION',
                    conversationId: conv.id,
                  })
                }
              >
                <div className="dm-avatar dm-avatar--saved">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M5 2h14a1 1 0 0 1 1 1v19.143a.5.5 0 0 1-.766.424L12 18.03l-7.234 4.536A.5.5 0 0 1 4 22.143V3a1 1 0 0 1 1-1z" />
                  </svg>
                </div>
                <div className="dm-label">
                  <span className="dm-name">Saved Messages</span>
                </div>
                {(state.unread.conversations[conv.id] ?? 0) > 0 && (
                  <span className="unread-badge">
                    {state.unread.conversations[conv.id] > 99 ? '99+' : state.unread.conversations[conv.id]}
                  </span>
                )}
              </li>
            );
          }

          const initials = other.display_name
            .split(' ')
            .map((n) => n[0])
            .join('')
            .slice(0, 2);
          const presence = state.presence[other.id] ?? other.status ?? 'offline';
          const presenceLabel = PRESENCE_LABELS[presence];
          return (
            <li
              key={conv.id}
              className={`dm-item${
                conv.id === state.activeConversationId ? ' active' : ''
              }${(state.unread.conversations[conv.id] ?? 0) > 0 ? ' unread' : ''}`}
              onClick={() =>
                dispatch({
                  type: 'SET_ACTIVE_CONVERSATION',
                  conversationId: conv.id,
                })
              }
            >
              <div
                className="dm-avatar"
                style={{ background: other.avatar_color }}
              >
                {initials}
                <span className={`presence-dot ${presence}`} />
              </div>
              <div className="dm-label">
                <span className="dm-name">{other.display_name}</span>
                {presenceLabel && <span className="dm-presence-label">{presenceLabel}</span>}
              </div>
              {(state.unread.conversations[conv.id] ?? 0) > 0 && (
                <span className="unread-badge">
                  {state.unread.conversations[conv.id] > 99 ? '99+' : state.unread.conversations[conv.id]}
                </span>
              )}
            </li>
          );
        })}
      </ul>
    </>
  );
}

