import { useEffect, useState } from 'react';
import { useCallContext } from '../../features/calls/CallProvider';
import { useApp } from '../../store';
import type { MediaErrorCode } from '../../features/calls/CallEngine';

/** Human-readable labels + descriptions for media error codes. */
const MEDIA_ERROR_INFO: Record<MediaErrorCode, { title: string; description: string; icon: string }> = {
  microphone_denied: {
    title: 'Mikrofon verweigert',
    description: 'Bitte erlaube den Mikrofonzugriff in deinen Browser-Einstellungen und versuche es erneut.',
    icon: '🎤',
  },
  permission_blocked: {
    title: 'Mikrofon dauerhaft blockiert',
    description: 'Der Mikrofonzugriff ist in den Browser-Einstellungen blockiert. Klicke auf das Schloss-Symbol in der Adressleiste, um die Berechtigung zu ändern.',
    icon: '🔒',
  },
  device_not_found: {
    title: 'Kein Mikrofon gefunden',
    description: 'Es wurde kein Mikrofon erkannt. Bitte schließe ein Mikrofon an und versuche es erneut.',
    icon: '🔇',
  },
  device_in_use: {
    title: 'Mikrofon nicht verfügbar',
    description: 'Das Mikrofon wird von einer anderen Anwendung verwendet. Bitte schließe die andere Anwendung und versuche es erneut.',
    icon: '⚠️',
  },
  connection_lost: {
    title: 'Verbindung verloren',
    description: 'Die Verbindung zum Gesprächspartner wurde unterbrochen.',
    icon: '📡',
  },
};

/**
 * Full-screen overlay shown when there is an incoming call (ringing-incoming),
 * an outgoing call (ringing-outgoing), or a connecting/active call.
 * Also renders media error states (microphone_denied, device_not_found, etc.).
 * Renders nothing when idle (and no mediaError).
 */
export function CallOverlay() {
  const { callState, acceptCall, rejectCall, cancelCall, hangup, toggleMute } =
    useCallContext();
  const { state } = useApp();
  const { phase, call, muted, iceState, remoteStream, error, mediaError } = callState;

  // Elapsed timer for active calls
  const [elapsed, setElapsed] = useState(0);

  useEffect(() => {
    if (phase !== 'active') {
      setElapsed(0);
      return;
    }
    const t = setInterval(() => setElapsed((s) => s + 1), 1000);
    return () => clearInterval(t);
  }, [phase]);

  // Attach remote stream to audio element
  useEffect(() => {
    const audio = document.getElementById('call-remote-audio') as HTMLAudioElement | null;
    if (audio && remoteStream) {
      audio.srcObject = remoteStream;
    }
  }, [remoteStream]);

  if (phase === 'idle' && !mediaError) return null;
  if (phase === 'ended' && !mediaError) return null;

  // ── Media error overlay ──
  if (mediaError) {
    const info = MEDIA_ERROR_INFO[mediaError];
    return (
      <div className="call-overlay">
        <div className="call-overlay-card">
          <div className="call-media-error-icon">{info.icon}</div>
          <div className="call-overlay-name">{info.title}</div>
          <div className="call-media-error-desc">{info.description}</div>
          <div className="call-overlay-actions">
            <button
              className="call-action-btn call-dismiss-btn"
              onClick={() => {
                // Reset to idle — user can retry
                hangup();
              }}
              title="Schließen"
            >
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
        </div>
      </div>
    );
  }

  if (!call) return null;

  // Resolve participant name
  const otherUserId =
    call.caller_user_id === state.user?.id
      ? call.callee_user_id
      : call.caller_user_id;

  const conversations = state.conversations;
  let otherName = `User #${otherUserId}`;
  for (const conv of conversations) {
    const u = conv.users.find((u) => u.id === otherUserId);
    if (u) {
      otherName = u.display_name;
      break;
    }
  }

  // Avatar initials
  const initials = otherName
    .split(' ')
    .map((w) => w[0])
    .join('')
    .slice(0, 2)
    .toUpperCase();

  // Avatar color
  let avatarColor = 'var(--accent)';
  for (const conv of conversations) {
    const u = conv.users.find((u) => u.id === otherUserId);
    if (u) {
      avatarColor = u.avatar_color;
      break;
    }
  }

  const formatTime = (s: number) => {
    const m = Math.floor(s / 60);
    const sec = s % 60;
    return `${m}:${sec.toString().padStart(2, '0')}`;
  };

  const isCaller = call.caller_user_id === state.user?.id;

  // ── Incoming call overlay ──
  if (phase === 'ringing-incoming') {
    return (
      <div className="call-overlay">
        <div className="call-overlay-card">
          <div className="call-avatar" style={{ background: avatarColor }}>
            {initials}
          </div>
          <div className="call-overlay-name">{otherName}</div>
          <div className="call-overlay-status call-pulse">Eingehender Anruf …</div>
          <div className="call-overlay-actions">
            <button className="call-action-btn call-reject" onClick={rejectCall} title="Ablehnen">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.11 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91"/>
                <line x1="1" y1="1" x2="23" y2="23"/>
              </svg>
            </button>
            <button className="call-action-btn call-accept" onClick={acceptCall} title="Annehmen">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
              </svg>
            </button>
          </div>
        </div>
        <audio id="call-remote-audio" autoPlay />
      </div>
    );
  }

  // ── Outgoing ringing / initiating ──
  if (phase === 'initiating' || phase === 'ringing-outgoing') {
    return (
      <div className="call-overlay">
        <div className="call-overlay-card">
          <div className="call-avatar" style={{ background: avatarColor }}>
            {initials}
          </div>
          <div className="call-overlay-name">{otherName}</div>
          <div className="call-overlay-status call-pulse">Wird angerufen …</div>
          <div className="call-overlay-actions">
            <button className="call-action-btn call-reject" onClick={cancelCall} title="Abbrechen">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.11 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91"/>
                <line x1="1" y1="1" x2="23" y2="23"/>
              </svg>
            </button>
          </div>
        </div>
        <audio id="call-remote-audio" autoPlay />
      </div>
    );
  }

  // ── Connecting / Active / Ending ──
  return (
    <div className="call-panel">
      <div className="call-panel-info">
        <div className="call-panel-avatar" style={{ background: avatarColor }}>
          {initials}
        </div>
        <div className="call-panel-details">
          <div className="call-panel-name">{otherName}</div>
          <div className="call-panel-status">
            {phase === 'connecting' && 'Verbinde …'}
            {phase === 'active' && formatTime(elapsed)}
            {phase === 'ending' && 'Beende …'}
            {error && <span className="call-error"> — {error}</span>}
          </div>
        </div>
      </div>
      <div className="call-panel-controls">
        <button
          className={`call-control-btn ${muted ? 'call-muted' : ''}`}
          onClick={toggleMute}
          title={muted ? 'Mikrofon einschalten' : 'Mikrofon ausschalten'}
        >
          {muted ? (
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <line x1="1" y1="1" x2="23" y2="23"/>
              <path d="M9 9v3a3 3 0 0 0 5.12 2.12M15 9.34V4a3 3 0 0 0-5.94-.6"/>
              <path d="M17 16.95A7 7 0 0 1 5 12v-2m14 0v2c0 .76-.12 1.49-.34 2.18"/>
              <line x1="12" y1="19" x2="12" y2="23"/>
              <line x1="8" y1="23" x2="16" y2="23"/>
            </svg>
          ) : (
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
              <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
              <line x1="12" y1="19" x2="12" y2="23"/>
              <line x1="8" y1="23" x2="16" y2="23"/>
            </svg>
          )}
        </button>
        <button className="call-control-btn call-hangup-btn" onClick={hangup} title="Auflegen">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.11 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91"/>
            <line x1="1" y1="1" x2="23" y2="23"/>
          </svg>
        </button>
      </div>
      <audio id="call-remote-audio" autoPlay />
    </div>
  );
}
