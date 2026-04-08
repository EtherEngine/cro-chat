export type User = {
  id: number;
  email: string;
  display_name: string;
  title: string;
  avatar_color: string;
  status: 'online' | 'away' | 'offline';
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

export type Message = {
  id: number;
  body: string | null;
  user_id: number;
  channel_id: number | null;
  conversation_id: number | null;
  reply_to_id: number | null;
  edited_at: string | null;
  deleted_at: string | null;
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

export type PresenceMap = Record<number, 'online' | 'away' | 'offline'>;

export type UnreadCounts = {
  channels: Record<number, number>;
  conversations: Record<number, number>;
};