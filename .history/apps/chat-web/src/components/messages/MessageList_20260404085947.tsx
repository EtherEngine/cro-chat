import { useRef, useEffect } from 'react';
import { useApp } from '../../store';
import { MessageItem } from './MessageItem';

export function MessageList() {
  const { state } = useApp();
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [state.messages]);

  return (
    <div className="message-list">
      {state.messages.map((msg) => (
        <MessageItem key={msg.id} message={msg} />
      ))}
      <div ref={endRef} />
    </div>
  );
}

