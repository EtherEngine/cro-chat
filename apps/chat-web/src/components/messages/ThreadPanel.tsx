import { useState, useEffect, useRef, useCallback } from 'react';
import type { Message } from '../../types';
import { api } from '../../api/client';
import { useApp } from '../../store';

// ── Minimal message row for the thread panel ──────────────────────────────────

function ThreadMessageRow({ message }: { message: Message }) {
  const user = message.user;
  const initials = user
    ? user.display_name.split(' ').map((n) => n[0]).join('').slice(0, 2)
    : '?';
  const time = new Date(message.created_at).toLocaleTimeString('de-DE', {
    hour: '2-digit',
    minute: '2-digit',
  });

  return (
    <div className="thread-message-row">
      <div
        className="thread-message-avatar"
        style={{ background: user?.avatar_color || '#7c3aed' }}
      >
        {initials}
      </div>
      <div className="thread-message-content">
        <div className="thread-message-meta">
          <span className="thread-message-author">{user?.display_name || 'Unknown'}</span>
          <span className="thread-message-time">{time}</span>
        </div>
        {message.deleted_at ? (
          <div className="thread-message-deleted">Diese Nachricht wurde gelöscht.</div>
        ) : (
          <div className="thread-message-body">{message.body}</div>
        )}
      </div>
    </div>
  );
}

// ── ThreadPanel ───────────────────────────────────────────────────────────────

export function ThreadPanel() {
  const { state, dispatch } = useApp();
  const panel = state.threadPanel;

  const [body, setBody] = useState('');
  const [sending, setSending] = useState(false);
  const [sendError, setSendError] = useState('');
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  // Load thread when panel opens (if thread already exists on the message)
  useEffect(() => {
    if (!panel) return;
    const threadId = panel.rootMessage.thread?.id;
    if (!threadId) return;

    dispatch({ type: 'SET_THREAD_LOADING', loading: true });
    api.threads.get(threadId)
      .then((res) => {
        dispatch({
          type: 'SET_THREAD_DATA',
          thread: res.thread,
          rootMessage: res.root_message,
          messages: res.messages,
        });
      })
      .catch(() => {
        dispatch({ type: 'SET_THREAD_ERROR', error: 'Thread konnte nicht geladen werden.' });
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [panel?.rootMessage.id]);

  // Auto-scroll to bottom when replies change
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [panel?.messages.length]);

  // Auto-grow textarea
  useEffect(() => {
    const el = textareaRef.current;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
  }, [body]);

  const handleSend = useCallback(async () => {
    const trimmed = body.trim();
    if (!trimmed || sending || !panel) return;
    setSending(true);
    setSendError('');
    try {
      if (panel.thread) {
        // Reply to existing thread
        const res = await api.threads.reply(panel.thread.id, trimmed);
        const updatedThread = {
          ...panel.thread,
          reply_count: panel.thread.reply_count + 1,
          last_reply_at: new Date().toISOString(),
        };
        dispatch({ type: 'APPEND_THREAD_REPLY', message: res.message, thread: updatedThread });
        // Update reply count on root message in main feed
        dispatch({
          type: 'APPEND_MESSAGES',
          messages: [{
            ...panel.rootMessage,
            thread: {
              id: panel.thread.id,
              reply_count: updatedThread.reply_count,
              last_reply_at: updatedThread.last_reply_at,
            },
          }],
        });
      } else {
        // Start new thread (first reply)
        const res = await api.threads.startThread(panel.rootMessage.id, trimmed);
        dispatch({
          type: 'SET_THREAD_DATA',
          thread: res.thread,
          rootMessage: panel.rootMessage,
          messages: [res.message],
        });
        // Update root message in main feed with thread summary
        dispatch({
          type: 'APPEND_MESSAGES',
          messages: [{
            ...panel.rootMessage,
            thread: {
              id: res.thread.id,
              reply_count: 1,
              last_reply_at: new Date().toISOString(),
            },
          }],
        });
      }
      setBody('');
    } catch {
      setSendError('Senden fehlgeschlagen. Bitte erneut versuchen.');
    } finally {
      setSending(false);
    }
  }, [body, sending, panel, dispatch]);

  if (!panel) return null;

  const { rootMessage, thread, messages, loading, error } = panel;
  const replyLabel = thread
    ? `${thread.reply_count} Antwort${thread.reply_count !== 1 ? 'en' : ''}`
    : 'Noch keine Antworten';

  return (
    <aside className="thread-panel" aria-label="Thread">
      {/* Header */}
      <div className="thread-panel-header">
        <span className="thread-panel-title">🧵 Thread</span>
        <button
          type="button"
          className="thread-panel-close"
          title="Thread schließen"
          onClick={() => dispatch({ type: 'CLOSE_THREAD' })}
        >
          ×
        </button>
      </div>

      {/* Root message */}
      <div className="thread-panel-root">
        <ThreadMessageRow message={rootMessage} />
      </div>

      {/* Replies label */}
      <div className="thread-panel-replies-label">{replyLabel}</div>

      {/* Replies list */}
      <div className="thread-panel-messages">
        {loading && (
          <div className="thread-panel-loading">Laden…</div>
        )}
        {error && !loading && (
          <div className="thread-panel-error">{error}</div>
        )}
        {!loading && !error && messages.length === 0 && (
          <div className="thread-panel-empty">Noch keine Antworten. Schreib die erste!</div>
        )}
        {messages.map((m) => (
          <ThreadMessageRow key={m.id} message={m} />
        ))}
        <div ref={messagesEndRef} />
      </div>

      {/* Composer */}
      <div className="thread-panel-composer">
        <textarea
          ref={textareaRef}
          className="thread-panel-input"
          value={body}
          onChange={(e) => { setBody(e.target.value); setSendError(''); }}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault();
              handleSend();
            }
          }}
          placeholder="Antwort im Thread schreiben…"
          rows={1}
          disabled={sending}
          aria-label="Thread-Antwort"
        />
        {sendError && <p className="thread-composer-error">{sendError}</p>}
        <div className="thread-composer-actions">
          <span className="thread-composer-hint">Enter senden · Shift+Enter Zeilenumbruch</span>
          <button
            type="button"
            className="thread-composer-send"
            onClick={handleSend}
            disabled={!body.trim() || sending}
          >
            {sending ? 'Senden…' : 'Senden'}
          </button>
        </div>
      </div>
    </aside>
  );
}
