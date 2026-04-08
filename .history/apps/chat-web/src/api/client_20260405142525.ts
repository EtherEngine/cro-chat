import type { User, Channel, Message, Conversation, CursorPage, PresenceMap, UnreadCounts, KeyBundle, ConversationKey, Attachment, SearchResults, SearchFilters, PinnedMessage, SavedMessage } from '../types';

const API_BASE = 'http://localhost/chat-api/public';

export type AdminMember = User & { role: string; muted_until: string | null; email: string; last_seen_at: string | null };

export type AdminChannel = {
  id: number; name: string; description: string | null; color: string;
  is_private: number; created_at: string; member_count: number;
  message_count: number; last_activity: string | null;
};

export type ModerationAction = {
  id: number;
  action_type: string;
  reason: string | null;
  created_at: string;
  actor_name: string;
  actor_color: string;
  target_name: string | null;
  metadata: string | null;
  channel_id: number | null;
};

export type AdminStats = {
  members: { total: number; owners: number; admins: number; moderators: number; members: number; guests: number; online: number; away: number };
  channels: { total: number; private_count: number; public_count: number };
  messages: { total: number; deleted: number; edited: number; replies: number; today: number; last_7_days: number; last_30_days: number };
  conversations: { total: number };
  attachments: { total: number; total_bytes: number; total_mb: number };
  reactions: { total: number };
  threads: { total: number };
  moderation: { total: number; message_deletes: number; mutes: number; kicks: number; role_changes: number };
  pins: { total: number };
  topChannels: { id: number; name: string; color: string; message_count: number; member_count: number }[];
  topUsers: { id: number; display_name: string; avatar_color: string; status: string; message_count: number }[];
  dailyMessages: { day: string; count: number }[];
  recentModeration: ModerationAction[];
};

export type AdminJob = {
  id: number; queue: string; type: string; payload: Record<string, unknown>;
  status: string; attempts: number; max_attempts: number; priority: number;
  last_error: string | null; idempotency_key: string | null;
  locked_by: string | null; locked_at: string | null;
  available_at: string; created_at: string; started_at: string | null;
  completed_at: string | null;
};

export type AdminJobStats = { pending: number; processing: number; done: number; failed: number };

export type AdminJobsResponse = {
  jobs: AdminJob[]; next_cursor: number | null; has_more: boolean; stats: AdminJobStats;
};

export type AdminNotificationStats = {
  total: number; unread: number;
  by_type: { type: string; count: number; unread: number }[];
  daily: { day: string; count: number }[];
  recent: { id: number; user_id: number; type: string; read_at: string | null; created_at: string; actor_name: string; actor_color: string; user_name: string }[];
};

export type AdminRealtimeData = {
  status_counts: { online: number; away: number; offline: number };
  online_users: { id: number; display_name: string; avatar_color: string; status: string; last_seen_at: string | null; role: string }[];
  active_channels: { id: number; name: string; color: string; active_users: number; msg_count: number }[];
  recent_activity: { id: number; display_name: string; avatar_color: string; status: string; last_seen_at: string | null }[];
};

export type RetentionPolicy = {
  id: number; space_id: number; target: string; retention_days: number;
  hard_delete: boolean; enabled: boolean; created_by: number;
  created_by_name: string | null; updated_at: string; created_at: string;
};

export type DataExportRequest = {
  id: number; user_id: number; user_name: string | null; space_id: number;
  status: string; file_size: number | null; requested_by: number;
  requested_by_name: string | null; completed_at: string | null;
  expires_at: string | null; error: string | null; created_at: string;
};

export type DeletionRequest = {
  id: number; user_id: number; user_name: string | null; space_id: number;
  action: string; status: string; reason: string | null; requested_by: number;
  requested_by_name: string | null; grace_end_at: string | null;
  completed_at: string | null; created_at: string;
};

export type ComplianceLogEntry = {
  id: number; space_id: number; action: string; actor_id: number;
  actor_name: string | null; target_user_id: number | null;
  target_name: string | null; details: Record<string, unknown> | null;
  created_at: string;
};

export type ComplianceSummary = {
  policies: RetentionPolicy[];
  available_targets: string[];
  recent_exports: DataExportRequest[];
  recent_deletions: DeletionRequest[];
  recent_log: ComplianceLogEntry[];
};

let csrfToken = '';

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    ...(init.headers as Record<string, string> || {}),
  };
  if (csrfToken) {
    headers['X-CSRF-Token'] = csrfToken;
  }

  const res = await fetch(API_BASE + path, {
    ...init,
    credentials: 'include',
    headers,
  });

  // Update CSRF token from response header
  const newToken = res.headers.get('X-CSRF-Token');
  if (newToken) {
    csrfToken = newToken;
  }

  const data = await res.json();
  if (!res.ok) throw new Error(data.message || 'Request failed');
  return data;
}

async function uploadFile<T>(path: string, file: File): Promise<T> {
  const form = new FormData();
  form.append('file', file);
  const headers: Record<string, string> = {};
  if (csrfToken) {
    headers['X-CSRF-Token'] = csrfToken;
  }
  const res = await fetch(API_BASE + path, {
    method: 'POST',
    credentials: 'include',
    body: form,
    headers,
    // No Content-Type header — browser sets multipart boundary automatically
  });
  const newToken = res.headers.get('X-CSRF-Token');
  if (newToken) {
    csrfToken = newToken;
  }
  const data = await res.json();
  if (!res.ok) throw new Error(data.message || 'Upload failed');
  return data;
}

export const api = {
  auth: {
    login: (email: string, password: string) =>
      request<{ user: User }>('/api/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      }),
    me: () => request<{ user: User }>('/api/auth/me'),
    logout: () =>
      request<{ ok: boolean }>('/api/auth/logout', { method: 'POST' }),
    wsTicket: () =>
      request<{ ticket: string }>('/api/auth/ws-ticket', { method: 'POST' }),
  },

  users: {
    updateProfile: (data: { display_name: string; title: string }) =>
      request<{ user: User }>('/api/users/me/profile', {
        method: 'PUT',
        body: JSON.stringify(data),
      }),
    changePassword: (data: { current_password: string; new_password: string }) =>
      request<{ ok: boolean }>('/api/users/me/password', {
        method: 'PUT',
        body: JSON.stringify(data),
      }),
  },

  spaces: {
    list: () => request<{ spaces: { id: number; name: string; role: string }[] }>('/api/spaces'),
    members: (spaceId: number) =>
      request<{ members: User[] }>(`/api/spaces/${spaceId}/members`),
  },

  admin: {
    stats: (spaceId: number) =>
      request<AdminStats>(`/api/spaces/${spaceId}/admin/stats`),
    members: (spaceId: number) =>
      request<{ members: AdminMember[] }>(`/api/spaces/${spaceId}/admin/members`),
    channels: (spaceId: number) =>
      request<{ channels: AdminChannel[] }>(`/api/spaces/${spaceId}/admin/channels`),
    changeRole: (spaceId: number, userId: number, role: string, reason?: string) =>
      request<{ ok: boolean }>(`/api/spaces/${spaceId}/moderation/roles/${userId}`, {
        method: 'PUT',
        body: JSON.stringify({ role, reason }),
      }),
    removeMember: (spaceId: number, userId: number) =>
      request<{ ok: boolean }>(`/api/spaces/${spaceId}/admin/members/${userId}`, { method: 'DELETE' }),
    muteMember: (spaceId: number, userId: number, durationMinutes: number, reason?: string) =>
      request<{ ok: boolean; muted_until: string }>(`/api/spaces/${spaceId}/admin/members/${userId}/mute`, {
        method: 'PUT',
        body: JSON.stringify({ duration_minutes: durationMinutes, reason }),
      }),
    unmuteMember: (spaceId: number, userId: number) =>
      request<{ ok: boolean }>(`/api/spaces/${spaceId}/admin/members/${userId}/mute`, { method: 'DELETE' }),
    moderationLog: (spaceId: number, limit = 50) =>
      request<{ actions: ModerationAction[] }>(`/api/spaces/${spaceId}/moderation/log?limit=${limit}`),
    jobs: (spaceId: number, params?: { status?: string; queue?: string; type?: string; before?: number; limit?: number }) => {
      const qs = new URLSearchParams();
      if (params?.status) qs.set('status', params.status);
      if (params?.queue) qs.set('queue', params.queue);
      if (params?.type) qs.set('type', params.type);
      if (params?.before) qs.set('before', String(params.before));
      if (params?.limit) qs.set('limit', String(params.limit));
      const q = qs.toString();
      return request<AdminJobsResponse>(`/api/spaces/${spaceId}/admin/jobs${q ? '?' + q : ''}`);
    },
    retryJob: (spaceId: number, jobId: number) =>
      request<{ job: AdminJob }>(`/api/spaces/${spaceId}/admin/jobs/${jobId}/retry`, { method: 'POST' }),
    purgeJobs: (spaceId: number, olderThanHours = 48) =>
      request<{ purged: number }>(`/api/spaces/${spaceId}/admin/jobs/purge`, {
        method: 'POST',
        body: JSON.stringify({ older_than_hours: olderThanHours }),
      }),
    notifications: (spaceId: number, limit = 20) =>
      request<AdminNotificationStats>(`/api/spaces/${spaceId}/admin/notifications?limit=${limit}`),
    realtime: (spaceId: number) =>
      request<AdminRealtimeData>(`/api/spaces/${spaceId}/admin/realtime`),
  },

  compliance: {
    summary: (spaceId: number) =>
      request<ComplianceSummary>(`/api/spaces/${spaceId}/compliance/summary`),
    listPolicies: (spaceId: number) =>
      request<{ policies: RetentionPolicy[]; available_targets: string[] }>(`/api/spaces/${spaceId}/compliance/retention`),
    upsertPolicy: (spaceId: number, data: { target: string; retention_days: number; hard_delete?: boolean; enabled?: boolean }) =>
      request<{ policy: RetentionPolicy }>(`/api/spaces/${spaceId}/compliance/retention`, {
        method: 'PUT', body: JSON.stringify(data),
      }),
    applyRetention: (spaceId: number) =>
      request<{ ok: boolean; message: string }>(`/api/spaces/${spaceId}/compliance/retention/apply`, { method: 'POST' }),
    requestExport: (spaceId: number, userId: number) =>
      request<{ export: DataExportRequest }>(`/api/spaces/${spaceId}/compliance/export`, {
        method: 'POST', body: JSON.stringify({ user_id: userId }),
      }),
    listExports: (spaceId: number, limit = 20) =>
      request<{ exports: DataExportRequest[] }>(`/api/spaces/${spaceId}/compliance/exports?limit=${limit}`),
    downloadExportUrl: (spaceId: number, exportId: number) =>
      `${API_BASE}/api/spaces/${spaceId}/compliance/exports/${exportId}/download`,
    requestDeletion: (spaceId: number, userId: number, action: 'anonymize' | 'delete', reason?: string) =>
      request<{ request: DeletionRequest }>(`/api/spaces/${spaceId}/compliance/deletion`, {
        method: 'POST', body: JSON.stringify({ user_id: userId, action, reason }),
      }),
    listDeletions: (spaceId: number, limit = 20) =>
      request<{ requests: DeletionRequest[] }>(`/api/spaces/${spaceId}/compliance/deletions?limit=${limit}`),
    cancelDeletion: (spaceId: number, requestId: number) =>
      request<{ ok: boolean }>(`/api/spaces/${spaceId}/compliance/deletions/${requestId}/cancel`, { method: 'POST' }),
    log: (spaceId: number, limit = 50, action?: string) => {
      const qs = new URLSearchParams({ limit: String(limit) });
      if (action) qs.set('action', action);
      return request<{ entries: ComplianceLogEntry[] }>(`/api/spaces/${spaceId}/compliance/log?${qs}`);
    },
  },

  channels: {
    list: (spaceId: number) =>
      request<{ channels: Channel[] }>(`/api/spaces/${spaceId}/channels`),
    create: (spaceId: number, data: { name: string; description?: string; color?: string; is_private?: boolean }) =>
      request<{ channel: Channel }>(`/api/spaces/${spaceId}/channels`, {
        method: 'POST',
        body: JSON.stringify(data),
      }),
    members: (channelId: number) =>
      request<{ members: User[] }>(`/api/channels/${channelId}/members`),
  },

  messages: {
    forChannel: (channelId: number, params?: { before?: number; after?: number; limit?: number }) => {
      const qs = new URLSearchParams();
      if (params?.before) qs.set('before', String(params.before));
      if (params?.after) qs.set('after', String(params.after));
      if (params?.limit) qs.set('limit', String(params.limit));
      const q = qs.toString();
      return request<CursorPage<Message>>(`/api/channels/${channelId}/messages${q ? '?' + q : ''}`);
    },
    sendChannel: (channelId: number, body: string, idempotencyKey?: string) =>
      request<{ message: Message }>(`/api/channels/${channelId}/messages`, {
        method: 'POST',
        body: JSON.stringify({ body, idempotency_key: idempotencyKey }),
      }),
    forConversation: (convId: number, params?: { before?: number; after?: number; limit?: number }) => {
      const qs = new URLSearchParams();
      if (params?.before) qs.set('before', String(params.before));
      if (params?.after) qs.set('after', String(params.after));
      if (params?.limit) qs.set('limit', String(params.limit));
      const q = qs.toString();
      return request<CursorPage<Message>>(`/api/conversations/${convId}/messages${q ? '?' + q : ''}`);
    },
    sendConversation: (convId: number, body: string, idempotencyKey?: string) =>
      request<{ message: Message }>(`/api/conversations/${convId}/messages`, {
        method: 'POST',
        body: JSON.stringify({ body, idempotency_key: idempotencyKey }),
      }),
  },

  attachments: {
    upload: (messageId: number, file: File) =>
      uploadFile<{ attachment: Attachment }>(`/api/messages/${messageId}/attachments`, file),
    downloadUrl: (attachmentId: number) =>
      `${API_BASE}/api/attachments/${attachmentId}`,
  },

  conversations: {
    list: () => request<{ conversations: Conversation[] }>('/api/conversations'),
    show: (convId: number) => request<{ conversation: Conversation }>(`/api/conversations/${convId}`),
    members: (convId: number) => request<{ members: User[] }>(`/api/conversations/${convId}/members`),
    createDirect: (spaceId: number, userId: number) =>
      request<{ conversation: Conversation }>('/api/conversations', {
        method: 'POST',
        body: JSON.stringify({ space_id: spaceId, user_id: userId }),
      }),
    createGroup: (spaceId: number, userIds: number[], title?: string) =>
      request<{ conversation: Conversation }>('/api/conversations', {
        method: 'POST',
        body: JSON.stringify({ space_id: spaceId, user_ids: userIds, ...(title ? { title } : {}) }),
      }),
    rename: (convId: number, title: string) =>
      request<{ conversation: Conversation }>(`/api/conversations/${convId}`, {
        method: 'PUT',
        body: JSON.stringify({ title }),
      }),
    updateAvatar: (convId: number, avatarUrl: string) =>
      request<{ conversation: Conversation }>(`/api/conversations/${convId}`, {
        method: 'PUT',
        body: JSON.stringify({ avatar_url: avatarUrl }),
      }),
    addMember: (convId: number, userId: number) =>
      request<{ conversation: Conversation }>(`/api/conversations/${convId}/members`, {
        method: 'POST',
        body: JSON.stringify({ user_id: userId }),
      }),
    removeMember: (convId: number, userId: number) =>
      request<{ conversation: Conversation }>(`/api/conversations/${convId}/members/${userId}`, {
        method: 'DELETE',
      }),
    leave: (convId: number) =>
      request<{ left: boolean; conversation_id: number }>(`/api/conversations/${convId}/leave`, {
        method: 'POST',
      }),
  },

  presence: {
    heartbeat: () =>
      request<{ ok: boolean }>('/api/presence/heartbeat', { method: 'POST' }),
    status: () =>
      request<{ statuses: PresenceMap }>('/api/presence/status'),
  },

  unread: {
    counts: () =>
      request<UnreadCounts>('/api/unread'),
    markChannelRead: (channelId: number, messageId: number) =>
      request<{ ok: boolean }>(`/api/channels/${channelId}/read`, {
        method: 'POST',
        body: JSON.stringify({ message_id: messageId }),
      }),
    markConversationRead: (convId: number, messageId: number) =>
      request<{ ok: boolean }>(`/api/conversations/${convId}/read`, {
        method: 'POST',
        body: JSON.stringify({ message_id: messageId }),
      }),
  },

  keys: {
    uploadBundle: (bundle: { device_id: string; identity_key: string; signed_pre_key: string; pre_key_sig: string; one_time_keys?: string[] }) =>
      request<{ ok: boolean }>('/api/keys/bundle', {
        method: 'PUT',
        body: JSON.stringify(bundle),
      }),
    getUserKeys: (userId: number) =>
      request<{ keys: KeyBundle[] }>(`/api/users/${userId}/keys`),
    claimKey: (userId: number, deviceId: string) =>
      request<{ one_time_key: string | null }>(`/api/users/${userId}/keys/claim`, {
        method: 'POST',
        body: JSON.stringify({ device_id: deviceId }),
      }),
    storeConversationKey: (convId: number, deviceId: string, encryptedKey: string) =>
      request<{ ok: boolean }>(`/api/conversations/${convId}/keys`, {
        method: 'PUT',
        body: JSON.stringify({ device_id: deviceId, encrypted_key: encryptedKey }),
      }),
    getConversationKeys: (convId: number) =>
      request<{ keys: ConversationKey[] }>(`/api/conversations/${convId}/keys`),
  },

  search: {
    query: (q: string, filters?: SearchFilters) => {
      const qs = new URLSearchParams({ q });
      if (filters?.type) qs.set('type', filters.type);
      if (filters?.channel_id) qs.set('channel_id', String(filters.channel_id));
      if (filters?.conversation_id) qs.set('conversation_id', String(filters.conversation_id));
      if (filters?.user_id) qs.set('user_id', String(filters.user_id));
      if (filters?.after) qs.set('after', filters.after);
      if (filters?.before) qs.set('before', filters.before);
      return request<SearchResults>(`/api/search?${qs}`);
    },
  },

  pins: {
    pin: (messageId: number) =>
      request<{ pinned: boolean; message_id: number }>(`/api/messages/${messageId}/pin`, { method: 'POST' }),
    unpin: (messageId: number) =>
      request<{ pinned: boolean; message_id: number }>(`/api/messages/${messageId}/pin`, { method: 'DELETE' }),
    forChannel: (channelId: number) =>
      request<{ pins: PinnedMessage[] }>(`/api/channels/${channelId}/pins`),
    forConversation: (convId: number) =>
      request<{ pins: PinnedMessage[] }>(`/api/conversations/${convId}/pins`),
  },

  savedMessages: {
    save: (messageId: number) =>
      request<{ saved: boolean; message_id: number }>(`/api/messages/${messageId}/save`, { method: 'POST' }),
    unsave: (messageId: number) =>
      request<{ saved: boolean; message_id: number }>(`/api/messages/${messageId}/save`, { method: 'DELETE' }),
    list: () =>
      request<{ saved_messages: SavedMessage[] }>('/api/saved-messages'),
  },

  push: {
    vapidKey: (spaceId: number) =>
      request<{ public_key: string }>(`/api/spaces/${spaceId}/push/vapid-key`),
    register: (data: {
      device_id: string; space_id: number; platform: string;
      device_name?: string; endpoint?: string; p256dh_key?: string;
      auth_key?: string; push_token?: string;
    }) =>
      request<{ subscription: PushSubscriptionRecord }>('/api/devices/register', {
        method: 'POST', body: JSON.stringify(data),
      }),
    listDevices: () =>
      request<{ devices: PushSubscriptionRecord[] }>('/api/devices'),
    unregister: (subscriptionId: number) =>
      request<{ ok: boolean }>(`/api/devices/${subscriptionId}`, { method: 'DELETE' }),
    deactivate: (subscriptionId: number) =>
      request<{ ok: boolean }>(`/api/devices/${subscriptionId}/deactivate`, { method: 'POST' }),
    sync: (data: { device_id: string; space_id: number; limit?: number }) =>
      request<SyncResponse>('/api/devices/sync', {
        method: 'POST', body: JSON.stringify(data),
      }),
    syncAck: (data: { device_id: string; space_id: number; cursor: number }) =>
      request<{ ok: boolean }>('/api/devices/sync/ack', {
        method: 'POST', body: JSON.stringify(data),
      }),
  },

  notifications: {
    list: (params?: { before?: number; limit?: number }) => {
      const qs = new URLSearchParams();
      if (params?.before) qs.set('before', String(params.before));
      if (params?.limit) qs.set('limit', String(params.limit));
      const q = qs.toString();
      return request<{ notifications: Notification[]; next_cursor: number | null; has_more: boolean }>(`/api/notifications${q ? '?' + q : ''}`);
    },
    unreadCount: () =>
      request<{ count: number }>('/api/notifications/unread-count'),
    markRead: (notificationId: number) =>
      request<{ ok: boolean }>(`/api/notifications/${notificationId}/read`, { method: 'POST' }),
    markAllRead: () =>
      request<{ ok: boolean }>('/api/notifications/read-all', { method: 'POST' }),
  },
};