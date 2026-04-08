import type { User, Channel, Message, Conversation, CursorPage, PresenceMap, UnreadCounts, KeyBundle, ConversationKey, Attachment, SearchResults, SearchFilters } from '../types';

const API_BASE = 'http://localhost/chat-api/public';

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

  spaces: {
    list: () => request<{ spaces: { id: number; name: string }[] }>('/api/spaces'),
  },

  channels: {
    list: (spaceId: number) =>
      request<{ channels: Channel[] }>(`/api/spaces/${spaceId}/channels`),
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
    createGroup: (spaceId: number, userIds: number[]) =>
      request<{ conversation: Conversation }>('/api/conversations', {
        method: 'POST',
        body: JSON.stringify({ space_id: spaceId, user_ids: userIds }),
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
};