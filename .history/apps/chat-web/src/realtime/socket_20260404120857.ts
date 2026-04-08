import { api } from '../api/client';

type EventHandler = (event: RealtimeEvent) => void;

export type RealtimeEvent = {
  type: string;
  room: string;
  payload: any;
  event_id: number;
  timestamp: string;
};

const WS_URL = 'ws://localhost:3001';

// Backoff: 1s, 2s, 4s, 8s, 16s, max 30s
const BASE_DELAY = 1000;
const MAX_DELAY = 30000;

export class RealtimeClient {
  private ws: WebSocket | null = null;
  private handlers = new Set<EventHandler>();
  private subscribedRooms = new Set<string>();
  private pendingSubscriptions = new Set<string>();
  private attempt = 0;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private disposed = false;
  private connected = false;

  /**
   * Connect to the WebSocket server.
   * Fetches a fresh HMAC ticket, then opens the connection.
   */
  async connect(): Promise<void> {
    if (this.disposed) return;

    try {
      const { ticket } = await api.auth.wsTicket();
      this.openSocket(ticket);
    } catch (err) {
      console.warn('[realtime] Failed to get WS ticket, retrying...', err);
      this.scheduleReconnect();
    }
  }

  private openSocket(ticket: string): void {
    if (this.disposed) return;

    this.ws = new WebSocket(`${WS_URL}?ticket=${encodeURIComponent(ticket)}`);

    this.ws.onopen = () => {
      console.log('[realtime] Connected');
      this.connected = true;
      this.attempt = 0;

      // Re-subscribe to all rooms we were in (or pending)
      for (const room of this.subscribedRooms) {
        this.sendSubscribe(room);
      }
      for (const room of this.pendingSubscriptions) {
        this.sendSubscribe(room);
      }
      this.pendingSubscriptions.clear();
    };

    this.ws.onmessage = (ev) => {
      try {
        const data = JSON.parse(ev.data as string);
        // Internal control messages
        if (data.type === 'connected' || data.type === 'subscribed' || data.type === 'unsubscribed') {
          return;
        }
        if (data.type === 'error') {
          console.warn('[realtime] Server error:', data.message);
          return;
        }
        // Domain events → dispatch to handlers
        for (const handler of this.handlers) {
          try {
            handler(data as RealtimeEvent);
          } catch (e) {
            console.error('[realtime] Handler error:', e);
          }
        }
      } catch {
        // Non-JSON message, ignore
      }
    };

    this.ws.onclose = () => {
      console.log('[realtime] Disconnected');
      this.connected = false;
      this.ws = null;
      if (!this.disposed) {
        this.scheduleReconnect();
      }
    };

    this.ws.onerror = (err) => {
      console.warn('[realtime] WebSocket error:', err);
      // onclose will fire after this
    };
  }

  private scheduleReconnect(): void {
    if (this.disposed || this.reconnectTimer) return;

    const delay = Math.min(BASE_DELAY * Math.pow(2, this.attempt), MAX_DELAY);
    this.attempt++;

    console.log(`[realtime] Reconnecting in ${delay}ms (attempt ${this.attempt})`);
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      this.connect();
    }, delay);
  }

  /**
   * Subscribe to a room (channel:X or conversation:Y).
   * Survives reconnects — will re-subscribe automatically.
   */
  subscribe(room: string): void {
    this.subscribedRooms.add(room);
    if (this.connected && this.ws) {
      this.sendSubscribe(room);
    } else {
      this.pendingSubscriptions.add(room);
    }
  }

  /**
   * Unsubscribe from a room.
   */
  unsubscribe(room: string): void {
    this.subscribedRooms.delete(room);
    this.pendingSubscriptions.delete(room);
    if (this.connected && this.ws) {
      this.ws.send(JSON.stringify({ action: 'unsubscribe', room }));
    }
  }

  /**
   * Replace all subscriptions with a new set.
   * Useful when switching channels.
   */
  setSubscriptions(rooms: string[]): void {
    const newRooms = new Set(rooms);

    // Unsubscribe from rooms we're no longer in
    for (const room of this.subscribedRooms) {
      if (!newRooms.has(room)) {
        this.unsubscribe(room);
      }
    }

    // Subscribe to new rooms
    for (const room of newRooms) {
      if (!this.subscribedRooms.has(room)) {
        this.subscribe(room);
      }
    }
  }

  /**
   * Register an event handler.
   * Returns an unsubscribe function.
   */
  onEvent(handler: EventHandler): () => void {
    this.handlers.add(handler);
    return () => this.handlers.delete(handler);
  }

  /**
   * Disconnect and clean up. No reconnect after this.
   */
  disconnect(): void {
    this.disposed = true;
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    if (this.ws) {
      this.ws.close();
      this.ws = null;
    }
    this.handlers.clear();
    this.subscribedRooms.clear();
    this.pendingSubscriptions.clear();
    this.connected = false;
  }

  isConnected(): boolean {
    return this.connected;
  }

  private sendSubscribe(room: string): void {
    this.ws?.send(JSON.stringify({ action: 'subscribe', room }));
  }
}
