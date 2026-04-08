import { useState, useRef, useEffect } from 'react';
import { api } from '../../api/client';
import { useApp } from '../../store';

type Props = {
  onClose: () => void;
};

const COLORS = [
  '#7C3AED', '#2563EB', '#059669', '#D97706', '#DC2626',
  '#DB2777', '#7C2D12', '#0891B2', '#4F46E5', '#65A30D',
];

export function NewChannelModal({ onClose }: Props) {
  const { state, dispatch } = useApp();
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [color, setColor] = useState(COLORS[0]);
  const [isPrivate, setIsPrivate] = useState(false);
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    inputRef.current?.focus();
  }, []);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const trimmed = name.trim();
    if (!trimmed) {
      setError('Ein Kanalname ist erforderlich.');
      return;
    }
    if (!state.spaceId) return;

    setSubmitting(true);
    setError('');
    try {
      const res = await api.channels.create(state.spaceId, {
        name: trimmed,
        description: description.trim() || undefined,
        color,
        is_private: isPrivate,
      });
      dispatch({ type: 'ADD_CHANNEL', channel: res.channel });
      dispatch({ type: 'SET_ACTIVE_CHANNEL', channelId: res.channel.id });
      dispatch({ type: 'SET_ACTIVE_CONVERSATION', conversationId: null });
      onClose();
    } catch (err: any) {
      setError(err?.message || 'Kanal konnte nicht erstellt werden.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="dm-modal-backdrop" onClick={onClose}>
      <div className="dm-modal" onClick={(e) => e.stopPropagation()}>
        <div className="dm-modal-header">
          <span>Neuer Kanal</span>
          <button className="dm-modal-close" onClick={onClose}>
            &times;
          </button>
        </div>
        <form className="ch-modal-form" onSubmit={handleSubmit}>
          <label className="ch-modal-label">
            Name <span className="ch-modal-required">*</span>
            <input
              ref={inputRef}
              type="text"
              className="ch-modal-input"
              placeholder="z.B. allgemein"
              value={name}
              onChange={(e) => setName(e.target.value)}
              maxLength={100}
            />
          </label>
          <label className="ch-modal-label">
            Beschreibung
            <input
              type="text"
              className="ch-modal-input"
              placeholder="Worum geht es?"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            />
          </label>
          <fieldset className="ch-modal-fieldset">
            <legend>Farbe</legend>
            <div className="ch-modal-colors">
              {COLORS.map((c) => (
                <button
                  key={c}
                  type="button"
                  className={`ch-modal-color-btn${color === c ? ' active' : ''}`}
                  style={{ background: c }}
                  onClick={() => setColor(c)}
                  aria-label={c}
                />
              ))}
            </div>
          </fieldset>
          <label className="ch-modal-toggle">
            <input
              type="checkbox"
              checked={isPrivate}
              onChange={(e) => setIsPrivate(e.target.checked)}
            />
            Privater Kanal
          </label>
          {error && <p className="ch-modal-error">{error}</p>}
          <button type="submit" className="ch-modal-submit" disabled={submitting}>
            {submitting ? 'Erstelle...' : 'Kanal erstellen'}
          </button>
        </form>
      </div>
    </div>
  );
}
