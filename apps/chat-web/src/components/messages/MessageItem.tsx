import { useState } from 'react';
import type { Message } from '../../types';
import { api } from '../../api/client';
import { useApp } from '../../store';
import { getMessagePolicy } from './messagePolicy';
import { useMessageActions } from './useMessageActions';
import { InlineMessageEditor } from './InlineMessageEditor';
import { ReactionBar } from './ReactionBar';
import { MessageActionToolbar } from './MessageActionToolbar';
import { MobileMessageMenu } from './MobileMessageMenu';

function formatSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1024 / 1024).toFixed(1) + ' MB';
}

function formatEditedAt(iso: string): string {
  return new Date(iso).toLocaleString('de-DE', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

type Props = { message: Message };

export function MessageItem({ message }: Props) {
  const { state, dispatch } = useApp();

  // ── Display state (rendering concerns only) ────────────────────────────
  const [hovered, setHovered] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [showReactionPicker, setShowReactionPicker] = useState(false);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [showMobileMenu, setShowMobileMenu] = useState(false);

  // ── Policy ──────────────────────────────────────────────────────────────
  const policy = getMessagePolicy(message, {
    userId: state.user?.id ?? null,
    spaceRole: state.spaceRole,
  });

  // ── Mutation logic (delegated to hook) ──────────────────────────────────
  const actions = useMessageActions(message, policy);

  // ── Derived display values ───────────────────────────────────────────────
  const user = message.user;
  const initials = user
    ? user.display_name.split(' ').map((n) => n[0]).join('').slice(0, 2)
    : '?';
  const time = new Date(message.created_at).toLocaleTimeString('de-DE', {
    hour: '2-digit',
    minute: '2-digit',
  });
  // ── Reply-to: look up from embed or fall back to state ─────────────────────
  const replyToSource = message.reply_to
    ?? (message.reply_to_id
      ? (state.messages.find((m) => m.id === message.reply_to_id) ?? null)
      : null);
  // ── Soft-delete tombstone ────────────────────────────────────────────────
  if (message.deleted_at) {
    return (
      <div className="message-item message-item--deleted">
        <div
          className="message-avatar message-avatar--deleted"
          style={{ background: user?.avatar_color || '#7c3aed' }}
        >
          {initials}
        </div>
        <div className="message-content">
          <div className="message-header">
            <span className="message-author">{user?.display_name || 'Unknown'}</span>
            <span className="message-time">{time}</span>
          </div>
          <div className="message-tombstone">Diese Nachricht wurde gelöscht.</div>
        </div>
      </div>
    );
  }

  // ── Coordinated handlers (display + mutation) ────────────────────────────
  const startEdit = () => {
    actions.initEditMode(message.body ?? '');
    setIsEditing(true);
    setHovered(false);
  };

  const cancelEdit = () => {
    actions.resetEditState();
    setIsEditing(false);
  };

  const handleEditSubmit = async () => {
    const ok = await actions.handleEditSubmit();
    if (ok) setIsEditing(false);
  };

  const handleConfirmDelete = () => {
    setShowDeleteConfirm(false);
    setHovered(false);
    actions.handleDelete();
  };

  const attachments = message.attachments || [];
  const images = attachments.filter((a) => a.mime_type.startsWith('image/'));
  const files = attachments.filter((a) => !a.mime_type.startsWith('image/'));

  return (
    <div
      className={`message-item${hovered ? ' message-item--hovered' : ''}`}
      role="group"
      aria-label={`Nachricht von ${user?.display_name || 'Unknown'} um ${time}`}
      tabIndex={0}
      onMouseEnter={() => setHovered(true)}
      onMouseLeave={() => { setHovered(false); setShowReactionPicker(false); }}
      onFocus={() => setHovered(true)}
      onBlur={(e) => {
        if (!e.currentTarget.contains(e.relatedTarget as Node)) {
          setHovered(false);
          setShowReactionPicker(false);
        }
      }}
    >
      <div
        className="message-avatar"
        style={{ background: user?.avatar_color || '#7c3aed' }}
      >
        {initials}
      </div>

      <div className="message-content">
        {actions.deleteError && <p className="delete-error" role="alert">{actions.deleteError}</p>}
        <div className="message-header">
          <span className="message-author">{user?.display_name || 'Unknown'}</span>
          <span className="message-time">{time}</span>
          {message.edited_at && (
            <span
              className="message-edited"
              title={`Bearbeitet am ${formatEditedAt(message.edited_at)}`}
            >
              (bearbeitet)
            </span>
          )}
          {message.is_pinned && (
            <span className="message-pin-indicator" title="Angepinnt">📌</span>
          )}
          {!isEditing && (
            <button
              type="button"
              className="message-more-btn"
              aria-label="Weitere Aktionen"
              aria-haspopup="dialog"
              aria-expanded={showMobileMenu}
              onClick={() => setShowMobileMenu(true)}
            >
              ···
            </button>
          )}
        </div>
        {(actions.pinError || actions.saveError) && (
          <p className="pin-save-error" role="alert">{actions.pinError || actions.saveError}</p>
        )}

        {replyToSource && (
          <button
            type="button"
            className="message-reply-quote"
            onClick={() => dispatch({ type: 'JUMP_TO_MESSAGE', messageId: replyToSource.id })}
            title="Zur Originalnachricht springen"
          >
            <span className="message-reply-quote-author">
              {(replyToSource as {user?: {display_name: string}}).user?.display_name ?? 'Unbekannt'}
            </span>
            <span className="message-reply-quote-body">
              {((replyToSource as {body?: string | null}).body ?? '').slice(0, 100) || '(Anhang)'}
            </span>
          </button>
        )}

        {isEditing ? (
          <InlineMessageEditor
            body={actions.editBody}
            busy={actions.editBusy}
            error={actions.editError}
            saveDisabled={actions.saveDisabled}
            originalBody={message.body ?? ''}
            onChange={actions.handleEditBodyChange}
            onSubmit={handleEditSubmit}
            onCancel={cancelEdit}
          />
        ) : (
          <>
            {message.body && message.body.trim() && (
              <div className="message-body">{message.body}</div>
            )}
            {message.thread && policy.canOpenThread && (
              <button
                type="button"
                className="message-thread-badge"
                title="Thread öffnen"
                onClick={actions.handleOpenThread}
              >
                🧵 <span className="thread-badge-count">{message.thread.reply_count}</span>
                {' '}{message.thread.reply_count === 1 ? 'Antwort' : 'Antworten'}
              </button>
            )}
          </>
        )}

        {images.length > 0 && (
          <div className="message-images">
            {images.map((img) => (
              <a key={img.id} href={api.attachments.downloadUrl(img.id)} target="_blank" rel="noopener noreferrer" className="message-image-link">
                <img src={api.attachments.downloadUrl(img.id)} alt={img.original_name} className="message-image" loading="lazy" />
              </a>
            ))}
          </div>
        )}
        {files.length > 0 && (
          <div className="message-files">
            {files.map((f) => (
              <a key={f.id} href={api.attachments.downloadUrl(f.id)} target="_blank" rel="noopener noreferrer" className="message-file-link">
                <span className="file-icon">📎</span>
                <span className="file-name">{f.original_name}</span>
                <span className="file-size">{formatSize(f.file_size)}</span>
              </a>
            ))}
          </div>
        )}

        <ReactionBar
          reactions={message.reactions ?? []}
          myId={actions.myId}
          members={state.members}
          onReact={actions.handleReact}
        />
      </div>

      {showMobileMenu && (
        <MobileMessageMenu
          policy={policy}
          message={message}
          myId={actions.myId}
          threadPanelActiveMessageId={state.threadPanel?.rootMessage.id}
          pinBusy={actions.pinBusy}
          saveBusy={actions.saveBusy}
          deleteBusy={actions.deleteBusy}
          onClose={() => setShowMobileMenu(false)}
          onReact={actions.handleReact}
          onReply={actions.handleReply}
          onOpenThread={actions.handleOpenThread}
          onStartEdit={startEdit}
          onPin={actions.handlePin}
          onSave={actions.handleSave}
          onDelete={actions.handleDelete}
        />
      )}

      {hovered && !isEditing && (
        <MessageActionToolbar
          policy={policy}
          message={message}
          threadPanelActiveMessageId={state.threadPanel?.rootMessage.id}
          showDeleteConfirm={showDeleteConfirm}
          showReactionPicker={showReactionPicker}
          pinBusy={actions.pinBusy}
          saveBusy={actions.saveBusy}
          deleteBusy={actions.deleteBusy}
          onToggleReactionPicker={() => setShowReactionPicker((v) => !v)}
          onCloseReactionPicker={() => setShowReactionPicker(false)}
          onReact={actions.handleReact}
          onReply={actions.handleReply}
          onOpenThread={actions.handleOpenThread}
          onStartEdit={startEdit}
          onPin={actions.handlePin}
          onSave={actions.handleSave}
          onRequestDelete={() => setShowDeleteConfirm(true)}
          onConfirmDelete={handleConfirmDelete}
          onCancelDelete={() => setShowDeleteConfirm(false)}
        />
      )}
    </div>
  );
}
