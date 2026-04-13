import { useRef, useState, useEffect } from 'react';
import type { Message } from '../../types';
import type { MessagePolicy } from './messagePolicy';
import { EmojiPicker } from './EmojiPicker';

type Props = {
  policy: MessagePolicy;
  message: Message;
  /** ID of the root message currently shown in the thread panel, if any. */
  threadPanelActiveMessageId: number | null | undefined;
  showDeleteConfirm: boolean;
  showReactionPicker: boolean;
  pinBusy: boolean;
  saveBusy: boolean;
  deleteBusy: boolean;
  onToggleReactionPicker: () => void;
  onCloseReactionPicker: () => void;
  onReact: (emoji: string) => void;
  onReply: () => void;
  onOpenThread: () => void;
  onStartEdit: () => void;
  onPin: () => void;
  onSave: () => void;
  onRequestDelete: () => void;
  onConfirmDelete: () => void;
  onCancelDelete: () => void;
};

export function MessageActionToolbar({
  policy, message, threadPanelActiveMessageId,
  showDeleteConfirm, showReactionPicker,
  pinBusy, saveBusy, deleteBusy,
  onToggleReactionPicker, onCloseReactionPicker, onReact,
  onReply, onOpenThread, onStartEdit,
  onPin, onSave, onRequestDelete, onConfirmDelete, onCancelDelete,
}: Props) {
  const toolbarRef = useRef<HTMLDivElement>(null);
  const moreRef = useRef<HTMLDivElement>(null);
  const [showMore, setShowMore] = useState(false);

  // Close "more" dropdown on outside click
  useEffect(() => {
    if (!showMore) return;
    const handler = (e: MouseEvent) => {
      if (moreRef.current && !moreRef.current.contains(e.target as Node)) {
        setShowMore(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [showMore]);

  // Arrow-key navigation within the toolbar (ARIA toolbar pattern)
  const handleKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
    if (e.key === 'Escape') {
      if (showDeleteConfirm) { onCancelDelete(); return; }
      if (showMore) { setShowMore(false); return; }
      if (showReactionPicker) { onCloseReactionPicker(); return; }
      const msgItem = toolbarRef.current?.closest<HTMLElement>('[role="group"]');
      msgItem?.focus();
      return;
    }
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
    e.preventDefault();
    const toolbar = toolbarRef.current;
    if (!toolbar) return;
    const buttons = Array.from(
      toolbar.querySelectorAll<HTMLElement>('button:not([disabled])')
    );
    const idx = buttons.indexOf(document.activeElement as HTMLElement);
    if (idx === -1) return;
    let next = idx;
    if (e.key === 'ArrowRight') next = (idx + 1) % buttons.length;
    else if (e.key === 'ArrowLeft') next = (idx - 1 + buttons.length) % buttons.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = buttons.length - 1;
    buttons[next].focus();
  };

  if (showDeleteConfirm) {
    return (
      <div
        ref={toolbarRef}
        className="message-toolbar"
        role="alertdialog"
        aria-label="Nachricht löschen bestätigen"
        aria-modal="false"
        onKeyDown={handleKeyDown}
      >
        <span className="delete-confirm-label" id="delete-confirm-label">Löschen?</span>
        <button
          type="button"
          className="delete-confirm-btn delete-confirm-btn--yes"
          aria-label="Löschen bestätigen"
          aria-describedby="delete-confirm-label"
          onClick={onConfirmDelete}
          disabled={deleteBusy}
          aria-busy={deleteBusy}
          autoFocus
        >
          Ja
        </button>
        <button
          type="button"
          className="delete-confirm-btn delete-confirm-btn--no"
          aria-label="Abbrechen"
          onClick={onCancelDelete}
        >
          Nein
        </button>
      </div>
    );
  }

  const isThreadActive = threadPanelActiveMessageId === message.id;
  const hasMoreActions = policy.canReply || policy.canOpenThread || policy.canPin || policy.canSave || policy.canDelete;

  const handleEmojiSelect = (emoji: string) => {
    onCloseReactionPicker();
    onReact(emoji);
  };

  const doMore = (fn: () => void) => {
    setShowMore(false);
    fn();
  };

  return (
    <div
      ref={toolbarRef}
      className="message-toolbar"
      role="toolbar"
      aria-label="Nachrichtenaktionen"
      onKeyDown={handleKeyDown}
    >
      {/* Emoji — always visible */}
      {policy.canReact && (
        <div className="toolbar-emoji-anchor">
          <button
            type="button"
            className={`toolbar-btn${showReactionPicker ? ' toolbar-btn--active' : ''}`}
            aria-label="Emoji-Reaktion hinzufügen"
            aria-expanded={showReactionPicker}
            aria-haspopup="dialog"
            title="Reaktion"
            onClick={() => { setShowMore(false); onToggleReactionPicker(); }}
          >
            😀
          </button>
          {showReactionPicker && (
            <EmojiPicker onSelect={handleEmojiSelect} onClose={onCloseReactionPicker} />
          )}
        </div>
      )}

      {/* Edit — always visible (owner-only via policy.canEdit) */}
      {policy.canEdit && (
        <button
          type="button"
          className="toolbar-btn"
          aria-label="Nachricht bearbeiten"
          title="Bearbeiten"
          onClick={onStartEdit}
        >
          ✏️
        </button>
      )}

      {/* More dropdown — Reply, Thread, Pin, Save, Delete */}
      {hasMoreActions && (
        <div className="toolbar-more-anchor" ref={moreRef}>
          <button
            type="button"
            className={`toolbar-btn toolbar-btn--more${showMore ? ' toolbar-btn--active' : ''}`}
            aria-label="Weitere Aktionen"
            aria-expanded={showMore}
            aria-haspopup="menu"
            title="Mehr"
            onClick={() => { onCloseReactionPicker(); setShowMore((v) => !v); }}
          >
            ···
          </button>
          {showMore && (
            <div className="toolbar-dropdown" role="menu" aria-label="Weitere Aktionen">
              {policy.canReply && (
                <button type="button" role="menuitem" className="toolbar-dropdown-item" onClick={() => doMore(onReply)}>
                  <span className="toolbar-dropdown-icon" aria-hidden="true">💬</span>
                  Antworten
                </button>
              )}
              {policy.canOpenThread && (
                <button
                  type="button"
                  role="menuitem"
                  className={`toolbar-dropdown-item${isThreadActive ? ' toolbar-dropdown-item--active' : ''}`}
                  onClick={() => doMore(onOpenThread)}
                >
                  <span className="toolbar-dropdown-icon" aria-hidden="true">🧵</span>
                  {isThreadActive ? 'Thread schließen' : 'Thread öffnen'}
                </button>
              )}
              {policy.canPin && (
                <button
                  type="button"
                  role="menuitem"
                  className={`toolbar-dropdown-item${message.is_pinned ? ' toolbar-dropdown-item--active' : ''}`}
                  onClick={() => doMore(onPin)}
                  disabled={pinBusy}
                  aria-busy={pinBusy}
                >
                  <span className="toolbar-dropdown-icon" aria-hidden="true">📌</span>
                  {message.is_pinned ? 'Anpinnen aufheben' : 'Anpinnen'}
                </button>
              )}
              {policy.canSave && (
                <button
                  type="button"
                  role="menuitem"
                  className={`toolbar-dropdown-item${message.is_saved ? ' toolbar-dropdown-item--active' : ''}`}
                  onClick={() => doMore(onSave)}
                  disabled={saveBusy}
                  aria-busy={saveBusy}
                >
                  <span className="toolbar-dropdown-icon" aria-hidden="true">🔖</span>
                  {message.is_saved ? 'Gespeichert entfernen' : 'Für später speichern'}
                </button>
              )}
              {policy.canDelete && (
                <button
                  type="button"
                  role="menuitem"
                  className="toolbar-dropdown-item toolbar-dropdown-item--danger"
                  onClick={() => doMore(onRequestDelete)}
                  disabled={deleteBusy}
                >
                  <span className="toolbar-dropdown-icon" aria-hidden="true">🗑️</span>
                  Löschen
                </button>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
