import { useApp } from '../../store';
import { ChannelListItem } from './ChannelListItem';

export function ChannelList() {
  const { state, dispatch } = useApp();

  return (
    <>
      <div className="section-header">
        <span>Channels</span>
        <button className="add-btn" title="Add channel">
          +
        </button>
      </div>
      <ul className="channel-list">
        {state.channels.map((ch) => (
          <ChannelListItem
            key={ch.id}
            channel={ch}
            active={ch.id === state.activeChannelId}
            onClick={() =>
              dispatch({ type: 'SET_ACTIVE_CHANNEL', channelId: ch.id })
            }
          />
        ))}
      </ul>

      <div className="section-header">
        <span>Direct Chats</span>
        <button className="add-btn" title="New direct message">
          +
        </button>
      </div>
      <ul className="channel-list">
        {state.conversations.map((conv) => {
          const other = conv.users[0];
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
              }`}
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
            </li>
          );
        })}
      </ul>
    </>
  );
}

