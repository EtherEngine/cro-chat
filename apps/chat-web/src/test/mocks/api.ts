/**
 * Mock for api.calls.* and api.auth.wsTicket.
 * vi.mock() must be called in the test file; this module provides the factory.
 *
 * Usage in test file:
 *   vi.mock('../../api/client', () => import('../mocks/api'));
 *
 * Then override individual methods per-test:
 *   apiMocks.initiate.mockResolvedValueOnce({ call: fakeCall(1, 2) });
 */

import type { Call } from '../../types';

// ── Call fixture ─────────────────────────────────

export function fakeCall(callerId: number, calleeId: number, overrides: Partial<Call> = {}): Call {
  return {
    id: 1,
    conversation_id: 10,
    caller_user_id: callerId,
    callee_user_id: calleeId,
    status: 'ringing',
    started_at: new Date().toISOString(),
    answered_at: null,
    ended_at: null,
    duration_seconds: null,
    end_reason: null,
    created_at: new Date().toISOString(),
    ...overrides,
  };
}

// ── Mock API namespace ────────────────────────────

export const apiMocks = {
  // calls
  initiate: vi.fn(),
  accept: vi.fn(),
  reject: vi.fn(),
  cancel: vi.fn(),
  hangup: vi.fn(),
  iceServers: vi.fn(),
  show: vi.fn(),
  active: vi.fn(),
  history: vi.fn(),

  // auth
  wsTicket: vi.fn(),
};

function defaultResponse(callerId = 1, calleeId = 2) {
  const call = fakeCall(callerId, calleeId);
  return {
    initiate: { call },
    accept: { call: { ...call, status: 'accepted' as const } },
    reject: { call: { ...call, status: 'rejected' as const } },
    cancel: { call: { ...call, status: 'missed' as const } },
    hangup: { call: { ...call, status: 'ended' as const } },
    iceServers: { ice_servers: [] as { urls: string }[], ice_transport_policy: 'all' as RTCIceTransportPolicy },
    wsTicket: { ticket: 'mock-ticket' },
  };
}

/** Reset all mocks to their default resolved values. */
export function resetApiMocks(callerId = 1, calleeId = 2) {
  const defaults = defaultResponse(callerId, calleeId);
  apiMocks.initiate.mockResolvedValue(defaults.initiate);
  apiMocks.accept.mockResolvedValue(defaults.accept);
  apiMocks.reject.mockResolvedValue(defaults.reject);
  apiMocks.cancel.mockResolvedValue(defaults.cancel);
  apiMocks.hangup.mockResolvedValue(defaults.hangup);
  apiMocks.iceServers.mockResolvedValue(defaults.iceServers);
  apiMocks.show.mockResolvedValue({ call: defaults.initiate.call });
  apiMocks.active.mockResolvedValue({ call: null });
  apiMocks.history.mockResolvedValue({ calls: [] });
  apiMocks.wsTicket.mockResolvedValue(defaults.wsTicket);
}

// ── Exported module shape matching ../../api/client ──

export const api = {
  calls: {
    initiate: (conversationId: number) => apiMocks.initiate(conversationId),
    accept: (callId: number) => apiMocks.accept(callId),
    reject: (callId: number) => apiMocks.reject(callId),
    cancel: (callId: number) => apiMocks.cancel(callId),
    hangup: (callId: number) => apiMocks.hangup(callId),
    iceServers: () => apiMocks.iceServers(),
    show: (callId: number) => apiMocks.show(callId),
    active: (conversationId: number) => apiMocks.active(conversationId),
    history: (conversationId: number, params?: object) => apiMocks.history(conversationId, params),
  },
  auth: {
    wsTicket: () => apiMocks.wsTicket(),
  },
};

export class ApiError extends Error {
  code: string;
  errors?: Record<string, unknown>;

  constructor(message: string, public status: number, code: string, errors?: Record<string, unknown>) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.errors = errors;
  }
}
