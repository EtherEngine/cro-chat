import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../../../api/client';
import type { Call } from '../../../types';

export interface SimScenario {
  id: string;
  label: string;
  description: string;
  bot_actions: string[];
}

export interface SimulatorState {
  loading: boolean;
  error: string | null;
  scenarios: SimScenario[];
  activeCallId: number | null;
  activeScenario: string | null;
  lastBotAction: string | null;
}

const INITIAL: SimulatorState = {
  loading: false,
  error: null,
  scenarios: [],
  activeCallId: null,
  activeScenario: null,
  lastBotAction: null,
};

/**
 * Hook used by CallSimulatorPanel.
 * Wraps the /api/dev/calls/* endpoints and manages local panel state.
 *
 * Only renders/activates when import.meta.env.DEV is true.
 */
export function useCallSimulator() {
  const [state, setState] = useState<SimulatorState>(INITIAL);

  // Keep a ref so cleanup/unmount handlers can read the latest call ID
  const activeCallIdRef = useRef<number | null>(null);
  activeCallIdRef.current = state.activeCallId;

  // Load scenario catalog on mount
  useEffect(() => {
    api.dev
      .scenarios()
      .then(({ scenarios }) => setState((s) => ({ ...s, scenarios })))
      .catch(() => {}); // silent — backend may not be running
  }, []);

  // Cancel the active call when the component unmounts (page refresh, navigate away)
  useEffect(() => {
    return () => {
      const callId = activeCallIdRef.current;
      if (callId != null) {
        api.dev.botCallAction(callId, 'cancel').catch(() => {});
      }
    };
  }, []);

  const simulateIncoming = useCallback(async (scenarioId: string) => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const res = await api.dev.simulateCall({ scenario: scenarioId });
      setState((s) => ({
        ...s,
        loading: false,
        activeCallId: res.call.id,
        activeScenario: scenarioId,
        lastBotAction: null,
      }));
    } catch (err) {
      setState((s) => ({
        ...s,
        loading: false,
        error: (err as Error).message,
      }));
    }
  }, []);

  const botAction = useCallback(
    async (action: string) => {
      if (!state.activeCallId) return;
      setState((s) => ({ ...s, loading: true, error: null }));
      try {
        await api.dev.botCallAction(state.activeCallId, action);
        setState((s) => ({
          ...s,
          loading: false,
          lastBotAction: action,
          // Keep activeCallId so dev can see it in the panel after the action
        }));
      } catch (err) {
        setState((s) => ({
          ...s,
          loading: false,
          error: (err as Error).message,
        }));
      }
    },
    [state.activeCallId],
  );

  /**
   * Cancel any still-active call on the backend, then reset panel state.
   * Safe to call even after the call has already ended — backend returns 200.
   */
  const clearActiveCall = useCallback(async () => {
    const callId = state.activeCallId;
    if (callId != null) {
      try {
        await api.dev.botCallAction(callId, 'cancel');
      } catch {
        // best-effort — call may already be ended
      }
    }
    activeCallIdRef.current = null;
    setState((s) => ({ ...s, activeCallId: null, activeScenario: null, lastBotAction: null, error: null }));
  }, [state.activeCallId]);

  /**
   * Force-clears any stuck "Wird angerufen" presence state on the backend.
   * Use this when the panel was closed/refreshed without cancelling the call.
   */
  const forceResetPresence = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      await api.dev.resetPresence();
      activeCallIdRef.current = null;
      setState((s) => ({
        ...s,
        loading: false,
        activeCallId: null,
        activeScenario: null,
        lastBotAction: null,
        error: null,
      }));
    } catch (err) {
      setState((s) => ({ ...s, loading: false, error: (err as Error).message }));
    }
  }, []);

  return { state, simulateIncoming, botAction, clearActiveCall, forceResetPresence };
}
