import { useApp } from '../../store';
import type { PresenceStatus, User } from '../../types';

const PRESENCE_LABELS: Partial<Record<PresenceStatus, string>> = {
  ringing: 'Wird angerufen …',
  in_call: 'Im Gespräch',
  dnd: 'Nicht stören',
};

type Props = { member: User; isYou: boolean };

export function MemberListItem({ member, isYou }: Props) {
  const { state } = useApp();
  const initials = member.display_name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .slice(0, 2);

  const presence: PresenceStatus = state.presence[member.id] ?? member.status ?? 'offline';
  const presenceLabel = PRESENCE_LABELS[presence];

  return (
    <li className="member-item">
      <div
        className="member-avatar"
        style={{ background: member.avatar_color }}
      >
        {initials}
        <span className={`presence-dot ${presence}`} />
      </div>
      <div className="member-info">
        <div className="member-name">
          {member.display_name}
          {isYou && <span className="you-badge"> (You)</span>}
          {presenceLabel && <span className="member-presence-label">{presenceLabel}</span>}
        </div>
        {member.title && <div className="member-title">{member.title}</div>}
      </div>
    </li>
  );
}

