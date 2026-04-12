import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../api/client';
import type { RealtimeEvent } from '../../realtime/socket';
import { getSharedRealtimeClient } from '../../realtime/useRealtime';
import {
  CallEngine,
  type ICallEngine,
  type IceConnectionState,
  type MediaError,
  type MediaErrorCode,
  type AudioDevice,
} from './CallEngine';
import { SimulatedCallEngine } from './dev/SimulatedCallEngine';
import type { Call, CallStatus } from '../../types';

/**
 * When VITE_CALL_SIMULATION=true, the hook:
 *  - Instantiates SimulatedCallEngine instead of CallEngine (no getUserMedia)
 *  - On acceptCall(), starts the callee engine immediately (no real offer needed)
 *
 * Set in .env.local:   VITE_CALL_SIMULATION=true
 * Never set in .env.production — the flag defaults to false/undefined.
 */
const SIM_MODE = import.meta.env.VITE_CALL_SIMULATION === 'true';

// ── Call state exposed to UI ────────────────────────────────

export type CallPhase =
  | 'idle'
  | 'initiating'      // REST POST in flight
  | 'ringing-outgoing' // caller waits for callee
  | 'ringing-incoming' // callee sees incoming call
  | 'connecting'       // accepted, WebRTC negotiating
  | 'active'           // ICE connected, audio flowing
  | 'ending'           // hangup in flight
  | 'ended';           // terminal

/**
 * Allowed phase transitions.  Any transition not listed here is silently
 * rejected by `guardPhase()`, preventing illegal state jumps.
 */
const ALLOWED_TRANSITIONS: Record<CallPhase, readonly CallPhase[]> = {
  'idle':             ['initiating', 'ringing-incoming'],
  'initiating':       ['ringing-outgoing', 'ringing-incoming', 'ended', 'idle'],
  'ringing-outgoing': ['connecting', 'ending', 'ended', 'idle'],
  'ringing-incoming': ['connecting', 'ending', 'ended', 'idle'],
  'connecting':       ['active', 'ending', 'ended', 'idle'],
  'active':           ['ending', 'ended', 'idle'],
  'ending':           ['ended', 'idle'],
  'ended':            ['idle'],
};

export type CallState = {
  phase: CallPhase;
  call: Call | null;
  /** Local microphone muted? */
  muted: boolean;
  /** ICE connection state (null when no peer connection) */
  iceState: IceConnectionState | null;
  /** Remote audio stream — attach to <audio ref> */
  remoteStream: MediaStream | null;
  /** Human-readable error, if any */
  error: string | null;
  /** Structured media error code for UI rendering */
  mediaError: MediaErrorCode | null;
  /** Available audio input devices (populated after media access) */
  audioInputDevices: AudioDevice[];
  /** Available audio output devices (populated after media access) */
  audioOutputDevices: AudioDevice[];
  /** Currently selected audio input device ID (null = system default) */
  selectedAudioInput: string | null;
  /** Currently selected audio output device ID (null = system default) */
  selectedAudioOutput: string | null;
};

const IDLE_STATE: CallState = {
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

// ── Hook ────────────────────────────────────────────────────

/**
 * React hook for managing a 1:1 audio call.
 *
 * Provides the full call lifecycle:
 *  - `startCall(conversationId)` — initiates a call (caller)
 *  - `acceptCall()` — accepts an incoming call (callee)
 *  - `rejectCall()` — rejects an incoming call (callee)
 *  - `cancelCall()` — cancels an outgoing ringing call (caller)
 *  - `hangup()` — ends an active call (either party)
 *  - `toggleMute()` — mutes/unmutes local audio
 *
 * Listens to realtime events for incoming calls and WebRTC signaling.
 *
 * @param userId  The current user's ID (from auth state).
 */
export function useCall(userId: number) {
  const [callState, setCallState] = useState<CallState>(IDLE_STATE);
  const engineRef = useRef<ICallEngine | null>(null);

  // Stable ref for current call state (avoids stale closures in event handler)
  const stateRef = useRef(callState);
  stateRef.current = callState;

  const patch = useCallback((updates: Partial<CallState>) => {
    setCallState((prev) => ({ ...prev, ...updates }));
  }, []);

  /**
   * Guarded phase transition: only applies the update if the transition is
   * valid per ALLOWED_TRANSITIONS.  Returns true if the transition was applied.
   */
  const guardedPatch = useCallback((updates: Partial<CallState>) => {
    setCallState((prev) => {
      if (updates.phase && updates.phase !== prev.phase) {
        const allowed = ALLOWED_TRANSITIONS[prev.phase];
        if (!allowed.includes(updates.phase)) {
          console.warn(
            `[useCall] Blocked invalid transition: ${prev.phase} → ${updates.phase}`,
          );
          return prev;
        }
      }
      return { ...prev, ...updates };
    });
  }, []);

  /**
   * Buffer for a glare scenario: an incoming call.ringing arrives while we
   * are in 'initiating' phase (our POST hasn't returned yet).
   * When our initiate fails with CALL_ALREADY_ACTIVE, we pivot to this call.
   */
  const pendingIncomingRef = useRef<Call | null>(null);

  // ── Cleanup helper ──────────────────────────────

  const destroyEngine = useCallback(() => {
    engineRef.current?.dispose();
    engineRef.current = null;
  }, []);

  const resetToIdle = useCallback(() => {
    destroyEngine();
    rtcConfigRef.current = null; // Invalidate cached TURN credentials
    setCallState(IDLE_STATE);
  }, [destroyEngine]);

  // ── ICE config fetcher ───────────────────────────

  /** Cached RTCConfiguration for the current session. */
  const rtcConfigRef = useRef<RTCConfiguration | null>(null);

  /**
   * Fetch ICE server config from the backend (time-limited TURN credentials).
   * Caches for the hook lifetime; re-fetches on next call.
   */
  const fetchIceConfig = useCallback(async (): Promise<RTCConfiguration> => {
    try {
      const response = await api.calls.iceServers();
      const config = CallEngine.buildRtcConfig(response);
      rtcConfigRef.current = config;
      return config;
    } catch {
      // Fallback to public STUN if backend unreachable
      return CallEngine.buildRtcConfig({ ice_servers: [] });
    }
  }, []);

  // ── Build engine for a call ─────────────────────

  const buildEngine = useCallback(
    (call: Call, role: 'caller' | 'callee', rtcConfig?: RTCConfiguration): ICallEngine => {
      const targetUserId =
        role === 'caller' ? call.callee_user_id : call.caller_user_id;

      const callbacks = {
          onIceStateChange: (ice: IceConnectionState) => {
            patch({ iceState: ice });
            if (ice === 'connected' || ice === 'completed') {
              guardedPatch({ phase: 'active', mediaError: null });
            }
          },
          onRemoteStream: (stream: MediaStream) => {
            patch({ remoteStream: stream });
          },
          onMediaError: (mediaErr: MediaError) => {
            patch({ mediaError: mediaErr.code, error: mediaErr.message });

            // connection_lost during active call → mark ending, try server hangup
            if (mediaErr.code === 'connection_lost') {
              guardedPatch({ phase: 'ending' });
              api.calls.hangup(call.id).catch(() => {});
              destroyEngine();
              return;
            }

            // Media acquisition errors → end immediately
            guardedPatch({ phase: 'ended' });
            api.calls.hangup(call.id).catch(() => {});
            destroyEngine();
          },
          onError: () => {
            // Handled by onMediaError — kept for backward compat, no-op here
          },
        };

      const engine: ICallEngine = SIM_MODE
        ? new SimulatedCallEngine(
            call.id,
            call.conversation_id,
            targetUserId,
            role,
            getSharedRealtimeClient(),
            callbacks,
            rtcConfig,
          )
        : new CallEngine(
            call.id,
            call.conversation_id,
            targetUserId,
            role,
            getSharedRealtimeClient(),
            callbacks,
            rtcConfig,
          );

      engineRef.current = engine;
      return engine;
    },
    [patch, guardedPatch, destroyEngine],
  );

  // ── Actions ─────────────────────────────────────

  /**
   * Start a call on a direct conversation (caller flow).
   */
  const startCall = useCallback(
    async (conversationId: number) => {
      if (stateRef.current.phase !== 'idle') return;

      pendingIncomingRef.current = null;
      guardedPatch({ phase: 'initiating', error: null, mediaError: null });

      try {
        // Initiate the call first so the active-call record exists in the
        // backend before we request ICE config.  The ICE endpoint issues TURN
        // credentials only when the user is in an active call, so the fetch
        // must happen *after* initiate() succeeds — not in parallel with it.
        const { call } = await api.calls.initiate(conversationId);

        guardedPatch({ call, phase: 'ringing-outgoing' });

        const rtcConfig = await fetchIceConfig();

        // Build engine with dynamic ICE config and start media
        const engine = buildEngine(call, 'caller', rtcConfig);
        await engine.start();

        // If start() failed (mediaError callback fired), don't proceed
        if (stateRef.current.mediaError) return;

        // Enumerate devices now that we have mic permission
        await refreshDevicesInternal();

        await engine.createOffer();
      } catch (err) {
        // ── Glare resolution ──────────────────────────────
        // If the backend rejected because the other party already
        // called us, pivot to the incoming call instead of showing
        // an error.
        const bufferedCall = pendingIncomingRef.current;
        pendingIncomingRef.current = null;

        if (err instanceof ApiError) {
          const code = err.code;
          const activeCall = err.errors?.active_call as Call | undefined;

          if (code === 'CALL_ALREADY_ACTIVE' || code === 'CALLEE_BUSY' || code === 'CALLER_BUSY') {
            const incomingCall = activeCall ?? bufferedCall;
            if (incomingCall && (incomingCall as Call).callee_user_id === userId) {
              // The other party called us — show as incoming
              guardedPatch({ phase: 'ringing-incoming', call: incomingCall, error: null });
              return;
            }
          }
        }

        guardedPatch({
          phase: 'ended',
          error: (err as Error).message,
        });
        destroyEngine();
      }
    },
    [userId, guardedPatch, buildEngine, destroyEngine, fetchIceConfig],
  );

  /**
   * Accept an incoming ringing call (callee flow).
   */
  const acceptCall = useCallback(async () => {
    const { call } = stateRef.current;
    if (!call || stateRef.current.phase !== 'ringing-incoming') return;

    guardedPatch({ phase: 'connecting' });

    try {
      await api.calls.accept(call.id);

      // ── Simulation mode ───────────────────────────────────────
      // In production the callee engine is built by the webrtc.offer handler
      // once the caller sends an SDP offer.  In simulation, the bot has no real
      // WebRTC peer and never sends an offer, so we start the engine here and
      // let SimulatedCallEngine schedule the fake ICE-connected transition.
      if (SIM_MODE && !engineRef.current) {
        fetchIceConfig().then(async (rtcConfig) => {
          const engine = buildEngine(call, 'callee', rtcConfig);
          await engine.start();
          await engine.handleOffer('sim-noop'); // SimulatedCallEngine ignores the SDP
        }).catch((err) => {
          guardedPatch({ phase: 'ended', error: (err as Error).message });
          destroyEngine();
        });
      }
      // In production: Engine was already started when the offer arrived — nothing else needed.
      // SDP answer is sent inside engine.handleOffer().
    } catch (err) {
      guardedPatch({ phase: 'ended', error: (err as Error).message });
      destroyEngine();
    }
  }, [guardedPatch, destroyEngine, buildEngine, fetchIceConfig]);

  /**
   * Reject an incoming call (callee).
   */
  const rejectCall = useCallback(async () => {
    const { call } = stateRef.current;
    if (!call || stateRef.current.phase !== 'ringing-incoming') return;

    guardedPatch({ phase: 'ending' });
    try {
      await api.calls.reject(call.id);
    } catch {
      // best-effort
    }
    resetToIdle();
  }, [guardedPatch, resetToIdle]);

  /**
   * Cancel an outgoing ringing call (caller).
   */
  const cancelCall = useCallback(async () => {
    const { call } = stateRef.current;
    if (!call || stateRef.current.phase !== 'ringing-outgoing') return;

    guardedPatch({ phase: 'ending' });
    try {
      await api.calls.cancel(call.id);
    } catch {
      // best-effort
    }
    resetToIdle();
  }, [guardedPatch, resetToIdle]);

  /**
   * Hang up an active (accepted) call — either party.
   */
  const hangup = useCallback(async () => {
    const { call, phase } = stateRef.current;
    if (!call) return;
    if (phase !== 'connecting' && phase !== 'active') return;

    guardedPatch({ phase: 'ending' });
    try {
      await api.calls.hangup(call.id);
    } catch {
      // best-effort
    }
    resetToIdle();
  }, [guardedPatch, resetToIdle]);

  /**
   * Toggle local microphone mute.
   */
  const toggleMute = useCallback(() => {
    const engine = engineRef.current;
    if (!engine) return;

    const muted = engine.toggleMute();
    patch({ muted });
  }, [patch]);

  // ── Device management ───────────────────────────

  /**
   * Internal: refresh device lists from the browser.
   */
  const refreshDevicesInternal = useCallback(async () => {
    const devices = await CallEngine.enumerateAudioDevices();
    patch({
      audioInputDevices: devices.filter((d) => d.kind === 'audioinput'),
      audioOutputDevices: devices.filter((d) => d.kind === 'audiooutput'),
    });
  }, [patch]);

  /**
   * Re-enumerate available audio devices.
   * Call this after plugging/unplugging a device.
   */
  const refreshDevices = useCallback(async () => {
    await refreshDevicesInternal();
  }, [refreshDevicesInternal]);

  /**
   * Switch the active microphone input device mid-call.
   */
  const switchAudioInput = useCallback(async (deviceId: string) => {
    const engine = engineRef.current;
    if (!engine) return;

    await engine.switchAudioInput(deviceId);
    patch({ selectedAudioInput: deviceId });
  }, [patch]);

  /**
   * Switch the audio output device (speaker/headphone).
   * Requires the <audio> element to support setSinkId (most browsers do).
   */
  const switchAudioOutput = useCallback((deviceId: string) => {
    const audio = document.getElementById('call-remote-audio') as HTMLAudioElement & {
      setSinkId?: (id: string) => Promise<void>;
    } | null;
    if (audio?.setSinkId) {
      audio.setSinkId(deviceId).then(() => {
        patch({ selectedAudioOutput: deviceId });
      }).catch((err) => {
        console.warn('[useCall] Failed to set audio output device:', err);
      });
    }
  }, [patch]);

  /**
   * Proactively check mic permission without prompting the user.
   * Returns the PermissionState or null if the API is unavailable.
   */
  const checkMicPermission = useCallback(async (): Promise<PermissionState | null> => {
    return CallEngine.checkMicPermission();
  }, []);

  // ── Realtime event listener ─────────────────────

  useEffect(() => {
    const client = getSharedRealtimeClient();

    const unsub = client.onEvent((event: RealtimeEvent) => {
      const { type, payload } = event;
      const current = stateRef.current;

      // ── Lifecycle events (from outbox broadcast) ──

      if (type === 'call.ringing') {
        const p = payload as {
          call_id: number;
          conversation_id: number;
          caller_user_id: number;
          callee_user_id: number;
        };

        // Only the callee should handle this
        if (p.callee_user_id !== userId) return;

        // Idempotent: ignore if we're already handling this exact call
        if (current.call?.id === p.call_id) return;

        // Build a minimal Call stub for the incoming call
        const callStub: Call = {
          id: p.call_id,
          conversation_id: p.conversation_id,
          caller_user_id: p.caller_user_id,
          callee_user_id: p.callee_user_id,
          status: 'ringing',
          started_at: new Date().toISOString(),
          answered_at: null,
          ended_at: null,
          duration_seconds: null,
          end_reason: null,
          created_at: new Date().toISOString(),
        };

        // ── Glare resolution ──────────────────────────────
        // If we're currently initiating our own call (POST in flight),
        // buffer this incoming call. When our initiate fails with
        // CALL_ALREADY_ACTIVE, startCall() will pivot to this buffer.
        if (current.phase === 'initiating') {
          pendingIncomingRef.current = callStub;
          return;
        }

        // Ignore if already in any non-idle call phase
        if (current.phase !== 'idle') return;

        guardedPatch({ phase: 'ringing-incoming', call: callStub, error: null });
      }

      if (type === 'call.accepted') {
        const p = payload as { call_id: number };
        // Idempotent: only process for our current call
        if (current.call?.id !== p.call_id) return;
        if (current.phase === 'ringing-outgoing') {
          guardedPatch({ phase: 'connecting' });
        }
      }

      if (
        type === 'call.rejected' ||
        type === 'call.ended' ||
        type === 'call.failed'
      ) {
        const p = payload as { call_id: number; reason?: string };
        if (current.call?.id === p.call_id && current.phase !== 'idle') {
          resetToIdle();
        }
      }

      // ── WebRTC signaling events (direct relay) ──

      if (type === 'webrtc.offer') {
        const p = payload as { call_id: number; sdp: string };
        // Only process for our current call
        if (current.call?.id !== p.call_id) return;

        // ── ICE restart offer (active call) ──
        // If we're already connected/active, this is an ICE restart offer
        // from the caller. Handle it inline without building a new engine.
        if (
          (current.phase === 'active' || current.phase === 'connecting') &&
          engineRef.current
        ) {
          engineRef.current.handleOffer(p.sdp).catch((err) => {
            console.warn('[useCall] ICE restart offer failed:', err);
          });
          return;
        }

        // ── Initial offer (callee ringing) ──
        if (current.phase !== 'ringing-incoming') return;

        // Fetch ICE config, build engine for callee, start media, handle offer
        fetchIceConfig().then(async (rtcConfig) => {
          const engine = buildEngine(current.call!, 'callee', rtcConfig);
          await engine.start();
          // If media failed, mediaError callback already fired
          if (stateRef.current.mediaError) return;
          await refreshDevicesInternal();
          await engine.handleOffer(p.sdp);
        }).catch((err) => {
          guardedPatch({ error: (err as Error).message, phase: 'ended' });
          destroyEngine();
        });
      }

      if (type === 'webrtc.answer') {
        const p = payload as { call_id: number; sdp: string };
        if (current.call?.id !== p.call_id) return;

        engineRef.current?.handleAnswer(p.sdp).catch((err) => {
          console.warn('[useCall] Failed to handle answer:', err);
        });
      }

      if (type === 'webrtc.ice_candidate') {
        const p = payload as { call_id: number; candidate: RTCIceCandidateInit };
        // Strict call_id match — drops stale candidates from previous calls
        if (current.call?.id !== p.call_id) return;

        engineRef.current?.addIceCandidate(p.candidate).catch((err) => {
          console.warn('[useCall] Failed to add ICE candidate:', err);
        });
      }
    });

    return unsub;
  }, [userId, guardedPatch, buildEngine, destroyEngine, resetToIdle]);

  // ── Browser refresh / tab close cleanup ─────────

  useEffect(() => {
    const onBeforeUnload = () => {
      const { call, phase } = stateRef.current;
      if (!call) return;

      // Attempt to notify the server that the call ended.
      // navigator.sendBeacon is fire-and-forget and works during unload.
      const activePhases: CallPhase[] = [
        'ringing-outgoing', 'ringing-incoming', 'connecting', 'active',
      ];
      if (!activePhases.includes(phase)) return;

      // Determine the right endpoint for the current call state
      const base = '/chat-api/public/api';
      let endpoint: string;
      if (phase === 'ringing-outgoing') {
        endpoint = `${base}/calls/${call.id}/cancel`;
      } else if (phase === 'ringing-incoming') {
        endpoint = `${base}/calls/${call.id}/reject`;
      } else {
        endpoint = `${base}/calls/${call.id}/hangup`;
      }

      // sendBeacon sends a POST with the session cookie
      navigator.sendBeacon(endpoint);

      // Also tear down local media immediately
      engineRef.current?.dispose();
    };

    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, []);

  // ── Cleanup on unmount ──────────────────────────

  useEffect(() => {
    return () => {
      destroyEngine();
    };
  }, [destroyEngine]);

  return {
    callState,
    startCall,
    acceptCall,
    rejectCall,
    cancelCall,
    hangup,
    toggleMute,
    refreshDevices,
    switchAudioInput,
    switchAudioOutput,
    checkMicPermission,
  };
}
