import type { Message } from '../../types';

type Props = { message: Message };

export function MessageItem({ message }: Props) {
  const user = message.user;
  const initials = user
    ? user.display_name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .slice(0, 2)
    : '?';

  const time = new Date(message.created_at).toLocaleTimeString('de-DE', {
    hour: '2-digit',
    minute: '2-digit',
  });

  return (
    <div className="message-item">
      <div
        className="message-avatar"
        style={{ background: user?.avatar_color || '#7c3aed' }}
      >
        {initials}
      </div>
      <div className="message-content">
        <div className="message-header">
          <span className="message-author">
            {user?.display_name || 'Unknown'}
          </span>
          <span className="message-time">{time}</span>
        </div>
        <div className="message-body">{message.body}</div>
      </div>
    </div>
  );
}

