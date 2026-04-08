import 'dotenv/config';
import { createServer } from 'node:http';
import { WebSocketServer, type WebSocket } from 'ws';
import { URL } from 'node:url';
import { initDb } from './db.js';
import { verifyTicket, isChannelMember, isConversationMember, isSpaceMemberForChannel } from './auth.js';
import { registerClient, removeClient, getClient, joinRoom, leaveRoom, stats } from './rooms.js';
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
};

// ── Config ──────────────────────────────────────────────────

const PORT = parseInt(process.env.WS_PORT || '3001', 10);
const WS_SECRET = process.env.WS_SECRET || 'change-me-in-production';
const CORS_ORIGIN = process.env.CORS_ORIGIN || 'http://localhost:5173';
const POLL_INTERVAL = parseInt(process.env.POLL_INTERVAL || '500', 10);

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

  ws.send(JSON.stringify({ type: 'error', message: 'Unknown action' }));
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
