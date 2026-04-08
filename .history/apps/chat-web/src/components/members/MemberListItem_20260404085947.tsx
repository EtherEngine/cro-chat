import type { User } from '../../types';

type Props = { member: User; isYou: boolean };

export function MemberListItem({ member, isYou }: Props) {
  const initials = member.display_name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .slice(0, 2);

  return (
    <li className="member-item">
      <div
        className="member-avatar"
        style={{ background: member.avatar_color }}
      >
        {initials}
      </div>
      <div className="member-info">
        <div className="member-name">
          {member.display_name}
          {isYou && <span className="you-badge"> (You)</span>}
        </div>
        {member.title && <div className="member-title">{member.title}</div>}
      </div>
    </li>
  );
}

