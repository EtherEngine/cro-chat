import { useState, useRef, type FormEvent } from 'react';
import { useApp } from '../../store';
import { api } from '../../api/client';
import type { Attachment } from '../../types';

function formatSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1024 / 1024).toFixed(1) + ' MB';
}

export function MessageComposer() {
  const { state, dispatch } = useApp();
  const [body, setBody] = useState('');
  const [pendingFiles, setPendingFiles] = useState<File[]>([]);
  const [uploading, setUploading] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!body.trim() && pendingFiles.length === 0) return;
    setUploading(true);
    try {
      // 1. Send message (use placeholder body if only files)
      const text = body.trim() || (pendingFiles.length > 0 ? ' ' : '');
      let message;
      if (state.activeChannelId) {
        ({ message } = await api.messages.sendChannel(state.activeChannelId, text));
      } else if (state.activeConversationId) {
        ({ message } = await api.messages.sendConversation(state.activeConversationId, text));
      }
      if (!message) return;

      // 2. Upload files sequentially and collect attachments
      const attachments: Attachment[] = [];
      for (const file of pendingFiles) {
        const { attachment } = await api.attachments.upload(message.id, file);
        attachments.push(attachment);
      }

      // 3. Dispatch message with attachments
      message.attachments = [...(message.attachments || []), ...attachments];
      dispatch({ type: 'APPEND_MESSAGES', messages: [message] });
      setBody('');
      setPendingFiles([]);
    } catch {
      /* ignore */
    } finally {
      setUploading(false);
    }
  };

  const handleFileSelect = () => { fileRef.current?.click(); };

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(e.target.files || []);
    setPendingFiles((prev) => [...prev, ...files]);
    e.target.value = '';
  };

  const removeFile = (index: number) => {
    setPendingFiles((prev) => prev.filter((_, i) => i !== index));
  };

  return (
    <div className="message-composer">
      {pendingFiles.length > 0 && (
        <div className="composer-files">
          {pendingFiles.map((file, i) => (
            <div key={i} className="composer-file-chip">
              <span className="file-chip-name">{file.name}</span>
              <span className="file-chip-size">{formatSize(file.size)}</span>
              <button type="button" className="file-chip-remove" onClick={() => removeFile(i)} title="Entfernen">&times;</button>
            </div>
          ))}
        </div>
      )}
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
          <button type="button" className="icon-btn" title="Attach" onClick={handleFileSelect}>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
              <circle cx="8.5" cy="8.5" r="1.5" />
              <polyline points="21 15 16 10 5 21" />
            </svg>
          </button>
          <input
            ref={fileRef}
            type="file"
            multiple
            onChange={handleFileChange}
            style={{ display: 'none' }}
            accept="image/*,.pdf,.txt,.csv,.md,.zip,.gz,.docx,.xlsx,.pptx,.mp3,.ogg,.mp4,.webm"
          />
        </div>
        <input
          value={body}
          onChange={(e) => setBody(e.target.value)}
          placeholder="Send message"
          disabled={uploading}
        />
        <button type="submit" className="send-btn" title="Send" disabled={uploading}>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" />
          </svg>
        </button>
      </form>
    </div>
  );
}

