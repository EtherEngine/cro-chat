export type EntityId = number;

export type User = {
  id: EntityId;
  email: string;
  display_name: string;
  avatar_url: string | null;
  job_title: string | null;
};
