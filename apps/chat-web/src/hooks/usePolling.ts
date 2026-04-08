import { useEffect, useRef, useSyncExternalStore } from 'react';

// ── Visibility ────────────────────────────────

function subscribe(cb: () => void) {
  document.addEventListener('visibilitychange', cb);
  return () => document.removeEventListener('visibilitychange', cb);
}

function getSnapshot() {
  return document.visibilityState === 'visible';
}

/** Returns true when the tab is visible/focused. */
export function useDocumentVisible(): boolean {
  return useSyncExternalStore(subscribe, getSnapshot);
}

// ── Polling ───────────────────────────────────

type PollingOptions = {
  /** Milliseconds between polls when the tab is visible. */
  interval: number;
  /** Multiplier applied to interval when the tab is hidden. (0 = pause) */
  hiddenFactor?: number;
  /** Whether polling is enabled at all. */
  enabled?: boolean;
};

/**
 * Calls `fn` on an interval, respecting tab visibility.
 * The callback is always invoked with the latest closure (no stale-closure issues).
 * Automatically cleans up on unmount or when deps change.
 */
export function usePolling(fn: () => void | Promise<void>, opts: PollingOptions) {
  const { interval, hiddenFactor = 0, enabled = true } = opts;
  const visible = useDocumentVisible();
  const savedFn = useRef(fn);
  savedFn.current = fn;

  useEffect(() => {
    if (!enabled) return;

    const effectiveInterval = visible ? interval : (hiddenFactor > 0 ? interval * hiddenFactor : 0);
    if (effectiveInterval <= 0) return;

    const id = setInterval(() => {
      savedFn.current();
    }, effectiveInterval);

    return () => clearInterval(id);
  }, [interval, hiddenFactor, enabled, visible]);
}
