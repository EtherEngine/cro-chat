import { createContext, useContext, useReducer, type Dispatch, type ReactNode } from 'react';
import type { User, Channel, Message, Conversation, PresenceMap, UnreadCounts, AppNotification } from '../types';

type State = {
  user: User | null;
  spaceId: number | null;
  spaceRole: string | null;
  channels: Channel[];
  activeChannelId: number | null;
  activeConversationId: number | null;
  messages: Message[];
  members: User[];
  conversations: Conversation[];
  presence: PresenceMap;
  unread: UnreadCounts;
  showMembers: boolean;
  jumpToMessageId: number | null;
  notifications: AppNotification[];
  notificationUnread: number;
};

type Action =
  | { type: 'SET_USER'; user: User | null }
  | { type: 'SET_SPACE'; spaceId: number; role: string }
  | { type: 'SET_CHANNELS'; channels: Channel[] }
  | { type: 'SET_ACTIVE_CHANNEL'; channelId: number }
  | { type: 'SET_ACTIVE_CONVERSATION'; conversationId: number }
  | { type: 'SET_MESSAGES'; messages: Message[] }
  | { type: 'ADD_MESSAGE'; message: Message }
  | { type: 'APPEND_MESSAGES'; messages: Message[] }
  | { type: 'SET_MEMBERS'; members: User[] }
  | { type: 'SET_CONVERSATIONS'; conversations: Conversation[] }
  | { type: 'UPSERT_CONVERSATION'; conversation: Conversation }
  | { type: 'ADD_CHANNEL'; channel: Channel }
  | { type: 'SET_PRESENCE'; presence: PresenceMap }
  | { type: 'SET_UNREAD'; unread: UnreadCounts }
  | { type: 'CLEAR_UNREAD_CHANNEL'; channelId: number }
  | { type: 'CLEAR_UNREAD_CONVERSATION'; conversationId: number }
  | { type: 'TOGGLE_MEMBERS' }
  | { type: 'CLOSE_MEMBERS' }
  | { type: 'JUMP_TO_MESSAGE'; messageId: number }
  | { type: 'CLEAR_JUMP' }
  | { type: 'SET_NOTIFICATIONS'; notifications: AppNotification[] }
  | { type: 'ADD_NOTIFICATION'; notification: AppNotification }
  | { type: 'SET_NOTIFICATION_UNREAD'; count: number }
  | { type: 'MARK_NOTIFICATION_READ'; notificationId: number };

const initialState: State = {
  user: null,
  spaceId: null,
  spaceRole: null,
  channels: [],
  activeChannelId: null,
  activeConversationId: null,
  messages: [],
  members: [],
  conversations: [],
  presence: {},
  unread: { channels: {}, conversations: {} },
  showMembers: true,
  jumpToMessageId: null,
  notifications: [],
  notificationUnread: 0,
};

function mergeMessages(existing: Message[], incoming: Message[]): Message[] {
  const map = new Map(existing.map((m) => [m.id, m]));
  for (const m of incoming) {
    map.set(m.id, m);
  }
  return Array.from(map.values()).sort((a, b) => a.id - b.id);
}

function reducer(state: State, action: Action): State {
  switch (action.type) {
    case 'SET_USER':
      return { ...state, user: action.user };
    case 'SET_SPACE':
      return { ...state, spaceId: action.spaceId, spaceRole: action.role };
    case 'SET_CHANNELS':
      return { ...state, channels: action.channels };
    case 'ADD_CHANNEL':
      return { ...state, channels: [...state.channels, action.channel] };
    case 'SET_ACTIVE_CHANNEL':
      return { ...state, activeChannelId: action.channelId, activeConversationId: null, messages: [] };
    case 'SET_ACTIVE_CONVERSATION':
      return { ...state, activeConversationId: action.conversationId, activeChannelId: null, messages: [] };
    case 'SET_MESSAGES':
      return { ...state, messages: action.messages };
    case 'ADD_MESSAGE':
      return { ...state, messages: [...state.messages, action.message] };
    case 'APPEND_MESSAGES':
      return { ...state, messages: mergeMessages(state.messages, action.messages) };
    case 'SET_MEMBERS':
      return { ...state, members: action.members };
    case 'SET_CONVERSATIONS':
      return { ...state, conversations: action.conversations };
    case 'UPSERT_CONVERSATION': {
      const exists = state.conversations.some((c) => c.id === action.conversation.id);
      return {
        ...state,
        conversations: exists
          ? state.conversations.map((c) => (c.id === action.conversation.id ? action.conversation : c))
          : [action.conversation, ...state.conversations],
      };
    }
    case 'SET_PRESENCE':
      return { ...state, presence: { ...state.presence, ...action.presence } };
    case 'SET_UNREAD':
      return { ...state, unread: action.unread };
    case 'CLEAR_UNREAD_CHANNEL': {
      const { [action.channelId]: _, ...rest } = state.unread.channels;
      return { ...state, unread: { ...state.unread, channels: rest } };
    }
    case 'CLEAR_UNREAD_CONVERSATION': {
      const { [action.conversationId]: _, ...rest } = state.unread.conversations;
      return { ...state, unread: { ...state.unread, conversations: rest } };
    }
    case 'TOGGLE_MEMBERS':
      return { ...state, showMembers: !state.showMembers };
    case 'CLOSE_MEMBERS':
      return { ...state, showMembers: false };
    case 'JUMP_TO_MESSAGE':
      return { ...state, jumpToMessageId: action.messageId };
    case 'CLEAR_JUMP':
      return { ...state, jumpToMessageId: null };
    case 'SET_NOTIFICATIONS':
      return { ...state, notifications: action.notifications };
    case 'ADD_NOTIFICATION':
      return {
        ...state,
        notifications: [action.notification, ...state.notifications],
        notificationUnread: state.notificationUnread + (action.notification.read_at ? 0 : 1),
      };
    case 'SET_NOTIFICATION_UNREAD':
      return { ...state, notificationUnread: action.count };
    case 'MARK_NOTIFICATION_READ':
      return {
        ...state,
        notifications: state.notifications.map((n) =>
          n.id === action.notificationId ? { ...n, read_at: new Date().toISOString() } : n
        ),
        notificationUnread: Math.max(0, state.notificationUnread - 1),
      };
    default:
      return state;
  }
}

const AppContext = createContext<{ state: State; dispatch: Dispatch<Action> } | null>(null);

export function AppProvider({ children }: { children: ReactNode }) {
  const [state, dispatch] = useReducer(reducer, initialState);
  return <AppContext.Provider value={{ state, dispatch }}>{children}</AppContext.Provider>;
}

export function useApp() {
  const ctx = useContext(AppContext);
  if (!ctx) throw new Error('useApp must be inside AppProvider');
  return ctx;
}
