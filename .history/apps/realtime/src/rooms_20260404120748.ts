import type { WebSocket } from 'ws';

export type Client = {
  ws: WebSocket;
  userId: number;
  rooms: Set<string>;
};

/** room → set of clients */
const rooms = new Map<string, Set<Client>>();

/** ws → Client */
const clients = new Map<WebSocket, Client>();

export function registerClient(ws: WebSocket, userId: number): Client {
  const client: Client = { ws, userId, rooms: new Set() };
  clients.set(ws, client);
  return client;
}

export function removeClient(ws: WebSocket): void {
  const client = clients.get(ws);
  if (!client) return;

  for (const room of client.rooms) {
    const members = rooms.get(room);
    if (members) {
      members.delete(client);
      if (members.size === 0) rooms.delete(room);
    }
  }
  clients.delete(ws);
}

export function joinRoom(client: Client, room: string): void {
  client.rooms.add(room);
  let members = rooms.get(room);
  if (!members) {
    members = new Set();
    rooms.set(room, members);
  }
  members.add(client);
}

export function leaveRoom(client: Client, room: string): void {
  client.rooms.delete(room);
  const members = rooms.get(room);
  if (members) {
    members.delete(client);
    if (members.size === 0) rooms.delete(room);
  }
}

/**
 * Broadcast a JSON payload to all clients in a room.
 */
export function broadcastToRoom(room: string, data: object): void {
  const members = rooms.get(room);
  if (!members || members.size === 0) return;

  const payload = JSON.stringify(data);
  for (const client of members) {
    if (client.ws.readyState === client.ws.OPEN) {
      client.ws.send(payload);
    }
  }
}

export function getClient(ws: WebSocket): Client | undefined {
  return clients.get(ws);
}

export function stats(): { clients: number; rooms: number } {
  return { clients: clients.size, rooms: rooms.size };
}
