import type {
  CallRole,
  ICallEngine,
  IceConnectionState,
  CallEngineCallbacks,
} from '../CallEngine';

/**
 * Simulation-only drop-in replacement for CallEngine.
 *
 * Used when `import.meta.env.VITE_CALL_SIMULATION === 'true'`.
 *
 * What it does differently from the real CallEngine:
 *  - `start()`       — creates a silent AudioContext oscillator track instead
 *                      of calling getUserMedia (no microphone permission needed)
 *  - `createOffer()` — fires onIceStateChange('connected') after 800 ms,
 *                      simulating a successful ICE negotiation with no peer
 *  - `handleOffer()` — same as createOffer() (ignores the SDP value)
 *  - `handleAnswer()`, `addIceCandidate()` — no-ops
 *  - All other methods — identical semantics to CallEngine
 *
 * The public interface is identical to CallEngine (both implement ICallEngine)
 * so useCall.ts can use it interchangeably.
 *
 * ⚠️  Never instantiated in production.  Import of this file is guarded by
 *     the VITE_CALL_SIMULATION flag; Vite will tree-shake it in prod builds
 *     where the flag is not set.
 */
export class SimulatedCallEngine implements ICallEngine {
  private audioCtx: AudioContext | null = null;
  private localStream: MediaStream | null = null;
  private remoteStream: MediaStream | null = null;
  private connectTimer: ReturnType<typeof setTimeout> | null = null;
  private disposed = false;

  constructor(
    private readonly callId: number,
    _conversationId: number,
    _targetUserId: number,
    private readonly role: CallRole,
    _realtime: unknown,             // kept for interface compat, not used
    private readonly callbacks: CallEngineCallbacks,
    _rtcConfig?: RTCConfiguration,  // kept for interface compat, not used
  ) {}

  // ── Lifecycle ─────────────────────────────────────────────

  async start(): Promise<void> {
    if (this.disposed) return;
    console.info(`[SimulatedCallEngine] start() call=${this.callId} role=${this.role} — synthetic audio, no microphone`);

    // Local stream: silent oscillator (frequency=0 → inaudible)
    this.localStream = this.createSilentStream() ?? new MediaStream();

    // Remote stream: a second silent oscillator so the <audio> element has something
    const remoteStream = this.createSilentStream() ?? new MediaStream();
    this.remoteStream  = remoteStream;
    this.callbacks.onRemoteStream(remoteStream);
  }

  /** Caller path: simulate ICE connecting → connected after 800 ms. */
  async createOffer(): Promise<void> {
    if (this.disposed) return;
    console.info('[SimulatedCallEngine] createOffer() — scheduling simulated ICE connect');
    this.scheduleConnect();
  }

  /**
   * Callee path: simulate ICE connecting → connected after 800 ms.
   * The SDP value is intentionally ignored — there is no real peer.
   */
  async handleOffer(_sdp: string): Promise<void> {
    if (this.disposed) return;
    console.info('[SimulatedCallEngine] handleOffer() — scheduling simulated ICE connect');
    this.scheduleConnect();
  }

  /** No-op: no real remote description to apply. */
  async handleAnswer(_sdp: string): Promise<void> {}

  /** No-op: no real ICE gathering in progress. */
  async addIceCandidate(_candidate: RTCIceCandidateInit): Promise<void> {}

  // ── Audio control ─────────────────────────────────────────

  toggleMute(): boolean {
    const track = this.localStream?.getAudioTracks()[0];
    if (!track) return true;
    track.enabled = !track.enabled;
    return !track.enabled; // muted = !enabled
  }

  setMuted(muted: boolean): void {
    const track = this.localStream?.getAudioTracks()[0];
    if (track) track.enabled = !muted;
  }

  async switchAudioInput(_deviceId: string): Promise<void> {
    console.info('[SimulatedCallEngine] switchAudioInput() — no-op in simulation');
  }

  // ── Getters ───────────────────────────────────────────────

  get isMuted(): boolean {
    const track = this.localStream?.getAudioTracks()[0];
    return track ? !track.enabled : true;
  }

  /** Always 'connected' once start() has been called (as seen by the UI). */
  get iceState(): IceConnectionState | null {
    return this.disposed ? null : 'connected';
  }

  // ── Dispose ───────────────────────────────────────────────

  dispose(): void {
    if (this.disposed) return;
    this.disposed = true;

    if (this.connectTimer) {
      clearTimeout(this.connectTimer);
      this.connectTimer = null;
    }

    this.localStream?.getTracks().forEach((t) => t.stop());
    this.remoteStream?.getTracks().forEach((t) => t.stop());
    this.audioCtx?.close().catch(() => {});

    this.audioCtx    = null;
    this.localStream = null;
    this.remoteStream = null;

    console.info('[SimulatedCallEngine] disposed');
  }

  // ── Internal helpers ──────────────────────────────────────

  /**
   * Schedule a simulated ICE 'connected' event after a short delay.
   * Idempotent: if already scheduled, the existing timer runs.
   */
  private scheduleConnect(): void {
    if (this.connectTimer !== null) return; // already scheduled

    this.connectTimer = setTimeout(() => {
      this.connectTimer = null;
      if (this.disposed) return;
      console.info('[SimulatedCallEngine] Simulated ICE → connected');
      this.callbacks.onIceStateChange('connected');
    }, 800);
  }

  /**
   * Create a silent MediaStream from an AudioContext oscillator.
   * Returns null when AudioContext is not available (e.g. jsdom in unit tests).
   */
  private createSilentStream(): MediaStream | null {
    try {
      const ctx  = new AudioContext();
      const osc  = ctx.createOscillator();
      osc.frequency.value = 0; // below audible range → silent
      const dest = ctx.createMediaStreamDestination();
      osc.connect(dest);
      osc.start();

      // Keep one AudioContext reference for cleanup (use the first one)
      if (!this.audioCtx) this.audioCtx = ctx;

      return dest.stream;
    } catch {
      return null;
    }
  }
}
