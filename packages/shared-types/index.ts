export type User = {
  id: number;
  email: string;
  display_name: string;
  title: string;
  avatar_color: string;
  status: 'online' | 'away' | 'offline';
};

export type SpaceRole = 'owner' | 'admin' | 'moderator' | 'member' | 'guest';
export type ChannelRole = 'admin' | 'moderator' | 'member' | 'guest';

export type Channel = {
  id: number;
  name: string;
  description: string;
  color: string;
  member_count: number;
};

export type ModerationAction = {
  id: number;
  space_id: number;
  channel_id: number | null;
  action_type: 'message_delete' | 'user_mute' | 'user_unmute' | 'user_kick' | 'role_change' | 'channel_role_change';
  actor_id: number;
  target_user_id: number | null;
  message_id: number | null;
  reason: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  actor_name?: string;
  target_name?: string;
};

export type Message = {
  id: number;
  body: string;
  user_id: number;
  channel_id: number | null;
  conversation_id: number | null;
  created_at: string;
  user?: {
    id: number;
    display_name: string;
    avatar_color: string;
    title: string;
  };
};

export type Conversation = {
  id: number;
  space_id: number;
  is_group: boolean;
  title: string;
  created_by: number | null;
  avatar_url: string;
  users: User[];
  created_at: string;
};

export type JobStatus = 'pending' | 'processing' | 'done' | 'failed';

export type Job = {
  id: number;
  queue: string;
  type: string;
  payload: Record<string, unknown>;
  status: JobStatus;
  attempts: number;
  max_attempts: number;
  priority: number;
  last_error: string | null;
  idempotency_key: string | null;
  available_at: string;
  created_at: string;
  started_at: string | null;
  completed_at: string | null;
};

export type JobStats = {
  pending: number;
  processing: number;
  done: number;
  failed: number;
};

// ── Audio Calls (1:1 WebRTC) ────────────────────────────────

export type CallStatus =
  | 'initiated'
  | 'ringing'
  | 'accepted'
  | 'rejected'
  | 'ended'
  | 'missed'
  | 'failed';

export type CallEndReason =
  | 'hangup'
  | 'timeout'
  | 'network_error'
  | 'rejected'
  | 'caller_cancelled'
  | 'busy';

export type CallSessionRole = 'caller' | 'callee';

export type Call = {
  id: number;
  conversation_id: number;
  caller_user_id: number;
  callee_user_id: number;
  status: CallStatus;
  started_at: string;
  answered_at: string | null;
  ended_at: string | null;
  duration_seconds: number | null;
  end_reason: CallEndReason | null;
  created_at: string;
  caller?: Pick<User, 'id' | 'display_name' | 'avatar_color'>;
  callee?: Pick<User, 'id' | 'display_name' | 'avatar_color'>;
};

export type CallSession = {
  id: number;
  call_id: number;
  user_id: number;
  role: CallSessionRole;
  joined_at: string | null;
  left_at: string | null;
  muted: boolean;
  ice_state: string | null;
  created_at: string;
};

// ── Call Lifecycle Events (domain_events outbox → room broadcast) ─

/** Server → Room: call initiated, callee should ring */
export type CallRingingPayload = {
  call_id: number;
  conversation_id: number;
  caller_user_id: number;
  callee_user_id: number;
};

/** Server → Room: callee accepted the call */
export type CallAcceptedPayload = {
  call_id: number;
  conversation_id: number;
  callee_user_id: number;
};

/** Server → Room: callee rejected the call */
export type CallRejectedPayload = {
  call_id: number;
  conversation_id: number;
  caller_user_id: number;
  callee_user_id: number;
};

/** Server → Room: call ended (hangup, missed, cancelled) */
export type CallEndedPayload = {
  call_id: number;
  conversation_id: number;
  caller_user_id: number;
  callee_user_id: number;
  status: 'ended' | 'missed';
  reason: CallEndReason;
};

/** Server → Room: call failed (technical error) */
export type CallFailedPayload = {
  call_id: number;
  conversation_id: number;
  caller_user_id: number;
  callee_user_id: number;
  reason: string;
};

// ── WebRTC Signaling Events (direct user-to-user relay via WS) ──

/** Caller → Server → Callee: SDP offer */
export type WebRtcOfferPayload = {
  call_id: number;
  conversation_id: number;
  sender_id: number;
  sdp: string;
};

/** Callee → Server → Caller: SDP answer */
export type WebRtcAnswerPayload = {
  call_id: number;
  conversation_id: number;
  sender_id: number;
  sdp: string;
};

/** Either → other: trickle ICE candidate */
export type WebRtcIceCandidatePayload = {
  call_id: number;
  conversation_id: number;
  sender_id: number;
  candidate: RTCIceCandidateInit;
};

/**
 * All call-related event types that flow through the realtime system.
 *
 * Lifecycle events (outbox → room broadcast):
 *   call.ringing          → CallRingingPayload
 *   call.accepted         → CallAcceptedPayload
 *   call.rejected         → CallRejectedPayload
 *   call.ended            → CallEndedPayload
 *   call.failed           → CallFailedPayload
 *
 * WebRTC signaling (direct user-to-user relay):
 *   webrtc.offer          → WebRtcOfferPayload
 *   webrtc.answer         → WebRtcAnswerPayload
 *   webrtc.ice_candidate  → WebRtcIceCandidatePayload
 */
export type CallEventType =
  | 'call.ringing'
  | 'call.accepted'
  | 'call.rejected'
  | 'call.ended'
  | 'call.failed'
  | 'webrtc.offer'
  | 'webrtc.answer'
  | 'webrtc.ice_candidate';