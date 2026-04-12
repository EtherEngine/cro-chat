import { useApp } from '../../store';
import type { Message, CallMeta, CallStatus } from '../../types';

const STATUS_ICON: Record<CallStatus, string> = {
  initiated: '📞',
  ringing: '📞',
  accepted: '📞',
  ended: '✅',
  missed: '📵',
  rejected: '🚫',
  failed: '⚠️',
};

const STATUS_LABEL: Record<CallStatus, string> = {
  initiated: 'Anruf',
  ringing: 'Anruf',
  accepted: 'Anruf',
  ended: 'Anruf beendet',
  missed: 'Verpasster Anruf',
  rejected: 'Anruf abgelehnt',
  failed: 'Anruf fehlgeschlagen',
};

function formatDuration(seconds: number): string {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  if (m === 0) return `${s}s`;
  return `${m}:${String(s).padStart(2, '0')} min`;
}

type Props = { message: Message };

export function CallMessage({ message }: Props) {
  const { state } = useApp();
  const meta: CallMeta | null | undefined = message.call_meta;

  if (!meta) return null;

  const currentUserId = state.user?.id;
  const isCaller = meta.caller_user_id === currentUserId;
  const status = meta.status;
  const icon = STATUS_ICON[status] ?? '📞';
  const label = STATUS_LABEL[status] ?? 'Anruf';

  // Direction label
  let direction = '';
  if (status === 'missed') {
    direction = isCaller ? 'Ausgehend' : 'Eingehend';
  } else if (status === 'rejected') {
    direction = isCaller ? 'Abgelehnt' : 'Abgelehnt';
  } else if (status === 'ended') {
    direction = isCaller ? 'Ausgehend' : 'Eingehend';
  }

  const time = new Date(message.created_at).toLocaleTimeString('de-DE', {
    hour: '2-digit',
    minute: '2-digit',
  });

  return (
    <div className={`call-message call-message--${status}`}>
      <span className="call-message-icon">{icon}</span>
      <div className="call-message-body">
        <span className="call-message-label">{label}</span>
        {meta.duration_seconds != null && meta.duration_seconds > 0 && (
          <span className="call-message-duration">
            {formatDuration(meta.duration_seconds)}
          </span>
        )}
        {direction && (
          <span className="call-message-direction">{direction}</span>
        )}
      </div>
      <span className="call-message-time">{time}</span>
    </div>
  );
}
