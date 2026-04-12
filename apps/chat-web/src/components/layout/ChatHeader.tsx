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
    title = conversation.users.map((u) => u.display_name).join(', ');
    const other = conversation.users.find((u) => u.id !== state.user?.id);
    if (other) {
      presenceStatus = state.presence[other.id] ?? other.status ?? 'offline';
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
      {conversation && <CallButton conversationId={conversation.id} />}
    </div>
  );
}

