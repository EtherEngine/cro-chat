import type { Reaction, User } from '../../types';

type Props = {
  reactions: Reaction[];
  myId: number;
  members: User[];
  onReact: (emoji: string) => void;
};

function buildTooltip(r: Reaction, members: User[]): string {
  const names = r.user_ids
    .map((id) => members.find((m) => m.id === id)?.display_name ?? null)
    .filter(Boolean) as string[];
  const shown = names.slice(0, 3);
  const rest = names.length - shown.length;
  const label = rest > 0 ? `${shown.join(', ')} +${rest}` : shown.join(', ');
  return label
    ? `${label} ${r.count === 1 ? 'hat' : 'haben'} mit ${r.emoji} reagiert`
    : `${r.count} Reaktion${r.count !== 1 ? 'en' : ''}`;
}

export function ReactionBar({
  reactions, myId, members, onReact,
}: Props) {
  if (reactions.length === 0) return null;

  return (
    <div className="message-reactions" role="group" aria-label="Reaktionen">
      {reactions.map((r) => (
        <button
          key={r.emoji}
          type="button"
          className={`reaction-bubble${r.user_ids.includes(myId) ? ' reaction-bubble--own' : ''}`}
          aria-label={buildTooltip(r, members)}
          aria-pressed={r.user_ids.includes(myId)}
          title={buildTooltip(r, members)}
          onClick={() => onReact(r.emoji)}
        >
          <span className="reaction-emoji" aria-hidden="true">{r.emoji}</span>
          <span className="reaction-count" aria-hidden="true">{r.count}</span>
        </button>
      ))}
    </div>
  );
}
