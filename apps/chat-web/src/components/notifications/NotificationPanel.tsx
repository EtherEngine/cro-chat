import { useEffect, useRef } from 'react';
import { useApp } from '../../store';
import { api } from '../../api/client';
import type { AppNotification } from '../../types';

function timeAgo(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime();
  const mins = Math.floor(diff / 60_000);
  if (mins < 1) return 'gerade eben';
  if (mins < 60) return `vor ${mins} Min.`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `vor ${hrs} Std.`;
  const days = Math.floor(hrs / 24);
  return `vor ${days} T.`;
}

const typeLabels: Record<string, string> = {
  mention: 'hat dich erwähnt',
  dm: 'Neue Direktnachricht',
  thread_reply: 'Neue Antwort im Thread',
  reaction: 'hat reagiert',
  call_incoming: 'Eingehender Anruf',
  call_missed: 'Verpasster Anruf',
  call_rejected: 'Anruf abgelehnt',
};

const typeIcons: Record<string, string> = {
  mention: '@',
  dm: '💬',
  thread_reply: '↩',
  reaction: '👍',
  call_incoming: '📞',
  call_missed: '📵',
  call_rejected: '🚫',
};

export function NotificationPanel({ onClose }: { onClose: () => void }) {
  const { state, dispatch } = useApp();
  const panelRef = useRef<HTMLDivElement>(null);

  // Close when clicking outside
  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (panelRef.current && !panelRef.current.contains(e.target as Node)) {
        onClose();
      }
    }
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [onClose]);

  function handleMarkRead(n: AppNotification) {
    if (n.read_at) return;
    api.notifications.markRead(n.id).catch(() => {});
    dispatch({ type: 'MARK_NOTIFICATION_READ', notificationId: n.id });
  }

  function handleClick(n: AppNotification) {
    handleMarkRead(n);
    // Navigate to conversation if it's a call notification
    if (n.conversation_id) {
      dispatch({ type: 'SET_ACTIVE_CONVERSATION', conversationId: n.conversation_id });
      onClose();
    } else if (n.channel_id) {
      dispatch({ type: 'SET_ACTIVE_CHANNEL', channelId: n.channel_id });
      onClose();
    }
  }

  function handleMarkAllRead() {
    api.notifications.markAllRead().catch(() => {});
    dispatch({ type: 'SET_NOTIFICATION_UNREAD', count: 0 });
    dispatch({
      type: 'SET_NOTIFICATIONS',
      notifications: state.notifications.map((n) => ({
        ...n,
        read_at: n.read_at ?? new Date().toISOString(),
      })),
    });
  }

  const initials = (name: string) =>
    name.split(' ').map((w) => w[0]).join('').slice(0, 2);

  return (
    <div className="notification-panel" ref={panelRef}>
      <div className="notification-panel-header">
        <h3>Benachrichtigungen</h3>
        {state.notificationUnread > 0 && (
          <button className="notification-mark-all" onClick={handleMarkAllRead}>
            Alle gelesen
          </button>
        )}
      </div>

      <div className="notification-panel-list">
        {state.notifications.length === 0 ? (
          <div className="notification-empty">Keine Benachrichtigungen</div>
        ) : (
          state.notifications.map((n) => (
            <button
              key={n.id}
              className={`notification-item${!n.read_at ? ' unread' : ''}${
                n.type.startsWith('call_') ? ` notification-call notification-${n.type}` : ''
              }`}
              onClick={() => handleClick(n)}
            >
              <div className="notification-item-icon">
                <div className="avatar avatar-sm" style={{ background: n.actor.avatar_color }}>
                  {initials(n.actor.display_name)}
                </div>
                <span className="notification-type-badge">{typeIcons[n.type] ?? '•'}</span>
              </div>
              <div className="notification-item-content">
                <div className="notification-item-text">
                  <strong>{n.actor.display_name}</strong>{' '}
                  {typeLabels[n.type] ?? n.type}
                </div>
                <div className="notification-item-time">{timeAgo(n.created_at)}</div>
              </div>
              {!n.read_at && <span className="notification-unread-dot" />}
            </button>
          ))
        )}
      </div>
    </div>
  );
}
