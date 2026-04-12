import 'dotenv/config';
import { createServer } from 'node:http';
import { WebSocketServer, type WebSocket } from 'ws';
import { URL } from 'node:url';
import { initDb, db } from './db.js';
import { verifyTicket, isChannelMember, isConversationMember, isSpaceMemberForChannel, isSpaceMember } from './auth.js';
import { registerClient, removeClient, getClient, joinRoom, leaveRoom, sendToUser, stats } from './rooms.js';
import { startPoller } from './poller.js';
import { initRedisPubSub, isPubSubConnected, closePubSub, instanceId } from './pubsub.js';

// ── Structured Logger ───────────────────────────────────────

function log(level: string, message: string, ctx: Record<string, unknown> = {}): void {
  const entry = { ts: new Date().toISOString(), level, message, ...ctx };
  process.stdout.write(JSON.stringify(entry) + '\n');
}

// ── Metrics ─────────────────────────────────────────────────

const metrics = {
  connections_total: 0,
  disconnections_total: 0,
  messages_received: 0,
  subscribe_success: 0,
  subscribe_denied: 0,
  auth_failed: 0,
  errors: 0,
  signal_relayed: 0,
  signal_denied: 0,
  signal_rate_limited: 0,
};

// ── Config ──────────────────────────────────────────────────

const PORT = parseInt(process.env.WS_PORT || '3001', 10);
const WS_SECRET = process.env.WS_SECRET || 'change-me-in-production';
const CORS_ORIGIN = process.env.CORS_ORIGIN || 'http://localhost:5173';
const POLL_INTERVAL = parseInt(process.env.POLL_INTERVAL || '500', 10);

// ── Call Signaling Security ─────────────────────────────────

/** Per-connection sliding window rate limiter for call.signal events. */
const SIGNAL_RATE_WINDOW_MS = 10_000;  // 10 second window
const SIGNAL_RATE_MAX = 60;            // max 60 signals per window

/** Max payload size for signaling data (SDP + ICE candidates). */
const SIGNAL_MAX_PAYLOAD_BYTES = 16_384;  // 16 KB — SDP is ~2-4 KB, ICE ~200 B

/** Nonce dedup window to prevent replay attacks. */
const NONCE_TTL_MS = 30_000;              // accept nonces up to 30 seconds
const NONCE_CLEANUP_INTERVAL_MS = 60_000; // purge stale nonces every 60s

/** Per-user signal timestamps for rate limiting. */
const signalBuckets = new Map<number, number[]>();

/** Seen signal nonces: nonce → timestamp (for replay prevention). */
const seenNonces = new Set<string>();
const nonceTimestamps = new Map<string, number>();

/** Periodically purge stale nonces and empty rate buckets. */
setInterval(() => {
  const now = Date.now();
  for (const [nonce, ts] of nonceTimestamps) {
    if (now - ts > NONCE_TTL_MS * 2) {
      seenNonces.delete(nonce);
      nonceTimestamps.delete(nonce);
    }
  }
  for (const [uid, timestamps] of signalBuckets) {
    const cutoff = now - SIGNAL_RATE_WINDOW_MS;
    const filtered = timestamps.filter((t) => t > cutoff);
    if (filtered.length === 0) signalBuckets.delete(uid);
    else signalBuckets.set(uid, filtered);
  }
}, NONCE_CLEANUP_INTERVAL_MS);

function checkSignalRate(userId: number): boolean {
  const now = Date.now();
  const cutoff = now - SIGNAL_RATE_WINDOW_MS;
  const bucket = signalBuckets.get(userId) ?? [];
  const recent = bucket.filter((t) => t > cutoff);
  if (recent.length >= SIGNAL_RATE_MAX) return false;
  recent.push(now);
  signalBuckets.set(userId, recent);
  return true;
}

function checkNonce(nonce: string): boolean {
  if (seenNonces.has(nonce)) return false; // replay
  seenNonces.add(nonce);
  nonceTimestamps.set(nonce, Date.now());
  return true;
}

// ── Database ────────────────────────────────────────────────

initDb({
  host: process.env.DB_HOST || '127.0.0.1',
  port: parseInt(process.env.DB_PORT || '3306', 10),
  database: process.env.DB_NAME || 'cro_chat',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASS || '',
});

// ── HTTP server (for health check + WS upgrade) ────────────

const server = createServer((req, res) => {
  // CORS preflight
  res.setHeader('Access-Control-Allow-Origin', CORS_ORIGIN);
  res.setHeader('Access-Control-Allow-Methods', 'GET');

  if (req.url === '/health') {
    const s = stats();
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok', clients: s.clients, rooms: s.rooms }));
    return;
  }

  if (req.url === '/metrics') {
    const s = stats();
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      ts: new Date().toISOString(),
      instance_id: instanceId,
      ws_connections_current: s.clients,
      ws_rooms_current: s.rooms,
      redis_pubsub: isPubSubConnected(),
      ...metrics,
      uptime_s: Math.floor(process.uptime()),
      memory_mb: Math.round(process.memoryUsage().heapUsed / 1024 / 1024),
    }));
    return;
  }

  res.writeHead(404);
  res.end();
});

// ── WebSocket server ────────────────────────────────────────

const wss = new WebSocketServer({
  server,
  verifyClient: (info, cb) => {
    // Check origin
    const origin = info.origin || info.req.headers.origin;
    if (origin && origin !== CORS_ORIGIN) {
      cb(false, 403, 'Forbidden origin');
      return;
    }

    // Extract and verify ticket from query string
    const url = new URL(info.req.url || '/', `http://${info.req.headers.host}`);
    const ticket = url.searchParams.get('ticket');
    if (!ticket) {
      cb(false, 401, 'Missing ticket');
      return;
    }

    const userId = verifyTicket(ticket, WS_SECRET);
    if (!userId) {
      metrics.auth_failed++;
      log('warn', 'ws_auth_failed', { ticket: ticket.slice(0, 8) + '...' });
      cb(false, 401, 'Invalid or expired ticket');
      return;
    }

    // Attach userId for later use
    (info.req as any)._userId = userId;
    cb(true);
  },
});

wss.on('connection', (ws: WebSocket, req) => {
  const userId: number = (req as any)._userId;
  const client = registerClient(ws, userId);
  metrics.connections_total++;

  log('info', 'ws_connected', { user_id: userId, clients: stats().clients });

  // Send welcome
  ws.send(JSON.stringify({ type: 'connected', userId }));

  ws.on('message', async (raw) => {
    let msg: any;
    try {
      msg = JSON.parse(raw.toString());
    } catch {
      ws.send(JSON.stringify({ type: 'error', message: 'Invalid JSON' }));
      return;
    }

    metrics.messages_received++;
    await handleMessage(ws, userId, msg);
  });

  ws.on('close', () => {
    removeClient(ws);
    metrics.disconnections_total++;
    log('info', 'ws_disconnected', { user_id: userId, clients: stats().clients });
  });

  ws.on('error', (err) => {
    metrics.errors++;
    log('error', 'ws_error', { user_id: userId, error: err.message });
    removeClient(ws);
  });
});

// ── Message handler ─────────────────────────────────────────

async function handleMessage(ws: WebSocket, userId: number, msg: any): Promise<void> {
  const { action, room } = msg;

  if (action === 'subscribe' && typeof room === 'string') {
    const allowed = await checkRoomAccess(room, userId);
    if (!allowed) {
      metrics.subscribe_denied++;
      log('warn', 'ws_subscribe_denied', { user_id: userId, room });
      ws.send(JSON.stringify({ type: 'error', message: 'Access denied', room }));
      return;
    }
    const client = getClient(ws);
    if (client) {
      joinRoom(client, room);
      metrics.subscribe_success++;
      ws.send(JSON.stringify({ type: 'subscribed', room }));
    }
    return;
  }

  if (action === 'unsubscribe' && typeof room === 'string') {
    const client = getClient(ws);
    if (client) {
      leaveRoom(client, room);
      ws.send(JSON.stringify({ type: 'unsubscribed', room }));
    }
    return;
  }

  // ── Call signaling relay ────────────────────────────────────
  // Forwards WebRTC signaling data (SDP, ICE) directly to the
  // target user.  Access control:
  //  1. Rate limit per-user (sliding window)
  //  2. Field validation + payload size limit
  //  3. Nonce dedup (replay protection)
  //  4. Sender must be a conversation member
  //  5. An active call must exist on that conversation
  //  6. Both sender and target must be participants of that call
  //  7. Target must match the other party (no spoofing)
  if (action === 'call.signal') {
    const { call_id, conversation_id, target_user_id, signal_type, payload, nonce } = msg;

    // ── Rate limit: prevent signaling flood ──
    if (!checkSignalRate(userId)) {
      metrics.signal_rate_limited++;
      log('warn', 'signal_rate_limited', { user_id: userId });
      ws.send(JSON.stringify({ type: 'error', message: 'Signal rate limit exceeded', code: 'SIGNAL_RATE_LIMITED' }));
      return;
    }

    // ── Field presence + type validation ──
    if (
      typeof call_id !== 'number' || !Number.isInteger(call_id) || call_id <= 0 ||
      typeof conversation_id !== 'number' || !Number.isInteger(conversation_id) || conversation_id <= 0 ||
      typeof target_user_id !== 'number' || !Number.isInteger(target_user_id) || target_user_id <= 0 ||
      typeof signal_type !== 'string' ||
      typeof payload !== 'object' || payload === null
    ) {
      metrics.signal_denied++;
      ws.send(JSON.stringify({ type: 'error', message: 'Invalid call signal fields', code: 'SIGNAL_INVALID_FIELDS' }));
      return;
    }

    // ── Cannot signal yourself ──
    if (target_user_id === userId) {
      metrics.signal_denied++;
      ws.send(JSON.stringify({ type: 'error', message: 'Cannot signal self', code: 'SIGNAL_SELF' }));
      return;
    }

    const validSignalTypes = ['offer', 'answer', 'ice_candidate'];
    if (!validSignalTypes.includes(signal_type)) {
      metrics.signal_denied++;
      ws.send(JSON.stringify({ type: 'error', message: 'Invalid signal_type', code: 'SIGNAL_INVALID_TYPE' }));
      return;
    }

    // ── Payload size limit (prevent oversized SDP/ICE payloads) ──
    const payloadStr = JSON.stringify(payload);
    if (payloadStr.length > SIGNAL_MAX_PAYLOAD_BYTES) {
      metrics.signal_denied++;
      ws.send(JSON.stringify({ type: 'error', message: 'Signal payload too large', code: 'SIGNAL_PAYLOAD_TOO_LARGE' }));
      return;
    }

    // ── Validate SDP structure for offer/answer ──
    if ((signal_type === 'offer' || signal_type === 'answer') && typeof payload.sdp !== 'string') {
      metrics.signal_denied++;
      ws.send(JSON.stringify({ type: 'error', message: 'Missing SDP in offer/answer', code: 'SIGNAL_MISSING_SDP' }));
      return;
    }

    // ── Validate ICE candidate structure ──
    if (signal_type === 'ice_candidate' && payload.candidate !== null && typeof payload.candidate !== 'string') {
      metrics.signal_denied++;
      ws.send(JSON.stringify({ type: 'error', message: 'Invalid ICE candidate', code: 'SIGNAL_INVALID_ICE' }));
      return;
    }

    // ── Replay protection via nonce (required) ──
    if (typeof nonce !== 'string' || nonce.length === 0) {
      metrics.signal_denied++;
      ws.send(JSON.stringify({ type: 'error', message: 'Nonce required', code: 'SIGNAL_NONCE_REQUIRED' }));
      return;
    }
    if (nonce.length > 64) {
      metrics.signal_denied++;
      ws.send(JSON.stringify({ type: 'error', message: 'Nonce too long', code: 'SIGNAL_NONCE_INVALID' }));
      return;
    }
    if (!checkNonce(nonce)) {
      metrics.signal_denied++;
      log('warn', 'signal_replay_detected', { user_id: userId, call_id, nonce });
      ws.send(JSON.stringify({ type: 'error', message: 'Duplicate signal (replay)', code: 'SIGNAL_REPLAY' }));
      return;
    }

    // ── 1. Verify sender is a member of the conversation ──
    const allowed = await isConversationMember(conversation_id, userId);
    if (!allowed) {
      metrics.signal_denied++;
      log('warn', 'signal_access_denied', { user_id: userId, conversation_id, call_id });
      ws.send(JSON.stringify({ type: 'error', message: 'Access denied', code: 'SIGNAL_ACCESS_DENIED' }));
      return;
    }

    // ── 2+3. Verify an active call exists and both users are participants ──
    const callOk = await verifyCallParticipants(call_id, conversation_id, userId, target_user_id);
    if (!callOk) {
      metrics.signal_denied++;
      log('warn', 'signal_invalid_call', { user_id: userId, target: target_user_id, call_id, conversation_id });
      ws.send(JSON.stringify({ type: 'error', message: 'No active call or invalid participants', code: 'SIGNAL_INVALID_CALL' }));
      return;
    }

    // ── 4. Relay to target user ──
    const delivered = sendToUser(target_user_id, {
      type: `webrtc.${signal_type}`,
      call_id,
      conversation_id,
      sender_id: userId,
      payload,
    });

    metrics.signal_relayed++;

    ws.send(JSON.stringify({
      type: 'webrtc.signal_ack',
      signal_type,
      delivered,
    }));

    log('info', 'call_signal_relayed', {
      user_id: userId,
      target: target_user_id,
      call_id,
      signal_type,
      conversation_id,
      delivered,
    });
    return;
  }

  ws.send(JSON.stringify({ type: 'error', message: 'Unknown action' }));
}

/**
 * Verify that an active call exists for the conversation and both
 * sender and target are caller/callee of that call.
 */
async function verifyCallParticipants(
  callId: number,
  conversationId: number,
  senderId: number,
  targetId: number,
): Promise<boolean> {
  const [rows] = await db().execute<any[]>(
    `SELECT caller_user_id, callee_user_id
     FROM calls
     WHERE id = ? AND conversation_id = ? AND status IN ('ringing', 'accepted')
     LIMIT 1`,
    [callId, conversationId],
  );
  if (rows.length === 0) return false;

  const { caller_user_id, callee_user_id } = rows[0];
  const participants = new Set([Number(caller_user_id), Number(callee_user_id)]);
  return participants.has(senderId) && participants.has(targetId);
}

/**
 * Verify the user has access to the requested room.
 * Rooms are formatted as "channel:123" or "conversation:456".
 */
async function checkRoomAccess(room: string, userId: number): Promise<boolean> {
  const [type, idStr] = room.split(':');
  const id = parseInt(idStr, 10);
  if (isNaN(id)) return false;

  if (type === 'channel') {
    // Direct membership or space membership (public channels)
    return (await isChannelMember(id, userId)) || (await isSpaceMemberForChannel(id, userId));
  }
  if (type === 'conversation') {
    return isConversationMember(id, userId);
  }
  if (type === 'space') {
    return isSpaceMember(id, userId);
  }
  // User-scoped room: each user can only subscribe to their own
  if (type === 'user') {
    return id === userId;
  }
  return false;
}

// ── Start ───────────────────────────────────────────────────

async function start() {
  // Initialize Redis pub/sub for horizontal scaling
  const pubsubReady = await initRedisPubSub();
  if (pubsubReady) {
    log('info', 'horizontal_scaling_enabled', { instance: instanceId });
  } else {
    log('info', 'single_instance_mode', { instance: instanceId });
  }

  startPoller(POLL_INTERVAL);

  server.listen(PORT, () => {
    log('info', 'realtime_started', { port: PORT, poll_interval: POLL_INTERVAL, instance: instanceId });
  });

  // Graceful shutdown
  const shutdown = async () => {
    log('info', 'shutting_down', { instance: instanceId });
    await closePubSub();
    wss.close();
    server.close();
    process.exit(0);
  };
  process.on('SIGTERM', shutdown);
  process.on('SIGINT', shutdown);
}

start();
