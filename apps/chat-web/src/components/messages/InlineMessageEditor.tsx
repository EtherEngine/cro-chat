import { useRef, useState, useEffect, useId } from 'react';
import { EmojiPicker } from './EmojiPicker';

type Props = {
  body: string;
  busy: boolean;
  error: string;
  saveDisabled: boolean;
  originalBody: string;
  onChange: (val: string) => void;
  onSubmit: () => void;
  onCancel: () => void;
};

export function InlineMessageEditor({
  body, busy, error, saveDisabled, originalBody,
  onChange, onSubmit, onCancel,
}: Props) {
  const ref = useRef<HTMLTextAreaElement>(null);
  const uid = useId();
  const errorId = `${uid}-error`;
  const hintId = `${uid}-hint`;
  const [showEmoji, setShowEmoji] = useState(false);

  // Auto-focus with cursor at end on mount
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    el.focus();
    const len = el.value.length;
    el.setSelectionRange(len, len);
  }, []);

  // Auto-grow height
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
  }, [body]);

  const insertEmoji = (emoji: string) => {
    const el = ref.current;
    if (el) {
      const start = el.selectionStart;
      const end = el.selectionEnd;
      const newBody = body.slice(0, start) + emoji + body.slice(end);
      onChange(newBody);
      // Restore cursor after emoji
      requestAnimationFrame(() => {
        const pos = start + emoji.length;
        el.setSelectionRange(pos, pos);
        el.focus();
      });
    } else {
      onChange(body + emoji);
    }
    setShowEmoji(false);
  };

  return (
    <div className="message-edit-area">
      <div className="message-edit-input-wrap">
        <textarea
          ref={ref}
          className="message-edit-input"
          value={body}
          onChange={(e) => onChange(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault();
              onSubmit();
            }
            if (e.key === 'Escape') {
              if (showEmoji) { setShowEmoji(false); return; }
              onCancel();
            }
          }}
          disabled={busy}
          aria-label="Nachricht bearbeiten"
          aria-invalid={!!error}
          aria-describedby={`${hintId}${error ? ` ${errorId}` : ''}`}
        />
        <div className="edit-emoji-anchor">
          <button
            type="button"
            className={`edit-emoji-btn${showEmoji ? ' edit-emoji-btn--active' : ''}`}
            aria-label="Emoji einfügen"
            aria-expanded={showEmoji}
            aria-haspopup="dialog"
            title="Emoji"
            onClick={() => setShowEmoji((v) => !v)}
            disabled={busy}
          >
            😀
          </button>
          {showEmoji && (
            <EmojiPicker onSelect={insertEmoji} onClose={() => setShowEmoji(false)} />
          )}
        </div>
      </div>
      {error && (
        <p id={errorId} className="edit-error" role="alert">{error}</p>
      )}
      <div className="message-edit-actions">
        <button
          type="button"
          className="edit-action-btn edit-action-btn--save"
          onClick={onSubmit}
          disabled={saveDisabled}
          aria-label={saveDisabled && body.trim() === originalBody ? 'Speichern – keine Änderungen' : 'Änderungen speichern'}
          title={body.trim() === originalBody ? 'Keine Änderungen' : undefined}
        >
          {busy ? 'Speichern...' : 'Speichern'}
        </button>
        <button
          type="button"
          className="edit-action-btn edit-action-btn--cancel"
          onClick={onCancel}
          disabled={busy}
          aria-label="Bearbeitung abbrechen"
        >
          Abbrechen
        </button>
        <span id={hintId} className="edit-hint">
          Enter speichern · Shift+Enter Zeilenumbruch · Esc abbrechen
        </span>
      </div>
    </div>
  );
}
