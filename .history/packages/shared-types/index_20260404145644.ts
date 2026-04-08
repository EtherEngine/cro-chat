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