import { db } from './db.js';
import { broadcastToRoom } from './rooms.js';
import { publishToRoom } from './pubsub.js';
import type { RowDataPacket } from 'mysql2';

interface DomainEvent extends RowDataPacket {
  id: number;
  event_type: string;
  room: string;
  payload: string;
  created_at: string;
}

let timer: ReturnType<typeof setInterval> | null = null;

/**
 * Start polling the domain_events outbox table.
 * Fetches unpublished events, broadcasts them to rooms, marks as published.
 */
export function startPoller(intervalMs: number): void {
  if (timer) return;

  timer = setInterval(async () => {
    try {
      await pollOnce();
    } catch (err) {
      console.error('[poller] Error:', (err as Error).message);
    }
  }, intervalMs);

  console.log(`[poller] Polling every ${intervalMs}ms`);
}

export function stopPoller(): void {
  if (timer) {
    clearInterval(timer);
    timer = null;
  }
}

async function pollOnce(): Promise<void> {
  const [rows] = await db().execute<DomainEvent[]>(
    `SELECT id, event_type, room, payload, created_at
     FROM domain_events
     WHERE published_at IS NULL
     ORDER BY id ASC
     LIMIT 100`,
  );

  if (rows.length === 0) return;

  const ids: number[] = [];

  for (const row of rows) {
    ids.push(row.id);

    let payload: unknown;
    try {
      payload = typeof row.payload === 'string' ? JSON.parse(row.payload) : row.payload;
    } catch {
      payload = row.payload;
    }

    broadcastToRoom(row.room, {
      type: row.event_type,
      room: row.room,
      payload,
      event_id: row.id,
      timestamp: row.created_at,
    });

    // Fan out to other instances via Redis
    publishToRoom(row.room, {
      type: row.event_type,
      room: row.room,
      payload,
      event_id: row.id,
      timestamp: row.created_at,
    });
  }

  // Mark as published in a single UPDATE
  const placeholders = ids.map(() => '?').join(',');
  await db().execute(
    `UPDATE domain_events SET published_at = NOW() WHERE id IN (${placeholders})`,
    ids,
  );
}
