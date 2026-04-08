/**
 * Redis Pub/Sub adapter for horizontal scaling of WebSocket servers.
 *
 * When multiple realtime server instances run behind a load balancer,
 * broadcasts must fan out to all instances. This module:
 *
 *   1. Subscribes to Redis channels matching room patterns
 *   2. On local broadcast → also publishes to Redis
 *   3. On Redis message → broadcasts to local clients only
 *
 * If Redis is unavailable, falls back to local-only broadcast (single-instance).
 */
import { createClient, type RedisClientType } from 'redis';
import { broadcastToRoom } from './rooms.js';

let pub: RedisClientType | null = null;
let sub: RedisClientType | null = null;
let connected = false;
const PREFIX = 'cro:realtime:';
const instanceId = `${process.pid}-${Date.now().toString(36)}`;

function log(level: string, message: string, ctx: Record<string, unknown> = {}): void {
  process.stdout.write(
    JSON.stringify({ ts: new Date().toISOString(), level, message, instance: instanceId, ...ctx }) + '\n',
  );
}

/**
 * Initialize Redis pub/sub connections.
 * Call once at startup. No-op if REDIS_URL is not set.
 */
export async function initRedisPubSub(): Promise<boolean> {
  const redisUrl = process.env.REDIS_URL || process.env.REDIS_HOST;
  if (!redisUrl) {
    log('info', 'redis_pubsub_disabled', { reason: 'No REDIS_URL or REDIS_HOST configured' });
    return false;
  }

  const url = redisUrl.startsWith('redis://') ? redisUrl : `redis://${redisUrl}:${process.env.REDIS_PORT || '6379'}`;
  const password = process.env.REDIS_PASSWORD || undefined;

  try {
    pub = createClient({ url, password });
    sub = pub.duplicate();

    pub.on('error', (err) => log('error', 'redis_pub_error', { error: err.message }));
    sub.on('error', (err) => log('error', 'redis_sub_error', { error: err.message }));

    await pub.connect();
    await sub.connect();

    // Subscribe to broadcast channel using pattern
    await sub.pSubscribe(`${PREFIX}room:*`, (message, channel) => {
      try {
        const data = JSON.parse(message);
        // Skip messages from this instance to avoid double-broadcast
        if (data._instanceId === instanceId) return;

        const room = channel.replace(`${PREFIX}room:`, '');
        delete data._instanceId;
        broadcastToRoom(room, data);
      } catch (err) {
        log('error', 'redis_message_parse_error', { error: (err as Error).message });
      }
    });

    connected = true;
    log('info', 'redis_pubsub_connected', { url: url.replace(/\/\/.*:.*@/, '//<redacted>@') });
    return true;
  } catch (err) {
    log('warn', 'redis_pubsub_failed', { error: (err as Error).message });
    pub = null;
    sub = null;
    return false;
  }
}

/**
 * Publish a message to Redis so other instances can broadcast it.
 * Call this AFTER the local broadcastToRoom.
 */
export function publishToRoom(room: string, data: object): void {
  if (!connected || !pub) return;

  const payload = JSON.stringify({ ...data, _instanceId: instanceId });
  pub.publish(`${PREFIX}room:${room}`, payload).catch((err) => {
    log('error', 'redis_publish_error', { room, error: (err as Error).message });
  });
}

/**
 * Check if Redis pub/sub is active.
 */
export function isPubSubConnected(): boolean {
  return connected;
}

/**
 * Graceful shutdown.
 */
export async function closePubSub(): Promise<void> {
  try {
    if (sub) await sub.quit();
    if (pub) await pub.quit();
  } catch {
    // ignore
  }
  pub = null;
  sub = null;
  connected = false;
}

export { instanceId };
