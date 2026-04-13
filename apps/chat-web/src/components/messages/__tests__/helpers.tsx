/**
 * Shared test helpers for message interaction tests.
 *
 * Provides:
 *  - fakeMessage()  — builds a Message fixture
 *  - makeAppState() — builds a minimal AppContext value
 *  - renderWithApp() — wraps a component in a stub AppContext
 *  - api mock setup (vi.mock must be called in each test file)
 */

import { createContext, useContext, type ReactNode } from 'react';
import { render } from '@testing-library/react';
import type { Message, Reaction, User } from '../../../types';

// ── Fixtures ──────────────────────────────────────────────────────────────────

export const ME: User = {
  id: 1,
  email: 'me@test.dev',
  display_name: 'Alice',
  title: '',
  avatar_color: '#6c63ff',
  status: 'online',
  last_seen_at: null,
};

export const OTHER: User = {
  id: 2,
  email: 'other@test.dev',
  display_name: 'Bob',
  title: '',
  avatar_color: '#ff6b6b',
  status: 'online',
  last_seen_at: null,
};

export function fakeMessage(overrides: Partial<Message> = {}): Message {
  return {
    id: 100,
    type: 'text',
    body: 'Hello world',
    call_id: null,
    call_meta: null,
    user_id: ME.id,
    channel_id: 10,
    conversation_id: null,
    reply_to_id: null,
    edited_at: null,
    deleted_at: null,
    created_at: new Date('2024-01-01T12:00:00Z').toISOString(),
    attachments: [],
    thread_id: null,
    thread: null,
    reactions: [],
    is_pinned: false,
    is_saved: false,
    user: { id: ME.id, display_name: ME.display_name, avatar_color: ME.avatar_color, title: '' },
    ...overrides,
  };
}

export function fakeReaction(emoji: string, userIds: number[]): Reaction {
  return { emoji, count: userIds.length, user_ids: userIds };
}

// ── AppContext stub ──────────────────────────────────────────────────────────

type AppCtxShape = { state: Record<string, unknown>; dispatch: ReturnType<typeof vi.fn> };
const AppContext = createContext<AppCtxShape | null>(null);

export function makeAppState(overrides: Record<string, unknown> = {}) {
  return {
    user: ME,
    spaceId: 1,
    spaceRole: 'member',
    channels: [],
    activeChannelId: 10,
    activeConversationId: null,
    messages: [],
    members: [ME, OTHER],
    conversations: [],
    presence: {},
    unread: { channels: {}, conversations: {} },
    showMembers: true,
    jumpToMessageId: null,
    replyToMessage: null,
    notifications: [],
    notificationUnread: 0,
    threadPanel: null,
    ...overrides,
  };
}

export function renderWithApp(
  ui: ReactNode,
  appState: Record<string, unknown> = makeAppState(),
  dispatch: ReturnType<typeof vi.fn> = vi.fn(),
) {
  const value = { state: appState, dispatch };
  return {
    dispatch,
    ...render(
      <AppContext.Provider value={value}>{ui}</AppContext.Provider>,
    ),
  };
}

// Exported so vi.mock factories can re-use it
export { AppContext };

// ── API mock shapes (used in vi.mock factories) ───────────────────────────────

export const msgApiMocks = {
  edit: vi.fn(),
  delete: vi.fn(),
};

export const reactionApiMocks = {
  add: vi.fn(),
  remove: vi.fn(),
};

export const pinApiMocks = {
  pin: vi.fn(),
  unpin: vi.fn(),
};

export const savedApiMocks = {
  save: vi.fn(),
  unsave: vi.fn(),
};

/** Reset all API mocks to sensible defaults before each test. */
export function resetMessageMocks(msg: Message) {
  msgApiMocks.edit.mockResolvedValue({ message: { ...msg, body: 'Edited', edited_at: new Date().toISOString() } });
  msgApiMocks.delete.mockResolvedValue({ ok: true });
  reactionApiMocks.add.mockResolvedValue({ reactions: [fakeReaction('👍', [ME.id])] });
  reactionApiMocks.remove.mockResolvedValue({ reactions: [] });
  pinApiMocks.pin.mockResolvedValue({ pinned: true, message_id: msg.id });
  pinApiMocks.unpin.mockResolvedValue({ pinned: false, message_id: msg.id });
  savedApiMocks.save.mockResolvedValue({ saved: true, message_id: msg.id });
  savedApiMocks.unsave.mockResolvedValue({ saved: false, message_id: msg.id });
}
