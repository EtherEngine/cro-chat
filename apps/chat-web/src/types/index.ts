export type User = {
  id: number;
  email: string;
  display_name: string;
  title: string;
  avatar_color: string;
  status: PresenceStatus;
  last_seen_at: string | null;
};

export type Channel = {
  id: number;
  space_id: number;
  name: string;
  description: string;
  color: string;
  is_private: number;
  member_count: number;
};

export type Attachment = {
  id: number;
  message_id: number;
  original_name: string;
  mime_type: string;
  file_size: number;
  created_at: string;
};

export type CallMeta = {
  call_id: number;
  status: CallStatus;
  end_reason: CallEndReason | null;
  duration_seconds: number | null;
  caller_user_id: number;
  callee_user_id: number;
  started_at: string | null;
  answered_at: string | null;
  ended_at: string | null;
};

export type Message = {
  id: number;
  type: 'text' | 'call';
  body: string | null;
  call_id: number | null;
  call_meta?: CallMeta | null;
  user_id: number;
  channel_id: number | null;
  conversation_id: number | null;
  reply_to_id: number | null;
  edited_at: string | null;
  deleted_at: string | null;
  created_at: string;
  attachments: Attachment[];
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
  participant_hash: string;
  users: User[];
  created_at: string;
};

export type KeyBundle = {
  device_id: string;
  identity_key: string;
  signed_pre_key: string;
  pre_key_sig: string;
  one_time_keys: string[];
  updated_at: string;
};

export type ConversationKey = {
  user_id: number;
  device_id: string;
  encrypted_key: string;
  updated_at: string;
};

export type CursorPage<T> = {
  messages: T[];
  next_cursor: number | null;
  has_more: boolean;
};

export type PresenceStatus = 'online' | 'away' | 'offline' | 'ringing' | 'in_call' | 'dnd';

export type PresenceMap = Record<number, PresenceStatus>;

export type UnreadCounts = {
  channels: Record<number, number>;
  conversations: Record<number, number>;
};

export type SearchMessageHit = {
  id: number;
  body: string;
  snippet: string;
  user_id: number;
  channel_id: number | null;
  conversation_id: number | null;
  thread_id: number | null;
  created_at: string;
  author_name: string;
  author_color: string;
  context: string;
};

export type SearchResults = {
  channels?: Channel[];
  users?: User[];
  messages?: SearchMessageHit[];
};

export type SearchFilters = {
  type?: 'all' | 'channels' | 'users' | 'messages';
  channel_id?: number;
  conversation_id?: number;
  user_id?: number;
  after?: string;
  before?: string;
};

export type PinnedMessage = {
  pin_id: number;
  pinned_by: number;
  pinner_name: string;
  pinned_at: string;
  message: Message;
};

export type SavedMessage = {
  saved_id: number;
  saved_at: string;
  message: Message & { context: string | null };
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
  | 'ice_failed'
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

/** ICE server entry as returned by the backend / consumed by RTCPeerConnection. */
export type IceServer = {
  urls: string;
  username?: string;
  credential?: string;
};

/** Response from GET /api/calls/ice-servers. */
export type IceServerConfig = {
  ice_servers: IceServer[];
  ice_transport_policy: RTCIceTransportPolicy;
};

// ── Notifications ──────────────────────────────────────

export type NotificationType =
  | 'mention'
  | 'dm'
  | 'thread_reply'
  | 'reaction'
  | 'call_incoming'
  | 'call_missed'
  | 'call_rejected';

export type AppNotification = {
  id: number;
  user_id: number;
  space_id: number;
  type: NotificationType;
  actor: {
    id: number;
    display_name: string;
    avatar_color: string;
  };
  message_id: number | null;
  channel_id: number | null;
  conversation_id: number | null;
  thread_id: number | null;
  data: Record<string, unknown> | null;
  read_at: string | null;
  created_at: string;
};