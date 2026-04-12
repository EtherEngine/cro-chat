import type { RealtimeClient } from '../../realtime/socket';

// ── Types ───────────────────────────────────────────────────

export type CallRole = 'caller' | 'callee';

export type IceConnectionState = RTCIceConnectionState;

/**
 * Structured media error codes surfaced to the UI.
 *
 * - `microphone_denied`   – user clicked "Deny" on the permission prompt
 * - `permission_blocked`  – browser policy permanently blocks mic access (e.g. site setting)
 * - `device_not_found`    – no audio input device / device unplugged
 * - `device_in_use`       – OS/browser can't access the device (another app holds it)
 * - `connection_lost`     – WebRTC ICE connection dropped after being established
 */
export type MediaErrorCode =
  | 'microphone_denied'
  | 'permission_blocked'
  | 'device_not_found'
  | 'device_in_use'
  | 'connection_lost';

export interface MediaError {
  code: MediaErrorCode;
  message: string;
}

/** Simplified descriptor for audio devices. */
export interface AudioDevice {
  deviceId: string;
  label: string;
  kind: 'audioinput' | 'audiooutput';
}

export type CallEngineCallbacks = {
  /** ICE connection state changed (checking, connected, disconnected, failed …) */
  onIceStateChange: (state: IceConnectionState) => void;
  /** Remote audio track is available — attach to an <audio> element */
  onRemoteStream: (stream: MediaStream) => void;
  /** A structured media / connection error occurred */
  onMediaError: (error: MediaError) => void;
  /** ICE restart initiated — callee receives a new offer */
  onIceRestart?: () => void;
  /**
   * @deprecated Use onMediaError for structured errors. Kept for backward compat.
   * A fatal error occurred (media denied, ICE failure, etc.)
   */
  onError: (error: Error) => void;
};

/**
 * Common interface implemented by both CallEngine (real WebRTC) and
 * SimulatedCallEngine (dev-only synthetic audio stub).
 *
 * useCall.ts types its engine ref against this interface so that both
 * implementations can be swapped without changing call-site code.
 */
export interface ICallEngine {
  start(): Promise<void>;
  createOffer(): Promise<void>;
  handleOffer(sdp: string): Promise<void>;
  handleAnswer(sdp: string): Promise<void>;
  addIceCandidate(candidate: RTCIceCandidateInit): Promise<void>;
  toggleMute(): boolean;
  setMuted(muted: boolean): void;
  switchAudioInput(deviceId: string): Promise<void>;
  dispose(): void;
  readonly isMuted: boolean;
  readonly iceState: IceConnectionState | null;
}

// ── Config ──────────────────────────────────────────────────

/**
 * Fallback used when the backend ICE config endpoint is unreachable.
 * Public STUN only — sufficient for development / open NATs.
 */
const FALLBACK_RTC_CONFIG: RTCConfiguration = {
  iceServers: [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
  ],
};

// ── CallEngine ──────────────────────────────────────────────

/**
 * Low-level WebRTC engine for a single 1:1 audio call.
 *
 * Responsibilities:
 *  - Acquire local microphone stream
 *  - Create and manage an RTCPeerConnection
 *  - Generate SDP offer / answer
 *  - Exchange ICE candidates via the existing RealtimeClient
 *  - Expose mute/unmute toggle
 *  - Clean up all resources on dispose
 *
 * Does NOT own call lifecycle (initiate/accept/reject/hangup) —
 * that's handled by the REST API and the useCall hook.
 */
export class CallEngine {
  private pc: RTCPeerConnection | null = null;
  private localStream: MediaStream | null = null;
  private remoteStream: MediaStream | null = null;
  private disposed = false;

  /** Buffered ICE candidates received before remote description was set. */
  private pendingCandidates: RTCIceCandidateInit[] = [];

  /** Timer for ICE disconnected → connection_lost escalation. */
  private iceDisconnectTimer: ReturnType<typeof setTimeout> | null = null;

  /** Whether ICE was ever in 'connected' or 'completed' state. */
  private wasConnected = false;

  /** Number of ICE restart attempts made for this call. */
  private iceRestartCount = 0;

  /** Maximum ICE restart attempts before giving up. */
  private static readonly MAX_ICE_RESTARTS = 2;

  /** Whether an offer has already been processed (duplicate offer guard). */
  private offerProcessed = false;

  /** The RTCConfiguration used for this call (ICE servers + transport policy). */
  private rtcConfig: RTCConfiguration;

  constructor(
    private callId: number,
    private conversationId: number,
    private targetUserId: number,
    private role: CallRole,
    private realtime: RealtimeClient,
    private callbacks: CallEngineCallbacks,
    rtcConfig?: RTCConfiguration,
  ) {
    this.rtcConfig = rtcConfig ?? FALLBACK_RTC_CONFIG;
  }

  // ── Public API ─────────────────────────────────

  /**
   * Initialize the engine: acquire microphone, create peer connection.
   * For the caller, also creates and returns the SDP offer.
   * For the callee, just prepares the connection (call setRemoteOffer + createAnswer later).
   */
  async start(): Promise<void> {
    if (this.disposed) return;

    // 1. Acquire microphone with structured error classification
    try {
      this.localStream = await navigator.mediaDevices.getUserMedia({
        audio: true,
        video: false,
      });
    } catch (err) {
      const mediaError = CallEngine.classifyMediaError(err);
      this.callbacks.onMediaError(mediaError);
      this.callbacks.onError(new Error(mediaError.message));
      return;
    }

    // 2. Create peer connection with dynamic ICE config
    this.pc = new RTCPeerConnection(this.rtcConfig);

    // 3. Add local audio tracks to the connection
    for (const track of this.localStream.getAudioTracks()) {
      this.pc.addTrack(track, this.localStream);
    }

    // 4. Wire up event handlers
    this.pc.onicecandidate = (ev) => {
      if (ev.candidate) {
        this.sendSignal('ice_candidate', { candidate: ev.candidate.toJSON() });
      }
    };

    this.pc.oniceconnectionstatechange = () => {
      if (!this.pc) return;
      const ice = this.pc.iceConnectionState;
      this.callbacks.onIceStateChange(ice);

      if (ice === 'connected' || ice === 'completed') {
        this.wasConnected = true;
        this.clearIceDisconnectTimer();
      }

      if (ice === 'disconnected' && this.wasConnected) {
        // Attempt ICE restart before escalating to connection_lost
        this.startIceDisconnectTimer();
      }

      if (ice === 'failed') {
        this.clearIceDisconnectTimer();
        // Try ICE restart before declaring connection_lost
        if (this.attemptIceRestart()) return;

        const error: MediaError = {
          code: 'connection_lost',
          message: 'Verbindung zum Gesprächspartner verloren',
        };
        this.callbacks.onMediaError(error);
        this.callbacks.onError(new Error(error.message));
      }
    };

    this.pc.ontrack = (ev) => {
      if (!this.remoteStream) {
        this.remoteStream = new MediaStream();
        this.callbacks.onRemoteStream(this.remoteStream);
      }
      this.remoteStream.addTrack(ev.track);
    };
  }

  /**
   * Caller only: create an SDP offer and send it to the callee.
   * Must be called after start().
   */
  async createOffer(): Promise<void> {
    if (!this.pc || this.disposed) return;

    const offer = await this.pc.createOffer();
    await this.pc.setLocalDescription(offer);

    this.sendSignal('offer', { sdp: offer.sdp });
  }

  /**
   * Callee only: set the remote SDP offer received from the caller,
   * create an SDP answer, and send it back.
   */
  async handleOffer(sdp: string): Promise<void> {
    if (!this.pc || this.disposed) return;

    // Guard against duplicate offers (only the first one is valid)
    if (this.offerProcessed) {
      console.warn('[CallEngine] Duplicate offer ignored');
      return;
    }
    this.offerProcessed = true;

    await this.pc.setRemoteDescription({ type: 'offer', sdp });
    await this.flushPendingCandidates();

    const answer = await this.pc.createAnswer();
    await this.pc.setLocalDescription(answer);

    this.sendSignal('answer', { sdp: answer.sdp });
  }

  /**
   * Caller only: set the remote SDP answer received from the callee.
   */
  async handleAnswer(sdp: string): Promise<void> {
    if (!this.pc || this.disposed) return;

    await this.pc.setRemoteDescription({ type: 'answer', sdp });
    await this.flushPendingCandidates();
  }

  /**
   * Add a remote ICE candidate received via signaling.
   * If the remote description isn't set yet, buffers the candidate.
   */
  async addIceCandidate(candidate: RTCIceCandidateInit): Promise<void> {
    if (!this.pc || this.disposed) return;

    if (!this.pc.remoteDescription) {
      this.pendingCandidates.push(candidate);
      return;
    }

    try {
      await this.pc.addIceCandidate(new RTCIceCandidate(candidate));
    } catch (err) {
      console.warn('[CallEngine] Failed to add ICE candidate:', err);
    }
  }

  /**
   * Toggle local microphone mute.
   * Returns the new muted state.
   */
  toggleMute(): boolean {
    if (!this.localStream) return false;

    const track = this.localStream.getAudioTracks()[0];
    if (!track) return false;

    track.enabled = !track.enabled;
    return !track.enabled; // muted = !enabled
  }

  /**
   * Set mute state explicitly.
   */
  setMuted(muted: boolean): void {
    if (!this.localStream) return;

    const track = this.localStream.getAudioTracks()[0];
    if (track) {
      track.enabled = !muted;
    }
  }

  /**
   * Switch the audio input device mid-call.
   * Replaces the local audio track on the peer connection.
   */
  async switchAudioInput(deviceId: string): Promise<void> {
    if (!this.pc || !this.localStream || this.disposed) return;

    // Get new stream from the selected device
    let newStream: MediaStream;
    try {
      newStream = await navigator.mediaDevices.getUserMedia({
        audio: { deviceId: { exact: deviceId } },
        video: false,
      });
    } catch (err) {
      const mediaError = CallEngine.classifyMediaError(err);
      this.callbacks.onMediaError(mediaError);
      return;
    }

    const newTrack = newStream.getAudioTracks()[0];
    if (!newTrack) return;

    // Preserve current mute state
    const wasMuted = this.isMuted;
    newTrack.enabled = !wasMuted;

    // Replace the track on the peer connection sender
    const sender = this.pc.getSenders().find((s) => s.track?.kind === 'audio');
    if (sender) {
      await sender.replaceTrack(newTrack);
    }

    // Stop old tracks and swap the stream
    for (const t of this.localStream.getAudioTracks()) {
      t.stop();
      this.localStream.removeTrack(t);
    }
    this.localStream.addTrack(newTrack);
  }

  // ── Static helpers ─────────────────────────────

  /**
   * Enumerate available audio input and output devices.
   * Requires at least one prior getUserMedia call for labels to be populated.
   */
  static async enumerateAudioDevices(): Promise<AudioDevice[]> {
    try {
      const devices = await navigator.mediaDevices.enumerateDevices();
      return devices
        .filter((d) => d.kind === 'audioinput' || d.kind === 'audiooutput')
        .map((d) => ({
          deviceId: d.deviceId,
          label: d.label || (d.kind === 'audioinput' ? 'Mikrofon' : 'Lautsprecher'),
          kind: d.kind as 'audioinput' | 'audiooutput',
        }));
    } catch {
      return [];
    }
  }

  /**
   * Check microphone permission state without prompting the user.
   * Returns 'granted', 'denied', 'prompt', or null if API unavailable.
   */
  static async checkMicPermission(): Promise<PermissionState | null> {
    try {
      const result = await navigator.permissions.query({ name: 'microphone' as PermissionName });
      return result.state;
    } catch {
      return null;
    }
  }

  /**
   * Build an RTCConfiguration from the backend ICE server response.
   *
   * The backend returns `{ ice_servers, ice_transport_policy }` where
   * ice_servers contain time-limited TURN credentials (HMAC-SHA1).
   *
   * Falls back to public STUN if the response is empty or malformed.
   */
  static buildRtcConfig(response: {
    ice_servers: Array<{ urls: string; username?: string; credential?: string }>;
    ice_transport_policy?: RTCIceTransportPolicy;
  }): RTCConfiguration {
    const servers = response.ice_servers;
    if (!servers || servers.length === 0) {
      return FALLBACK_RTC_CONFIG;
    }

    return {
      iceServers: servers.map((s) => ({
        urls: s.urls,
        ...(s.username ? { username: s.username } : {}),
        ...(s.credential ? { credential: s.credential } : {}),
      })),
      iceTransportPolicy: response.ice_transport_policy ?? 'all',
    };
  }

  /**
   * Classify a getUserMedia error into a structured MediaErrorCode.
   */
  static classifyMediaError(err: unknown): MediaError {
    const e = err as DOMException;
    const name = e?.name ?? '';

    switch (name) {
      case 'NotAllowedError':
        // Distinguish soft deny (prompt dismissed) from hard block (site setting)
        return {
          code: 'microphone_denied',
          message: 'Mikrofonzugriff wurde verweigert. Bitte erlaube den Zugriff in den Browser-Einstellungen.',
        };

      case 'NotFoundError':
        return {
          code: 'device_not_found',
          message: 'Kein Mikrofon gefunden. Bitte schließe ein Mikrofon an.',
        };

      case 'OverconstrainedError':
        return {
          code: 'device_not_found',
          message: 'Das gewählte Audiogerät ist nicht verfügbar.',
        };

      case 'NotReadableError':
      case 'AbortError':
        return {
          code: 'device_in_use',
          message: 'Das Mikrofon wird von einer anderen Anwendung verwendet.',
        };

      default:
        return {
          code: 'microphone_denied',
          message: `Mikrofon-Zugriff fehlgeschlagen: ${e?.message ?? 'Unbekannter Fehler'}`,
        };
    }
  }

  /**
   * Whether local audio is currently muted.
   */
  get isMuted(): boolean {
    const track = this.localStream?.getAudioTracks()[0];
    return track ? !track.enabled : true;
  }

  /**
   * Current ICE connection state, or null if no peer connection.
   */
  get iceState(): IceConnectionState | null {
    return this.pc?.iceConnectionState ?? null;
  }

  /**
   * Tear down everything: close peer connection, stop media tracks, clear buffers.
   * Safe to call multiple times.
   */
  dispose(): void {
    if (this.disposed) return;
    this.disposed = true;

    this.clearIceDisconnectTimer();

    // Stop all local media tracks (releases microphone)
    if (this.localStream) {
      for (const track of this.localStream.getTracks()) {
        track.stop();
      }
      this.localStream = null;
    }

    // Close peer connection
    if (this.pc) {
      this.pc.onicecandidate = null;
      this.pc.oniceconnectionstatechange = null;
      this.pc.ontrack = null;
      this.pc.close();
      this.pc = null;
    }

    this.remoteStream = null;
    this.pendingCandidates = [];
  }

  // ── Internal ───────────────────────────────────

  /**
   * Start a 5-second timer after ICE goes to 'disconnected'.
   * If ICE doesn't recover, attempt restart or fire connection_lost.
   */
  private startIceDisconnectTimer(): void {
    this.clearIceDisconnectTimer();
    this.iceDisconnectTimer = setTimeout(() => {
      if (this.disposed || !this.pc) return;
      if (this.pc.iceConnectionState === 'disconnected' || this.pc.iceConnectionState === 'failed') {
        // Try ICE restart first
        if (this.attemptIceRestart()) return;

        const error: MediaError = {
          code: 'connection_lost',
          message: 'Verbindung zum Gesprächspartner verloren',
        };
        this.callbacks.onMediaError(error);
        this.callbacks.onError(new Error(error.message));
      }
    }, 5000);
  }

  private clearIceDisconnectTimer(): void {
    if (this.iceDisconnectTimer) {
      clearTimeout(this.iceDisconnectTimer);
      this.iceDisconnectTimer = null;
    }
  }

  /**
   * Attempt an ICE restart if we haven't exceeded the max retry count.
   * Returns true if restart was initiated, false if retries exhausted.
   *
   * Only the ICE-controlling peer (the original offerer = caller) creates a new
   * offer with iceRestart: true.  The callee only resets its local state and
   * waits for the caller's restart offer to arrive via handleOffer() — calling
   * restartIce() on the callee side would trigger unnecessary ICE gathering
   * without a corresponding offer, which has no useful effect.
   */
  private attemptIceRestart(): boolean {
    if (!this.pc || this.disposed) return false;
    if (this.iceRestartCount >= CallEngine.MAX_ICE_RESTARTS) return false;

    this.iceRestartCount++;
    console.info(`[CallEngine] ICE restart attempt ${this.iceRestartCount}/${CallEngine.MAX_ICE_RESTARTS}`);

    // Allow re-processing new offer/answer after restart
    this.offerProcessed = false;

    if (this.role === 'caller') {
      // Caller (ICE controller): trigger a full ICE restart by creating a new offer
      this.pc.restartIce();
      this.pc.createOffer({ iceRestart: true })
        .then((offer) => this.pc!.setLocalDescription(offer))
        .then(() => {
          this.sendSignal('offer', { sdp: this.pc!.localDescription!.sdp });
        })
        .catch((err) => {
          console.warn('[CallEngine] ICE restart offer failed:', err);
        });
    }
    // Callee: offerProcessed is already reset above.  Wait for the caller's
    // restart offer which will arrive via handleOffer() and drive the restart.

    this.callbacks.onIceRestart?.();
    return true;
  }

  /**
   * Send a signaling message to the target user via the WebSocket relay.
   * Includes a unique nonce for server-side replay protection.
   */
  private sendSignal(signalType: string, payload: Record<string, unknown>): void {
    const nonce =
      typeof crypto !== 'undefined' && crypto.randomUUID
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

    this.realtime.send({
      action: 'call.signal',
      call_id: this.callId,
      conversation_id: this.conversationId,
      target_user_id: this.targetUserId,
      signal_type: signalType,
      payload,
      nonce,
    });
  }

  /**
   * Flush any ICE candidates that arrived before the remote description was set.
   */
  private async flushPendingCandidates(): Promise<void> {
    const candidates = this.pendingCandidates;
    this.pendingCandidates = [];

    for (const c of candidates) {
      try {
        await this.pc!.addIceCandidate(new RTCIceCandidate(c));
      } catch (err) {
        console.warn('[CallEngine] Failed to flush pending ICE candidate:', err);
      }
    }
  }
}
