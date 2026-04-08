import { createContext, useContext, useReducer, type Dispatch, type ReactNode } from 'react';
import type { User, Channel, Message, Conversation } from '../types';

type State = {
  user: User | null;
  channels: Channel[];
  activeChannelId: number | null;
  activeConversationId: number | null;
  messages: Message[];
  members: User[];
  conversations: Conversation[];
  showMembers: boolean;
};

type Action =
  | { type: 'SET_USER'; user: User | null }
  | { type: 'SET_CHANNELS'; channels: Channel[] }
  | { type: 'SET_ACTIVE_CHANNEL'; channelId: number }
  | { type: 'SET_ACTIVE_CONVERSATION'; conversationId: number }
  | { type: 'SET_MESSAGES'; messages: Message[] }
  | { type: 'ADD_MESSAGE'; message: Message }
  | { type: 'SET_MEMBERS'; members: User[] }
  | { type: 'SET_CONVERSATIONS'; conversations: Conversation[] }
  | { type: 'TOGGLE_MEMBERS' }
  | { type: 'CLOSE_MEMBERS' };

const initialState: State = {
  user: null,
  channels: [],
  activeChannelId: null,
  activeConversationId: null,
  messages: [],
  members: [],
  conversations: [],
  showMembers: true,
};

function reducer(state: State, action: Action): State {
  switch (action.type) {
    case 'SET_USER':
      return { ...state, user: action.user };
    case 'SET_CHANNELS':
      return { ...state, channels: action.channels };
    case 'SET_ACTIVE_CHANNEL':
      return { ...state, activeChannelId: action.channelId, activeConversationId: null };
    case 'SET_ACTIVE_CONVERSATION':
      return { ...state, activeConversationId: action.conversationId, activeChannelId: null };
    case 'SET_MESSAGES':
      return { ...state, messages: action.messages };
    case 'ADD_MESSAGE':
      return { ...state, messages: [...state.messages, action.message] };
    case 'SET_MEMBERS':
      return { ...state, members: action.members };
    case 'SET_CONVERSATIONS':
      return { ...state, conversations: action.conversations };
    case 'TOGGLE_MEMBERS':
      return { ...state, showMembers: !state.showMembers };
    case 'CLOSE_MEMBERS':
      return { ...state, showMembers: false };
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
