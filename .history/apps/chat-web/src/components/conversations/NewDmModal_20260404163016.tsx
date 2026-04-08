import { useState, useEffect, useRef } from 'react';
import { api } from '../../api/client';
import { useApp } from '../../store';
import type { User } from '../../types';

type Props = {
  onClose: () => void;
};

export function NewDmModal({ onClose }: Props) {
  const { state, dispatch } = useApp();
  const [query, setQuery] = useState('');
  const [users, setUsers] = useState<User[]>([]);
  const [loading, setLoading] = useState(true);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    inputRef.current?.focus();
  }, []);

  useEffect(() => {
    if (!state.spaceId) return;
    setLoading(true);
    api.spaces.members(state.spaceId).then((res) => {
      // Exclude self
      const others = res.members.filter((u) => u.id !== state.user!.id);
      setUsers(others);
      setLoading(false);
    });
  }, [state.spaceId, state.user]);

  const filtered = query.trim()
    ? users.filter(
        (u) =>
          u.display_name.toLowerCase().includes(query.toLowerCase()) ||
          u.email.toLowerCase().includes(query.toLowerCase())
      )
    : users;

  async function selectUser(user: User) {
    if (!state.spaceId) return;
    try {
      const res = await api.conversations.createDirect(state.spaceId, user.id);
      dispatch({ type: 'UPSERT_CONVERSATION', conversation: res.conversation });
      dispatch({ type: 'SET_ACTIVE_CONVERSATION', conversationId: res.conversation.id });
      onClose();
    } catch (err) {
      console.error('Failed to create DM:', err);
    }
  }

  return (
    <div className="dm-modal-backdrop" onClick={onClose}>
      <div className="dm-modal" onClick={(e) => e.stopPropagation()}>
        <div className="dm-modal-header">
          <span>Neue Direktnachricht</span>
          <button className="dm-modal-close" onClick={onClose}>
            &times;
          </button>
        </div>
        <div className="dm-modal-search">
          <input
            ref={inputRef}
            type="text"
            placeholder="Benutzer suchen..."
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>
        <ul className="dm-modal-list">
          {loading && <li className="dm-modal-empty">Laden...</li>}
          {!loading && filtered.length === 0 && (
            <li className="dm-modal-empty">Keine Benutzer gefunden</li>
          )}
          {filtered.map((user) => {
            const initials = user.display_name
              .split(' ')
              .map((n) => n[0])
              .join('')
              .slice(0, 2);
            return (
              <li
                key={user.id}
                className="dm-modal-user"
                onClick={() => selectUser(user)}
              >
                <div
                  className="dm-avatar"
                  style={{ background: user.avatar_color }}
                >
                  {initials}
                </div>
                <div className="dm-modal-user-info">
                  <span className="dm-modal-user-name">{user.display_name}</span>
                  <span className="dm-modal-user-title">{user.title}</span>
                </div>
              </li>
            );
          })}
        </ul>
      </div>
    </div>
  );
}
