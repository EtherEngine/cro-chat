import { useCallContext } from '../../features/calls/CallProvider';
import { useApp } from '../../store';
import type { PresenceStatus } from '../../types';

/** Presence states that indicate the remote user cannot take a call. */
const BUSY_STATUSES: PresenceStatus[] = ['in_call', 'dnd'];

/**
 * Phone icon button for the ChatHeader. Shown only in DM conversations.
 * Starts a call on the active conversation.
 * Disabled when local user is already in a call or remote user is busy/dnd.
 */
export function CallButton({ conversationId }: { conversationId: number }) {
  const { callState, startCall } = useCallContext();
  const { state } = useApp();

  const localBusy = callState.phase !== 'idle';

  // Determine remote user's presence
  const conv = state.conversations.find((c) => c.id === conversationId);
  const other = conv?.users.find((u) => u.id !== state.user?.id);
  const remoteStatus = other ? (state.presence[other.id] ?? other.status) : 'offline';
  const remoteBusy = BUSY_STATUSES.includes(remoteStatus);

  const disabled = localBusy || remoteBusy;

  let title = 'Anrufen';
  if (localBusy) title = 'Anruf läuft bereits';
  else if (remoteStatus === 'dnd') title = 'Nicht stören aktiv';
  else if (remoteStatus === 'in_call') title = 'Nutzer ist im Gespräch';
  else if (remoteStatus === 'ringing') title = 'Anrufen';
  else if (remoteStatus === 'offline') title = 'Nutzer ist offline';

  return (
    <button
      className="call-btn"
      onClick={() => startCall(conversationId)}
      disabled={disabled}
      title={title}
    >
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
      </svg>
    </button>
  );
}
