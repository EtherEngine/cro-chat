import { useCallback, useEffect, useState } from 'react';
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

  // Load scenario catalog on mount
  useEffect(() => {
    api.dev
      .scenarios()
      .then(({ scenarios }) => setState((s) => ({ ...s, scenarios })))
      .catch(() => {}); // silent — backend may not be running
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

  const clearActiveCall = useCallback(() => {
    setState((s) => ({ ...s, activeCallId: null, activeScenario: null, lastBotAction: null, error: null }));
  }, []);

  return { state, simulateIncoming, botAction, clearActiveCall };
}
