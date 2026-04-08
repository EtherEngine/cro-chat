import { useState } from 'react';
import { useApp } from '../../store';
import { ChannelListItem } from './ChannelListItem';
import { NewDmModal } from '../conversations/NewDmModal';
import { NewChannelModal } from './NewChannelModal';

export function ChannelList() {
  const { state, dispatch } = useApp();
  const [showNewDm, setShowNewDm] = useState(false);
  const [showNewChannel, setShowNewChannel] = useState(false);

  return (
    <>
      <div className="section-header">
        <span>Channels</span>
        <button className="add-btn" title="Add channel" onClick={() => setShowNewChannel(true)}>
          +
        </button>
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
        {state.conversations.map((conv) => {
          const me = state.user!.id;
          const other = conv.users.find((u) => u.id !== me) ?? conv.users[0];
          if (!other) return null;
          const initials = other.display_name
            .split(' ')
            .map((n) => n[0])
            .join('')
            .slice(0, 2);
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
              </div>
              <span className="dm-name">{other.display_name}</span>
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

