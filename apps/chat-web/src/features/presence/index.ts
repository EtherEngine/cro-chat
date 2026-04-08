import { useCallback, useRef } from 'react';
import { useApp } from '../../store';
import { api } from '../../api/client';
import { usePolling } from '../../hooks/usePolling';

const HEARTBEAT_INTERVAL = 20_000;
const STATUS_INTERVAL = 15_000;
const HIDDEN_FACTOR = 2;

export function usePresenceHeartbeat() {
  const inflight = useRef(false);

  const beat = useCallback(async () => {
    if (inflight.current) return;
    inflight.current = true;
    try {
      await api.presence.heartbeat();
    } catch {
      // retry next tick
    } finally {
      inflight.current = false;
    }
  }, []);

  usePolling(beat, {
    interval: HEARTBEAT_INTERVAL,
    hiddenFactor: 0,        // pause heartbeat when hidden
    enabled: true,
  });
}

export function usePresencePolling() {
  const { dispatch } = useApp();
  const inflight = useRef(false);

  const poll = useCallback(async () => {
    if (inflight.current) return;
    inflight.current = true;
    try {
      const { statuses } = await api.presence.status();
      dispatch({ type: 'SET_PRESENCE', presence: statuses });
    } catch {
      // retry next tick
    } finally {
      inflight.current = false;
    }
  }, [dispatch]);

  usePolling(poll, {
    interval: STATUS_INTERVAL,
    hiddenFactor: HIDDEN_FACTOR,
    enabled: true,
  });
}