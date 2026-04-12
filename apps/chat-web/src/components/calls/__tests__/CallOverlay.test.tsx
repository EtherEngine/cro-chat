/**
 * Tests for CallOverlay component.
 *
 * Uses a minimal wrapper that provides both AppContext and CallContext so
 * the component can be rendered without the full app tree.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createContext, useContext, type ReactNode } from 'react';
import { CallOverlay } from '../CallOverlay';
import type { CallState } from '../../features/calls/useCall';
import { installWebRTCMocks } from '../../../test/mocks/webrtc';

vi.mock('../../../api/client', () => import('../../../test/mocks/api'));

// ── Context stubs ─────────────────────────────────

// We stub useApp (AppContext) and useCallContext (CallContext) so we can
// control state without mounting the full provider tree.

const AppContext = createContext<{ state: Record<string, unknown>; dispatch: () => void } | null>(null);

vi.mock('../../../store', () => ({
  useApp: () => {
    const ctx = useContext(AppContext);
    return ctx!;
  },
}));

const CallContext = createContext<Record<string, unknown> | null>(null);

vi.mock('../../../features/calls/CallProvider', () => ({
  useCallContext: () => {
    const ctx = useContext(CallContext);
    return ctx!;
  },
}));

// ── Test helpers ──────────────────────────────────

const baseUser = { id: 1, display_name: 'Alice', avatar_color: '#6c63ff' };

function makeAppState(overrides: Record<string, unknown> = {}) {
  return {
    user: baseUser,
    conversations: [
      {
        id: 10,
        users: [
          baseUser,
          { id: 2, display_name: 'Bob', avatar_color: '#ff6b6b' },
        ],
      },
    ],
    ...overrides,
  };
}

const IDLE_CALL_STATE: CallState = {
  phase: 'idle',
  call: null,
  muted: false,
  iceState: null,
  remoteStream: null,
  error: null,
  mediaError: null,
  audioInputDevices: [],
  audioOutputDevices: [],
  selectedAudioInput: null,
  selectedAudioOutput: null,
};

function makeCallState(overrides: Partial<CallState>): CallState {
  return { ...IDLE_CALL_STATE, ...overrides };
}

function fakeCall(callerId = 1, calleeId = 2, id = 1) {
  return {
    id,
    conversation_id: 10,
    caller_user_id: callerId,
    callee_user_id: calleeId,
    status: 'ringing' as const,
    started_at: new Date().toISOString(),
    answered_at: null,
    ended_at: null,
    duration_seconds: null,
    end_reason: null,
    created_at: new Date().toISOString(),
  };
}

const mockActions = {
  acceptCall: vi.fn().mockResolvedValue(undefined),
  rejectCall: vi.fn().mockResolvedValue(undefined),
  cancelCall: vi.fn().mockResolvedValue(undefined),
  hangup: vi.fn().mockResolvedValue(undefined),
  toggleMute: vi.fn(),
  refreshDevices: vi.fn().mockResolvedValue(undefined),
  switchAudioInput: vi.fn().mockResolvedValue(undefined),
  switchAudioOutput: vi.fn(),
  checkMicPermission: vi.fn().mockResolvedValue('granted'),
};

function renderOverlay(callState: CallState, appStateOverrides: Record<string, unknown> = {}) {
  const appState = makeAppState(appStateOverrides);
  const callContextValue = { callState, ...mockActions };

  return render(
    <AppContext.Provider value={{ state: appState, dispatch: vi.fn() }}>
      <CallContext.Provider value={callContextValue}>
        <CallOverlay />
      </CallContext.Provider>
    </AppContext.Provider>,
  );
}

// ── Tests ─────────────────────────────────────────

beforeEach(() => {
  installWebRTCMocks();
  vi.clearAllMocks();
});

describe('CallOverlay — idle state', () => {
  it('renders nothing when idle and no media error', () => {
    const { container } = renderOverlay(IDLE_CALL_STATE);
    expect(container.firstChild).toBeNull();
  });

  it('renders nothing when ended and no media error', () => {
    const { container } = renderOverlay(makeCallState({ phase: 'ended' }));
    expect(container.firstChild).toBeNull();
  });
});

describe('CallOverlay — ringing-incoming', () => {
  const call = fakeCall(2, 1); // Bob calls Alice

  it('shows incoming call overlay with accept and reject buttons', () => {
    renderOverlay(
      makeCallState({ phase: 'ringing-incoming', call }),
    );
    expect(screen.getByTitle('Annehmen')).toBeInTheDocument();
    expect(screen.getByTitle('Ablehnen')).toBeInTheDocument();
  });

  it('shows caller name', () => {
    renderOverlay(makeCallState({ phase: 'ringing-incoming', call }));
    expect(screen.getByText('Bob')).toBeInTheDocument();
  });

  it('shows incoming call status text', () => {
    renderOverlay(makeCallState({ phase: 'ringing-incoming', call }));
    expect(screen.getByText(/Eingehender Anruf/)).toBeInTheDocument();
  });

  it('calls acceptCall when Annehmen button is clicked', async () => {
    const user = userEvent.setup();
    renderOverlay(makeCallState({ phase: 'ringing-incoming', call }));
    await user.click(screen.getByTitle('Annehmen'));
    expect(mockActions.acceptCall).toHaveBeenCalledOnce();
  });

  it('calls rejectCall when Ablehnen button is clicked', async () => {
    const user = userEvent.setup();
    renderOverlay(makeCallState({ phase: 'ringing-incoming', call }));
    await user.click(screen.getByTitle('Ablehnen'));
    expect(mockActions.rejectCall).toHaveBeenCalledOnce();
  });
});

describe('CallOverlay — ringing-outgoing', () => {
  const call = fakeCall(1, 2); // Alice calls Bob

  it('shows outgoing call overlay with cancel button', () => {
    renderOverlay(makeCallState({ phase: 'ringing-outgoing', call }));
    expect(screen.getByTitle('Abbrechen')).toBeInTheDocument();
  });

  it('shows callee name', () => {
    renderOverlay(makeCallState({ phase: 'ringing-outgoing', call }));
    expect(screen.getByText('Bob')).toBeInTheDocument();
  });

  it('shows "Wird angerufen …" status', () => {
    renderOverlay(makeCallState({ phase: 'ringing-outgoing', call }));
    expect(screen.getByText(/Wird angerufen/)).toBeInTheDocument();
  });

  it('calls cancelCall when Abbrechen button is clicked', async () => {
    const user = userEvent.setup();
    renderOverlay(makeCallState({ phase: 'ringing-outgoing', call }));
    await user.click(screen.getByTitle('Abbrechen'));
    expect(mockActions.cancelCall).toHaveBeenCalledOnce();
  });

  it('also shows outgoing overlay in initiating phase', () => {
    renderOverlay(makeCallState({ phase: 'initiating', call }));
    expect(screen.getByTitle('Abbrechen')).toBeInTheDocument();
  });
});

describe('CallOverlay — active call panel', () => {
  const call = fakeCall(1, 2);

  it('shows "Verbinde …" in connecting phase', () => {
    renderOverlay(makeCallState({ phase: 'connecting', call }));
    expect(screen.getByText(/Verbinde/)).toBeInTheDocument();
  });

  it('shows "Beende …" in ending phase', () => {
    renderOverlay(makeCallState({ phase: 'ending', call }));
    expect(screen.getByText(/Beende/)).toBeInTheDocument();
  });

  it('shows mute button in active phase', () => {
    renderOverlay(makeCallState({ phase: 'active', call }));
    expect(screen.getByTitle('Mikrofon ausschalten')).toBeInTheDocument();
  });

  it('mute button title changes when muted', () => {
    renderOverlay(makeCallState({ phase: 'active', call, muted: true }));
    expect(screen.getByTitle('Mikrofon einschalten')).toBeInTheDocument();
  });

  it('calls toggleMute when mute button is clicked', async () => {
    const user = userEvent.setup();
    renderOverlay(makeCallState({ phase: 'active', call }));
    await user.click(screen.getByTitle('Mikrofon ausschalten'));
    expect(mockActions.toggleMute).toHaveBeenCalledOnce();
  });

  it('calls hangup when hangup button is clicked in active phase', async () => {
    const user = userEvent.setup();
    renderOverlay(makeCallState({ phase: 'active', call }));
    const hangupBtn = screen.getByTitle('Auflegen');
    await user.click(hangupBtn);
    expect(mockActions.hangup).toHaveBeenCalledOnce();
  });

  it('shows other party name in active panel', () => {
    renderOverlay(makeCallState({ phase: 'active', call }));
    expect(screen.getByText('Bob')).toBeInTheDocument();
  });
});

describe('CallOverlay — media error states', () => {
  it('shows microphone_denied error with dismiss button', () => {
    renderOverlay(makeCallState({ mediaError: 'microphone_denied', phase: 'idle' }));
    expect(screen.getByText('Mikrofon verweigert')).toBeInTheDocument();
    expect(screen.getByTitle('Schließen')).toBeInTheDocument();
  });

  it('shows device_not_found error', () => {
    renderOverlay(makeCallState({ mediaError: 'device_not_found', phase: 'idle' }));
    expect(screen.getByText('Kein Mikrofon gefunden')).toBeInTheDocument();
  });

  it('shows device_in_use error', () => {
    renderOverlay(makeCallState({ mediaError: 'device_in_use', phase: 'idle' }));
    expect(screen.getByText('Mikrofon nicht verfügbar')).toBeInTheDocument();
  });

  it('shows connection_lost error', () => {
    renderOverlay(makeCallState({ mediaError: 'connection_lost', phase: 'ending' }));
    expect(screen.getByText('Verbindung verloren')).toBeInTheDocument();
  });

  it('dismiss button calls hangup', async () => {
    const user = userEvent.setup();
    renderOverlay(makeCallState({ mediaError: 'microphone_denied', phase: 'idle' }));
    await user.click(screen.getByTitle('Schließen'));
    expect(mockActions.hangup).toHaveBeenCalledOnce();
  });
});

describe('CallOverlay — unknown participant fallback', () => {
  it('shows User #id when participant not found in conversations', () => {
    const call = fakeCall(1, 99); // 99 not in conversations
    renderOverlay(
      makeCallState({ phase: 'ringing-outgoing', call }),
    );
    expect(screen.getByText('User #99')).toBeInTheDocument();
  });
});
