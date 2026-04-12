import { describe, it, expect, beforeEach, vi } from 'vitest';
import { CallEngine } from '../CallEngine';
import {
  installWebRTCMocks,
  getLastPeerConnection,
  mockGetUserMediaError,
  MockRealtimeClient as _MockRealtimeClient,
} from '../../../test/mocks/webrtc';
import { MockRealtimeClient } from '../../../test/mocks/realtime';

// ── Helpers ──────────────────────────────────────

function makeEngine(role: 'caller' | 'callee' = 'caller', callbacks?: Partial<ConstructorParameters<typeof CallEngine>[5]>) {
  const realtime = new MockRealtimeClient();
  const cbs = {
    onIceStateChange: vi.fn(),
    onRemoteStream: vi.fn(),
    onMediaError: vi.fn(),
    onError: vi.fn(),
    ...callbacks,
  };
  const engine = new CallEngine(
    1,        // callId
    10,       // conversationId
    2,        // targetUserId
    role,
    realtime as unknown as import('../../../realtime/socket').RealtimeClient,
    cbs,
  );
  return { engine, realtime, cbs };
}

// ── Suite ─────────────────────────────────────────

describe('CallEngine.classifyMediaError()', () => {
  it('classifies NotAllowedError as microphone_denied', () => {
    const err = Object.assign(new Error('Denied'), { name: 'NotAllowedError' });
    const result = CallEngine.classifyMediaError(err);
    expect(result.code).toBe('microphone_denied');
  });

  it('classifies NotFoundError as device_not_found', () => {
    const err = Object.assign(new Error('Not found'), { name: 'NotFoundError' });
    const result = CallEngine.classifyMediaError(err);
    expect(result.code).toBe('device_not_found');
  });

  it('classifies OverconstrainedError as device_not_found', () => {
    const err = Object.assign(new Error('Overconstrained'), { name: 'OverconstrainedError' });
    const result = CallEngine.classifyMediaError(err);
    expect(result.code).toBe('device_not_found');
  });

  it('classifies NotReadableError as device_in_use', () => {
    const err = Object.assign(new Error('Not readable'), { name: 'NotReadableError' });
    const result = CallEngine.classifyMediaError(err);
    expect(result.code).toBe('device_in_use');
  });

  it('classifies AbortError as device_in_use', () => {
    const err = Object.assign(new Error('Abort'), { name: 'AbortError' });
    const result = CallEngine.classifyMediaError(err);
    expect(result.code).toBe('device_in_use');
  });

  it('falls back to microphone_denied for unknown error name', () => {
    const err = Object.assign(new Error('Something else'), { name: 'UnknownError' });
    const result = CallEngine.classifyMediaError(err);
    expect(result.code).toBe('microphone_denied');
  });

  it('handles null/undefined gracefully', () => {
    const result = CallEngine.classifyMediaError(null);
    expect(result.code).toBe('microphone_denied');
  });
});

describe('CallEngine.buildRtcConfig()', () => {
  it('returns fallback STUN config when ice_servers is empty', () => {
    const config = CallEngine.buildRtcConfig({ ice_servers: [] });
    expect(config.iceServers).toBeDefined();
    expect((config.iceServers as RTCIceServer[])[0].urls).toContain('stun:');
  });

  it('maps ice_servers to RTCConfiguration correctly', () => {
    const config = CallEngine.buildRtcConfig({
      ice_servers: [
        { urls: 'turn:turn.example.com:3478', username: 'user1', credential: 'secret' },
      ],
    });
    const server = (config.iceServers as RTCIceServer[])[0];
    expect(server.urls).toBe('turn:turn.example.com:3478');
    expect(server.username).toBe('user1');
    expect(server.credential).toBe('secret');
  });

  it('respects ice_transport_policy', () => {
    const config = CallEngine.buildRtcConfig({
      ice_servers: [{ urls: 'turn:example.com' }],
      ice_transport_policy: 'relay',
    });
    expect(config.iceTransportPolicy).toBe('relay');
  });

  it('defaults iceTransportPolicy to "all" when not specified', () => {
    const config = CallEngine.buildRtcConfig({
      ice_servers: [{ urls: 'turn:example.com' }],
    });
    expect(config.iceTransportPolicy).toBe('all');
  });
});

describe('CallEngine — lifecycle', () => {
  beforeEach(() => {
    installWebRTCMocks();
  });

  it('start() acquires microphone and creates RTCPeerConnection', async () => {
    const { engine } = makeEngine();
    await engine.start();
    expect(getLastPeerConnection()).not.toBeNull();
  });

  it('start() calls onMediaError when getUserMedia is denied', async () => {
    mockGetUserMediaError('NotAllowedError');
    const { engine, cbs } = makeEngine();
    await engine.start();
    expect(cbs.onMediaError).toHaveBeenCalledWith(
      expect.objectContaining({ code: 'microphone_denied' }),
    );
  });

  it('createOffer() sends an offer via realtime signaling', async () => {
    const { engine, realtime } = makeEngine('caller');
    await engine.start();
    await engine.createOffer();
    const signals = realtime.sentMessages;
    const offerSignal = signals.find((m) => m.action === 'call.signal');
    expect(offerSignal).toBeDefined();
    expect((offerSignal as Record<string, unknown>).signal_type).toBe('offer');
  });

  it('handleOffer() creates and sends an answer', async () => {
    const { engine, realtime } = makeEngine('callee');
    await engine.start();
    await engine.handleOffer('remote-sdp-offer');
    const signals = realtime.sentMessages;
    const answerSignal = signals.find((m) => (m as Record<string, unknown>).signal_type === 'answer');
    expect(answerSignal).toBeDefined();
  });

  it('handleOffer() ignores duplicate offers', async () => {
    const { engine } = makeEngine('callee');
    await engine.start();
    await engine.handleOffer('first-offer');
    const pc = getLastPeerConnection()!;
    const callsBefore = pc.setRemoteDescription.mock.calls.length;
    await engine.handleOffer('duplicate-offer');
    expect(pc.setRemoteDescription).toHaveBeenCalledTimes(callsBefore); // no extra call
  });

  it('handleAnswer() sets remote description on the peer connection', async () => {
    const { engine } = makeEngine('caller');
    await engine.start();
    await engine.createOffer();
    await engine.handleAnswer('remote-sdp-answer');
    const pc = getLastPeerConnection()!;
    expect(pc.setRemoteDescription).toHaveBeenCalledWith(
      expect.objectContaining({ type: 'answer', sdp: 'remote-sdp-answer' }),
    );
  });

  it('addIceCandidate() buffers candidates when no remote description is set', async () => {
    const { engine } = makeEngine('caller');
    await engine.start();
    const candidate: RTCIceCandidateInit = { candidate: 'candidate:1', sdpMid: '0', sdpMLineIndex: 0 };
    // No remote desc set → should buffer, not throw
    await expect(engine.addIceCandidate(candidate)).resolves.not.toThrow();
  });

  it('toggleMute() mutes local audio track', async () => {
    const { engine } = makeEngine();
    await engine.start();
    const muted = engine.toggleMute();
    expect(muted).toBe(true);
    expect(engine.isMuted).toBe(true);
  });

  it('toggleMute() unmutes when already muted', async () => {
    const { engine } = makeEngine();
    await engine.start();
    engine.toggleMute(); // mute
    const muted = engine.toggleMute(); // unmute
    expect(muted).toBe(false);
    expect(engine.isMuted).toBe(false);
  });

  it('ICE connected state fires onIceStateChange callback', async () => {
    const { engine, cbs } = makeEngine();
    await engine.start();
    const pc = getLastPeerConnection()!;
    pc.simulateIceState('connected');
    expect(cbs.onIceStateChange).toHaveBeenCalledWith('connected');
  });

  it('ICE failed state fires onMediaError with connection_lost', async () => {
    const { engine, cbs } = makeEngine();
    await engine.start();
    const pc = getLastPeerConnection()!;
    // The engine first attempts ICE restart twice before emitting connection_lost.
    pc.simulateIceState('failed');
    pc.simulateIceState('failed');
    pc.simulateIceState('failed');
    expect(cbs.onMediaError).toHaveBeenCalledWith(
      expect.objectContaining({ code: 'connection_lost' }),
    );
  });

  it('ontrack fires onRemoteStream callback', async () => {
    const { engine, cbs } = makeEngine();
    await engine.start();
    const pc = getLastPeerConnection()!;
    const { MockMediaStream: _ignored, MockMediaStreamTrack } = await import('../../../test/mocks/webrtc');
    const track = new (globalThis as unknown as { MockMediaStreamTrack: typeof import('../../../test/mocks/webrtc').MockMediaStreamTrack }).MockMediaStreamTrack('audio');
    const stream = new (globalThis as unknown as { MediaStream: typeof import('../../../test/mocks/webrtc').MockMediaStream }).MediaStream();
    pc.simulateRemoteTrack(track as unknown as MediaStreamTrack, stream as unknown as MediaStream);
    expect(cbs.onRemoteStream).toHaveBeenCalled();
  });

  it('dispose() is idempotent (safe to call twice)', async () => {
    const { engine } = makeEngine();
    await engine.start();
    engine.dispose();
    expect(() => engine.dispose()).not.toThrow();
  });

  it('dispose() stops local media tracks', async () => {
    const { engine } = makeEngine();
    await engine.start();
    const pc = getLastPeerConnection()!;
    engine.dispose();
    expect(pc.close).toHaveBeenCalled();
  });

  it('isMuted returns true when no local stream', () => {
    const { engine } = makeEngine();
    // Not started — no local stream
    expect(engine.isMuted).toBe(true);
  });

  it('iceState returns null before start()', () => {
    const { engine } = makeEngine();
    expect(engine.iceState).toBeNull();
  });
});
