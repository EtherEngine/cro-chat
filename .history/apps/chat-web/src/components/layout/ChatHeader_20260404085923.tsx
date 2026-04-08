import { useApp } from '../../store';

export function ChatHeader() {
  const { state, dispatch } = useApp();

  const channel = state.channels.find((c) => c.id === state.activeChannelId);
  const conversation = state.conversations.find(
    (c) => c.id === state.activeConversationId,
  );

  let title = '';
  let meta = '';

  if (channel) {
    title = channel.name;
    meta = `${channel.member_count} members`;
  } else if (conversation) {
    title = conversation.users.map((u) => u.display_name).join(', ');
  }

  return (
    <div className="chat-header">
      <div
        className="chat-header-title"
        onClick={() => channel && dispatch({ type: 'TOGGLE_MEMBERS' })}
      >
        {title} <span className="arrow">\u25B8</span>
      </div>
      {meta && <div className="chat-header-meta">{meta}</div>}
    </div>
  );
}

