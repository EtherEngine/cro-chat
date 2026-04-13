import { useApp } from '../../store';
import type { PresenceStatus } from '../../types';
import { CallButton } from '../calls/CallButton';

const PRESENCE_LABELS: Record<PresenceStatus, string> = {
  online: 'Online',
  away: 'Abwesend',
  offline: 'Offline',
  ringing: 'Wird angerufen …',
  in_call: 'Im Gespräch',
  dnd: 'Nicht stören',
};

export function ChatHeader() {
  const { state, dispatch } = useApp();

  const channel = state.channels.find((c) => c.id === state.activeChannelId);
  const conversation = state.conversations.find(
    (c) => c.id === state.activeConversationId,
  );

  let title = '';
  let meta = '';
  let presenceStatus: PresenceStatus | undefined;

  if (channel) {
    title = channel.name;
    meta = `${channel.member_count} members`;
  } else if (conversation) {
    const me = state.user?.id;
    const isSelf = conversation.users.length === 1 && conversation.users[0].id === me;
    if (isSelf) {
      title = 'Saved Messages';
    } else {
      const other = conversation.users.find((u) => u.id !== me);
      title = other?.display_name ?? conversation.users.map((u) => u.display_name).join(', ');
      if (other) {
        presenceStatus = state.presence[other.id] ?? other.status ?? 'offline';
      }
    }
  }

  return (
    <div className="chat-header">
      <div style={{ flex: 1, minWidth: 0 }}>
        <div
          className="chat-header-title"
          onClick={() => channel && dispatch({ type: 'TOGGLE_MEMBERS' })}
        >
          {title} <span className="arrow">{'\u25B8'}</span>
        </div>
        {meta && <div className="chat-header-meta">{meta}</div>}
        {presenceStatus && (
          <div className={`chat-header-presence ${presenceStatus}`}>
            <span className={`presence-dot ${presenceStatus}`} />
            {PRESENCE_LABELS[presenceStatus]}
          </div>
        )}
      </div>
      {conversation && !(conversation.users.length === 1 && conversation.users[0].id === state.user?.id) && (
        <CallButton conversationId={conversation.id} />
      )}
    </div>
  );
}

