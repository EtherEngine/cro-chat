import { useState, type FormEvent } from 'react';
import { useApp } from '../../store';
import { api } from '../../api/client';

export function MessageComposer() {
  const { state, dispatch } = useApp();
  const [body, setBody] = useState('');

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!body.trim()) return;
    try {
      let message;
      if (state.activeChannelId) {
        ({ message } = await api.messages.sendChannel(
          state.activeChannelId,
          body,
        ));
      } else if (state.activeConversationId) {
        ({ message } = await api.messages.sendConversation(
          state.activeConversationId,
          body,
        ));
      }
      if (message) {
        dispatch({ type: 'APPEND_MESSAGES', messages: [message] });
        setBody('');
      }
    } catch {
      /* ignore */
    }
  };

  return (
    <div className="message-composer">
      <form className="composer-bar" onSubmit={handleSubmit}>
        <div className="composer-icons">
          <button type="button" className="icon-btn" title="Emoji">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="12" r="10" />
              <path d="M8 14s1.5 2 4 2 4-2 4-2" />
              <line x1="9" y1="9" x2="9.01" y2="9" />
              <line x1="15" y1="9" x2="15.01" y2="9" />
            </svg>
          </button>
          <button type="button" className="icon-btn" title="Attach">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
              <circle cx="8.5" cy="8.5" r="1.5" />
              <polyline points="21 15 16 10 5 21" />
            </svg>
          </button>
        </div>
        <input
          value={body}
          onChange={(e) => setBody(e.target.value)}
          placeholder="Send message"
        />
        <button type="submit" className="send-btn" title="Send">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" />
          </svg>
        </button>
      </form>
    </div>
  );
}

