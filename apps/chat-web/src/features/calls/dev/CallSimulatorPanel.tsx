import { useState } from 'react';
import { useCallSimulator } from './useCallSimulator';

/**
 * Floating developer panel for simulating 1:1 audio calls.
 *
 * Features:
 *  - Select a scenario and trigger an incoming call from the dev bot
 *  - Execute bot-side actions (cancel, hangup) to drive state transitions
 *  - Displays current active simulation call ID for correlation with logs
 *
 * Rendering guard:
 *   Only rendered when import.meta.env.DEV is true (Vite strips it from
 *   production builds via dead-code elimination).
 *
 * Usage:
 *   import { CallSimulatorPanel } from './features/calls/dev/CallSimulatorPanel';
 *   // In App.tsx or a layout component, next to <CallOverlay>:
 *   {import.meta.env.DEV && <CallSimulatorPanel />}
 *
 * Microphone:
 *   Set VITE_CALL_SIMULATION=true in .env.local to replace getUserMedia with
 *   a silent oscillator (no microphone needed when accepting calls).
 *   Leave it unset (or false) to use the real microphone on accept.
 */
export function CallSimulatorPanel() {
  // Hard guard — should never render outside dev builds
  if (!import.meta.env.DEV) return null;

  return <CallSimulatorPanelInner />;
}

function CallSimulatorPanelInner() {
  const { state, simulateIncoming, botAction, clearActiveCall, forceResetPresence } = useCallSimulator();
  const [selectedScenario, setSelectedScenario] = useState('ring_only');
  const [collapsed, setCollapsed] = useState(false);

  const simMode = import.meta.env.VITE_CALL_SIMULATION === 'true';

  return (
    <div
      style={{
        position: 'fixed',
        bottom: 16,
        right: 16,
        zIndex: 9999,
        width: collapsed ? 'auto' : 320,
        background: '#18181b',
        border: '1px solid #3f3f46',
        borderRadius: 8,
        fontFamily: 'monospace',
        fontSize: 12,
        color: '#e4e4e7',
        boxShadow: '0 4px 24px rgba(0,0,0,0.5)',
        overflow: 'hidden',
      }}
    >
      {/* Header */}
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          padding: '6px 10px',
          background: '#09090b',
          borderBottom: collapsed ? 'none' : '1px solid #3f3f46',
          cursor: 'pointer',
          userSelect: 'none',
        }}
        onClick={() => setCollapsed((c) => !c)}
      >
        <span style={{ fontWeight: 700, color: '#a78bfa' }}>
          📞 Call Simulator {simMode ? '(SIM)' : '(MIC)'}
        </span>
        <span style={{ color: '#71717a' }}>{collapsed ? '▲' : '▼'}</span>
      </div>

      {!collapsed && (
        <div style={{ padding: 10, display: 'flex', flexDirection: 'column', gap: 8 }}>
          {/* Sim mode badge */}
          {simMode ? (
            <div style={{ color: '#86efac', fontSize: 11 }}>
              ✓ VITE_CALL_SIMULATION=true — kein Mikrofon benötigt
            </div>
          ) : (
            <div style={{ color: '#fbbf24', fontSize: 11 }}>
              ⚠ VITE_CALL_SIMULATION nicht gesetzt — Mikrofon wird beim Annehmen abgefragt
            </div>
          )}

          {/* Scenario selector */}
          <div>
            <label style={{ color: '#a1a1aa', display: 'block', marginBottom: 4 }}>
              Szenario
            </label>
            <select
              value={selectedScenario}
              onChange={(e) => setSelectedScenario(e.target.value)}
              disabled={state.loading || !!state.activeCallId}
              style={{
                width: '100%',
                background: '#27272a',
                border: '1px solid #52525b',
                borderRadius: 4,
                color: '#e4e4e7',
                padding: '4px 6px',
                fontSize: 12,
              }}
            >
              {state.scenarios.length === 0 ? (
                <option value="ring_only">ring_only</option>
              ) : (
                state.scenarios.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.label}
                  </option>
                ))
              )}
            </select>
            {state.scenarios.find((s) => s.id === selectedScenario) && (
              <div style={{ color: '#71717a', marginTop: 3, fontSize: 11 }}>
                {state.scenarios.find((s) => s.id === selectedScenario)?.description}
              </div>
            )}
          </div>

          {/* Trigger button */}
          {!state.activeCallId ? (
            <button
              onClick={() => simulateIncoming(selectedScenario)}
              disabled={state.loading}
              style={btnStyle('#7c3aed', state.loading)}
            >
              {state.loading ? 'Wird gestartet…' : '▶ Eingehenden Anruf simulieren'}
            </button>
          ) : (
            <>
              {/* Active call info */}
              <div
                style={{
                  background: '#1c1c1e',
                  border: '1px solid #52525b',
                  borderRadius: 4,
                  padding: '5px 8px',
                }}
              >
                <div style={{ color: '#a1a1aa' }}>Aktiver Simulations-Anruf</div>
                <div style={{ color: '#a78bfa', fontWeight: 700 }}>
                  Call ID #{state.activeCallId}
                </div>
                {state.lastBotAction && (
                  <div style={{ color: '#86efac', marginTop: 2 }}>
                    ✓ Bot: {state.lastBotAction}
                  </div>
                )}
              </div>

              {/* Bot action buttons */}
              <div style={{ display: 'flex', gap: 6 }}>
                <button
                  onClick={() => botAction('cancel')}
                  disabled={state.loading}
                  style={btnStyle('#b45309', state.loading)}
                >
                  Bot: Abbrechen
                </button>
                <button
                  onClick={() => botAction('hangup')}
                  disabled={state.loading}
                  style={btnStyle('#dc2626', state.loading)}
                >
                  Bot: Auflegen
                </button>
              </div>

              <button
                onClick={() => void clearActiveCall()}
                disabled={state.loading}
                style={btnStyle('#3f3f46', state.loading)}
              >
                {state.loading ? 'Wird beendet…' : 'Panel zurücksetzen'}
              </button>
            </>
          )}

          {/* Error display */}
          {state.error && (
            <div
              style={{
                background: '#450a0a',
                border: '1px solid #7f1d1d',
                borderRadius: 4,
                padding: '5px 8px',
                color: '#fca5a5',
                wordBreak: 'break-word',
              }}
            >
              {state.error}
            </div>
          )}

          {/* Hint footer */}
          <div style={{ color: '#52525b', fontSize: 10, borderTop: '1px solid #27272a', paddingTop: 6 }}>
            Backend: APP_ENV=local · Route: POST /api/dev/calls/simulate
          </div>

          {/* Force-reset stuck presence */}
          <button
            onClick={() => void forceResetPresence()}
            disabled={state.loading}
            style={{ ...btnStyle('#374151', state.loading), marginTop: 2 }}
            title='Löscht alle hängengebliebenen "Wird angerufen"-Statuseinträge'
          >
            🔄 Presence zurücksetzen
          </button>
        </div>
      )}
    </div>
  );
}

function btnStyle(bg: string, disabled: boolean): React.CSSProperties {
  return {
    flex: 1,
    background: disabled ? '#27272a' : bg,
    border: 'none',
    borderRadius: 4,
    color: disabled ? '#71717a' : '#fff',
    padding: '5px 8px',
    cursor: disabled ? 'not-allowed' : 'pointer',
    fontSize: 12,
    fontFamily: 'monospace',
    fontWeight: 600,
    transition: 'background 0.15s',
  };
}
