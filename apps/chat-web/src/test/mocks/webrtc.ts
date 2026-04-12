/**
 * WebRTC browser API mocks for Vitest / jsdom.
 *
 * Usage:
 *   import { installWebRTCMocks, getLastPeerConnection } from '../mocks/webrtc';
 *   beforeEach(() => installWebRTCMocks());
 */

// ── Helpers ──────────────────────────────────────

type AnyFn = (...args: unknown[]) => unknown;

function makeEventTarget() {
  const listeners = new Map<string, Set<AnyFn>>();
  return {
    addEventListener(type: string, fn: AnyFn) {
      if (!listeners.has(type)) listeners.set(type, new Set());
      listeners.get(type)!.add(fn);
    },
    removeEventListener(type: string, fn: AnyFn) {
      listeners.get(type)?.delete(fn);
    },
    dispatchEvent(type: string, event: unknown) {
      listeners.get(type)?.forEach((fn) => fn(event));
    },
  };
}

// ── Mock RTCPeerConnection ────────────────────────

export const createdPeerConnections: MockRTCPeerConnection[] = [];

export class MockRTCPeerConnection {
  iceConnectionState: RTCIceConnectionState = 'new';
  localDescription: RTCSessionDescriptionInit | null = null;
  remoteDescription: RTCSessionDescriptionInit | null = null;

  onicecandidate: ((ev: { candidate: RTCIceCandidate | null }) => void) | null = null;
  oniceconnectionstatechange: (() => void) | null = null;
  ontrack: ((ev: { track: MediaStreamTrack; streams: MediaStream[] }) => void) | null = null;

  private senders: { track: MediaStreamTrack | null; replaceTrack: (t: MediaStreamTrack | null) => Promise<void> }[] = [];

  constructor(public config?: RTCConfiguration) {
    createdPeerConnections.push(this);
  }

  createOffer = vi.fn().mockResolvedValue({ type: 'offer', sdp: 'mock-sdp-offer' });
  createAnswer = vi.fn().mockResolvedValue({ type: 'answer', sdp: 'mock-sdp-answer' });

  setLocalDescription = vi.fn().mockImplementation((desc: RTCSessionDescriptionInit) => {
    this.localDescription = desc;
    return Promise.resolve();
  });

  setRemoteDescription = vi.fn().mockImplementation((desc: RTCSessionDescriptionInit) => {
    this.remoteDescription = desc;
    return Promise.resolve();
  });

  addIceCandidate = vi.fn().mockResolvedValue(undefined);

  addTrack = vi.fn().mockImplementation((track: MediaStreamTrack) => {
    const sender = {
      track,
      replaceTrack: vi.fn().mockResolvedValue(undefined),
    };
    this.senders.push(sender);
    return sender;
  });

  getSenders = vi.fn().mockImplementation(() => this.senders);

  restartIce = vi.fn();

  close = vi.fn().mockImplementation(() => {
    this.iceConnectionState = 'closed';
  });

  // Helper to simulate ICE state changes in tests
  simulateIceState(state: RTCIceConnectionState) {
    this.iceConnectionState = state;
    this.oniceconnectionstatechange?.();
  }

  // Helper to simulate an incoming track from the remote peer
  simulateRemoteTrack(track: MediaStreamTrack, stream: MediaStream) {
    this.ontrack?.({ track, streams: [stream] });
  }

  // Helper to simulate an ICE candidate
  simulateIceCandidate(candidate: RTCIceCandidate | null) {
    this.onicecandidate?.({ candidate });
  }
}

// ── Mock MediaStream / MediaStreamTrack ──────────

export class MockMediaStreamTrack {
  kind: string;
  enabled = true;
  id = `mock-track-${Math.random().toString(36).slice(2)}`;
  readyState: 'live' | 'ended' = 'live';

  stop = vi.fn().mockImplementation(() => {
    this.readyState = 'ended';
  });

  constructor(kind: 'audio' | 'video' = 'audio') {
    this.kind = kind;
  }
}

export class MockMediaStream {
  id = `mock-stream-${Math.random().toString(36).slice(2)}`;
  private tracks: MockMediaStreamTrack[] = [];

  constructor(tracks?: MockMediaStreamTrack[]) {
    if (tracks) this.tracks = [...tracks];
  }

  getAudioTracks = vi.fn().mockImplementation(() =>
    this.tracks.filter((t) => t.kind === 'audio'),
  );

  getVideoTracks = vi.fn().mockImplementation(() =>
    this.tracks.filter((t) => t.kind === 'video'),
  );

  getTracks = vi.fn().mockImplementation(() => [...this.tracks]);

  addTrack = vi.fn().mockImplementation((track: MockMediaStreamTrack) => {
    this.tracks.push(track);
  });

  removeTrack = vi.fn().mockImplementation((track: MockMediaStreamTrack) => {
    this.tracks = this.tracks.filter((t) => t !== track);
  });
}

// ── Install / reset ───────────────────────────────

let getUserMediaMock: ReturnType<typeof vi.fn>;

export function installWebRTCMocks() {
  createdPeerConnections.length = 0;

  // RTCPeerConnection global
  vi.stubGlobal('RTCPeerConnection', MockRTCPeerConnection);

  // MediaStream global
  vi.stubGlobal('MediaStream', MockMediaStream);

  // Expose track class so tests can instantiate explicit remote tracks.
  vi.stubGlobal('MockMediaStreamTrack', MockMediaStreamTrack);

  // getUserMedia returns a stream with one audio track
  const audioTrack = new MockMediaStreamTrack('audio');
  const mockStream = new MockMediaStream([audioTrack]);
  getUserMediaMock = vi.fn().mockResolvedValue(mockStream);

  vi.stubGlobal('navigator', {
    ...navigator,
    mediaDevices: {
      getUserMedia: getUserMediaMock,
      enumerateDevices: vi.fn().mockResolvedValue([
        { deviceId: 'default', kind: 'audioinput', label: 'Mikrofon (Standard)', groupId: '' },
        { deviceId: 'default', kind: 'audiooutput', label: 'Lautsprecher (Standard)', groupId: '' },
      ]),
    },
    sendBeacon: vi.fn().mockReturnValue(true),
    permissions: {
      query: vi.fn().mockResolvedValue({ state: 'granted' }),
    },
  });
}

/** Get the most recently created MockRTCPeerConnection (or null). */
export function getLastPeerConnection(): MockRTCPeerConnection | null {
  return createdPeerConnections[createdPeerConnections.length - 1] ?? null;
}

/** Get the getUserMedia spy for assertions. */
export function getUserMediaSpy() {
  return getUserMediaMock;
}

/** Simulate getUserMedia rejection (e.g. mic denied). */
export function mockGetUserMediaError(name: string, message = 'Denied') {
  const err = Object.assign(new Error(message), { name });
  getUserMediaMock.mockRejectedValueOnce(err);
}
