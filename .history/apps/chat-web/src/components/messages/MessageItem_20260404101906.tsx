import type { Message } from '../../types';
import { api } from '../../api/client';

function formatSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1024 / 1024).toFixed(1) + ' MB';
}

type Props = { message: Message };

export function MessageItem({ message }: Props) {
  const user = message.user;
  const initials = user
    ? user.display_name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .slice(0, 2)
    : '?';

  const time = new Date(message.created_at).toLocaleTimeString('de-DE', {
    hour: '2-digit',
    minute: '2-digit',
  });

  const attachments = message.attachments || [];
  const images = attachments.filter((a) => a.mime_type.startsWith('image/'));
  const files = attachments.filter((a) => !a.mime_type.startsWith('image/'));

  return (
    <div className="message-item">
      <div
        className="message-avatar"
        style={{ background: user?.avatar_color || '#7c3aed' }}
      >
        {initials}
      </div>
      <div className="message-content">
        <div className="message-header">
          <span className="message-author">
            {user?.display_name || 'Unknown'}
          </span>
          <span className="message-time">{time}</span>
        </div>
        {message.body && message.body.trim() && (
          <div className="message-body">{message.body}</div>
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
      </div>
    </div>
  );
}

