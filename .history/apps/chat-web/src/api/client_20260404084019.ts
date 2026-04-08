const API = "http://localhost/chat-api/public";

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(API + path, {
    ...init,
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      ...(init.headers || {})
    }
  });

  const data = await res.json();

  if (!res.ok) throw new Error(data.message);
  return data;
}