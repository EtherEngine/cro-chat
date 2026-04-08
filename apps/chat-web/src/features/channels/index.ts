import { useCallback, useRef } from 'react';
import { useApp } from '../../store';
import { api } from '../../api/client';
import { usePolling } from '../../hooks/usePolling';

const UNREAD_INTERVAL = 10_000;
const HIDDEN_FACTOR = 3;

export function useUnreadPolling() {
  const { dispatch } = useApp();
  const inflight = useRef(false);

  const poll = useCallback(async () => {
    if (inflight.current) return;
    inflight.current = true;
    try {
      const unread = await api.unread.counts();
      dispatch({ type: 'SET_UNREAD', unread });
    } catch {
      // retry next tick
    } finally {
      inflight.current = false;
    }
  }, [dispatch]);

  usePolling(poll, {
    interval: UNREAD_INTERVAL,
    hiddenFactor: HIDDEN_FACTOR,
    enabled: true,
  });
}