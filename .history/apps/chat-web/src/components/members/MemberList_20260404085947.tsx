import { useApp } from '../../store';
import { MemberListItem } from './MemberListItem';

type Props = { search: string };

export function MemberList({ search }: Props) {
  const { state } = useApp();
  const filtered = search
    ? state.members.filter((m) =>
        m.display_name.toLowerCase().includes(search.toLowerCase()),
      )
    : state.members;

  return (
    <ul className="member-list">
      {filtered.map((m) => (
        <MemberListItem
          key={m.id}
          member={m}
          isYou={m.id === state.user?.id}
        />
      ))}
    </ul>
  );
}

