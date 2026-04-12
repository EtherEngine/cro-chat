/**
 * Mock RealtimeClient that captures outgoing signals and lets tests emit
 * inbound events synchronously.
 *
 * Usage:
 *   import { MockRealtimeClient } from '../mocks/realtime';
 *
 *   const client = new MockRealtimeClient();
 *   setRealtimeClient(client);
 *   client.emit('call.ringing', 'user:1', { call_id: 1, ... });
 */

import type { RealtimeEvent } from '../../realtime/socket';

type EventHandler = (event: RealtimeEvent) => void;
type ConnectionHandler = (connected: boolean) => void;

export class MockRealtimeClient {
  private handlers = new Set<EventHandler>();
  private connectionHandlers = new Set<ConnectionHandler>();

  /** All messages sent via send() — inspect in tests. */
  sentMessages: Array<Record<string, unknown>> = [];

  /** All rooms currently subscribed via subscribe(). */
  subscribedRooms = new Set<string>();

  // ── RealtimeClient interface ──────────────────

  connect = vi.fn().mockResolvedValue(undefined);

  disconnect = vi.fn().mockImplementation(() => {
    this.handlers.clear();
    this.subscribedRooms.clear();
  });

  subscribe = vi.fn().mockImplementation((room: string) => {
    this.subscribedRooms.add(room);
  });

  unsubscribe = vi.fn().mockImplementation((room: string) => {
    this.subscribedRooms.delete(room);
  });

  setSubscriptions = vi.fn().mockImplementation((rooms: string[]) => {
    this.subscribedRooms.clear();
    rooms.forEach((r) => this.subscribedRooms.add(r));
  });

  send = vi.fn().mockImplementation((data: Record<string, unknown>) => {
    this.sentMessages.push(data);
    return true;
  });

  onEvent = vi.fn().mockImplementation((handler: EventHandler) => {
    this.handlers.add(handler);
    return () => this.handlers.delete(handler);
  });

  onConnection = vi.fn().mockImplementation((handler: ConnectionHandler) => {
    this.connectionHandlers.add(handler);
    return () => this.connectionHandlers.delete(handler);
  });

  isConnected = vi.fn().mockReturnValue(true);

  getLastEventId = vi.fn().mockReturnValue(0);

  getUptime = vi.fn().mockReturnValue(1000);

  forceReconnect = vi.fn();

  reconnectCount = 0;

  // ── Test helpers ─────────────────────────────

  /**
   * Emit a realtime event to all registered handlers.
   * Use this in tests to simulate incoming server events.
   */
  emit(type: string, room: string, payload: Record<string, unknown>) {
    const event: RealtimeEvent = {
      type,
      room,
      payload,
      event_id: Math.floor(Math.random() * 1000) + 1,
      timestamp: new Date().toISOString(),
    };
    for (const handler of this.handlers) {
      handler(event);
    }
  }

  /**
   * Simulate connection state change.
   */
  simulateConnection(connected: boolean) {
    for (const handler of this.connectionHandlers) {
      handler(connected);
    }
  }

  /**
   * Get all signals sent of a specific type. Useful to verify that an offer,
   * answer, or ICE candidate was relayed correctly.
   */
  getSentSignals(action: string): Array<Record<string, unknown>> {
    return this.sentMessages.filter((m) => m.action === action || m.type === action);
  }

  /**
   * Reset captured state between tests.
   */
  reset() {
    this.sentMessages = [];
    this.subscribedRooms.clear();
    vi.clearAllMocks();
  }
}
