export type User = {
  id: number;
  email: string;
  display_name: string;
  title: string;
  avatar_color: string;
  status: 'online' | 'away' | 'offline';
};

export type Channel = {
  id: number;
  name: string;
  description: string;
  color: string;
  member_count: number;
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
  users: User[];
  created_at: string;
};