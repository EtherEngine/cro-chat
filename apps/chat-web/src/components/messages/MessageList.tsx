import { useRef, useEffect, useCallback } from 'react';
import { useApp } from '../../store';
import { CallMessage } from './CallMessage';
import { MessageItem } from './MessageItem';

export function MessageList() {
  const { state, dispatch } = useApp();
  const endRef = useRef<HTMLDivElement>(null);
  const messageRefs = useRef<Map<number, HTMLDivElement>>(new Map());

  // Auto-scroll to bottom on new messages (only if not jumping)
  useEffect(() => {
    if (!state.jumpToMessageId) {
      endRef.current?.scrollIntoView({ behavior: 'smooth' });
    }
  }, [state.messages, state.jumpToMessageId]);

  // Jump-to-message: scroll + highlight
  useEffect(() => {
    if (!state.jumpToMessageId) return;
    const msgId = state.jumpToMessageId;

    // Small delay to let DOM render
    const timer = setTimeout(() => {
      const el = messageRefs.current.get(msgId);
      if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.classList.add('message-highlight');
        setTimeout(() => el.classList.remove('message-highlight'), 2000);
      }
      dispatch({ type: 'CLEAR_JUMP' });
    }, 200);

    return () => clearTimeout(timer);
  }, [state.jumpToMessageId, state.messages, dispatch]);

  const setRef = useCallback((id: number, el: HTMLDivElement | null) => {
    if (el) {
      messageRefs.current.set(id, el);
    } else {
      messageRefs.current.delete(id);
    }
  }, []);

  return (
    <div className="message-list">
      {state.messages.map((msg) => (
        <div key={msg.id} ref={(el) => setRef(msg.id, el)}>
          {msg.type === 'call' ? (
            <CallMessage message={msg} />
          ) : (
            <MessageItem message={msg} />
          )}
        </div>
      ))}
      <div ref={endRef} />
    </div>
  );
}

