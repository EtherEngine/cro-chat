import type { User, Channel, Message, Conversation } from '../types';

const API_BASE = 'http://localhost/chat-api/public';

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(API_BASE + path, {
    ...init,
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...(init.headers || {}),
    },
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.message || 'Request failed');
  return data;
}

export const api = {
  auth: {
    login: (email: string) =>
      request<{ user: User }>('/api/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email }),
      }),
    me: () => request<{ user: User }>('/api/auth/me'),
    logout: () =>
      request<{ ok: boolean }>('/api/auth/logout', { method: 'POST' }),
  },
  channels: {
    list: () => request<{ channels: Channel[] }>('/api/channels'),
    members: (id: number) =>
      request<{ members: User[] }>(`/api/channels/${id}/members`),
  },
  messages: {
    forChannel: (id: number) =>
      request<{ messages: Message[] }>(`/api/channels/${id}/messages`),
    sendChannel: (id: number, body: string) =>
      request<{ message: Message }>(`/api/channels/${id}/messages`, {
        method: 'POST',
        body: JSON.stringify({ body }),
      }),
    forConversation: (id: number) =>
      request<{ messages: Message[] }>(`/api/conversations/${id}/messages`),
    sendConversation: (id: number, body: string) =>
      request<{ message: Message }>(`/api/conversations/${id}/messages`, {
        method: 'POST',
        body: JSON.stringify({ body }),
      }),
  },
  conversations: {
    list: () => request<{ conversations: Conversation[] }>('/api/conversations'),
  },
};