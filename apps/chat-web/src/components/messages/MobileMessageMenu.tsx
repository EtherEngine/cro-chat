import { useState, useEffect } from 'react';
import type { Message } from '../../types';
import type { MessagePolicy } from './messagePolicy';
import { EmojiPicker } from './EmojiPicker';

const QUICK_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'] as const;

type Props = {
  policy: MessagePolicy;
  message: Message;
  myId: number;
  threadPanelActiveMessageId: number | null | undefined;
  pinBusy: boolean;
  saveBusy: boolean;
  deleteBusy: boolean;
  onClose: () => void;
  onReact: (emoji: string) => void;
  onReply: () => void;
  onOpenThread: () => void;
  onStartEdit: () => void;
  onPin: () => void;
  onSave: () => void;
  onDelete: () => void;
};

export function MobileMessageMenu({
  policy, message, myId, threadPanelActiveMessageId,
  pinBusy, saveBusy, deleteBusy,
  onClose, onReact, onReply, onOpenThread, onStartEdit, onPin, onSave, onDelete,
}: Props) {
  const [showFullPicker, setShowFullPicker] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const isThreadActive = threadPanelActiveMessageId === message.id;

  // Close on Escape (keydown in capture phase so it beats toolbar's handler)
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') { e.stopPropagation(); onClose(); }
    };
    document.addEventListener('keydown', handler, true);
    return () => document.removeEventListener('keydown', handler, true);
  }, [onClose]);

  // Prevent scrolling the page behind the sheet
  useEffect(() => {
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = ''; };
  }, []);

  const handleQuickReact = (emoji: string) => {
    onReact(emoji);
    onClose();
  };

  const handleFullPickerSelect = (emoji: string) => {
    setShowFullPicker(false);
    onReact(emoji);
    onClose();
  };

  const doAction = (fn: () => void) => { fn(); onClose(); };

  return (
    <div
      className="mobile-menu-overlay"
      role="presentation"
      onClick={onClose}
    >
      <div
        className="mobile-menu-sheet"
        role="dialog"
        aria-label="Nachrichtenaktionen"
        aria-modal="true"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Quick-react strip */}
        {policy.canReact && (
          <div className="mobile-menu-react-section">
            <div className="mobile-quick-reacts" role="group" aria-label="Schnellreaktionen">
              {QUICK_EMOJIS.map((emoji) => {
                const isActive = (message.reactions ?? []).some(
                  (r) => r.emoji === emoji && r.user_ids.includes(myId)
                );
                return (
                  <button
                    key={emoji}
                    type="button"
                    className={`mobile-quick-react-btn${isActive ? ' mobile-quick-react-btn--active' : ''}`}
                    aria-label={`Mit ${emoji} reagieren`}
                    aria-pressed={isActive}
                    onClick={() => handleQuickReact(emoji)}
                  >
                    {emoji}
                  </button>
                );
              })}
              <button
                type="button"
                className="mobile-quick-react-btn mobile-quick-react-btn--more"
                aria-label="Weiteres Emoji wählen"
                aria-expanded={showFullPicker}
                aria-haspopup="dialog"
                onClick={(e) => { e.stopPropagation(); setShowFullPicker((v) => !v); }}
              >
                +
              </button>
            </div>
            {/* Full emoji picker anchors above the react strip */}
            {showFullPicker && (
              <div className="mobile-picker-anchor">
                <EmojiPicker
                  onSelect={handleFullPickerSelect}
                  onClose={() => setShowFullPicker(false)}
                />
              </div>
            )}
          </div>
        )}

        <div className="mobile-menu-divider" role="separator" />

        {/* Action list */}
        <ul className="mobile-menu-actions" role="list">
          {policy.canReply && (
            <li role="listitem">
              <button type="button" className="mobile-menu-action-btn" onClick={() => doAction(onReply)}>
                <span className="mobile-menu-action-icon" aria-hidden="true">💬</span>
                Antworten
              </button>
            </li>
          )}
          {policy.canOpenThread && (
            <li role="listitem">
              <button type="button" className="mobile-menu-action-btn" onClick={() => doAction(onOpenThread)}>
                <span className="mobile-menu-action-icon" aria-hidden="true">🧵</span>
                {isThreadActive ? 'Thread schließen' : 'Thread öffnen'}
              </button>
            </li>
          )}
          {policy.canEdit && (
            <li role="listitem">
              <button type="button" className="mobile-menu-action-btn" onClick={() => doAction(onStartEdit)}>
                <span className="mobile-menu-action-icon" aria-hidden="true">✏️</span>
                Bearbeiten
              </button>
            </li>
          )}
          {policy.canPin && (
            <li role="listitem">
              <button
                type="button"
                className="mobile-menu-action-btn"
                onClick={() => doAction(onPin)}
                disabled={pinBusy}
                aria-busy={pinBusy}
              >
                <span className="mobile-menu-action-icon" aria-hidden="true">📌</span>
                {message.is_pinned ? 'Anpinnen aufheben' : 'Anpinnen'}
              </button>
            </li>
          )}
          {policy.canSave && (
            <li role="listitem">
              <button
                type="button"
                className="mobile-menu-action-btn"
                onClick={() => doAction(onSave)}
                disabled={saveBusy}
                aria-busy={saveBusy}
              >
                <span className="mobile-menu-action-icon" aria-hidden="true">🔖</span>
                {message.is_saved ? 'Gespeichert entfernen' : 'Für später speichern'}
              </button>
            </li>
          )}
          {policy.canDelete && !confirmDelete && (
            <li role="listitem">
              <button
                type="button"
                className="mobile-menu-action-btn mobile-menu-action-btn--danger"
                onClick={() => setConfirmDelete(true)}
              >
                <span className="mobile-menu-action-icon" aria-hidden="true">🗑️</span>
                Löschen
              </button>
            </li>
          )}
          {policy.canDelete && confirmDelete && (
            <li role="listitem" className="mobile-menu-delete-confirm">
              <p className="mobile-menu-delete-label">Nachricht wirklich löschen?</p>
              <div className="mobile-menu-delete-btns">
                <button
                  type="button"
                  className="mobile-confirm-btn mobile-confirm-btn--yes"
                  onClick={() => { onDelete(); onClose(); }}
                  disabled={deleteBusy}
                  aria-busy={deleteBusy}
                >
                  {deleteBusy ? 'Wird gelöscht…' : 'Löschen'}
                </button>
                <button
                  type="button"
                  className="mobile-confirm-btn mobile-confirm-btn--no"
                  onClick={() => setConfirmDelete(false)}
                >
                  Abbrechen
                </button>
              </div>
            </li>
          )}
        </ul>

        {/* Dismiss */}
        <button type="button" className="mobile-menu-cancel" onClick={onClose}>
          Abbrechen
        </button>
      </div>
    </div>
  );
}
