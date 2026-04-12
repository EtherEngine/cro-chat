import { useState, useEffect, useCallback } from 'react';
import { api } from '../../api/client';
import type { CallAnalytics, CallHealth } from '../../api/client';
import { useApp } from '../../store';

/* ═══════════════════════════════════════════════════════════════
   Call Debug Panel — dev-only overlay for call observability.
   Toggle with Ctrl+Shift+K.
   ═══════════════════════════════════════════════════════════════ */

export function CallDebugPanel() {
  const { state } = useApp();
  const [visible, setVisible] = useState(false);
  const [metrics, setMetrics] = useState<CallAnalytics | null>(null);
  const [health, setHealth] = useState<CallHealth | null>(null);
  const [logs, setLogs] = useState<SecurityLogEntry[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<'metrics' | 'health' | 'logs'>('metrics');

  // Keyboard shortcut: Ctrl+Shift+K
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.ctrlKey && e.shiftKey && e.key === 'K') {
        e.preventDefault();
        setVisible((v) => !v);
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, []);

  const refresh = useCallback(async () => {
    const spaceId = state.spaceId;
    if (!spaceId) return;
    setLoading(true);
    setError(null);
    try {
      const [m, h] = await Promise.all([
        api.analytics.callMetrics(spaceId, 30),
        api.health.calls(),
      ]);
      setMetrics(m);
      setHealth(h);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Fehler beim Laden');
    } finally {
      setLoading(false);
    }
  }, [state.spaceId]);

  useEffect(() => {
    if (visible) refresh();
  }, [visible, refresh]);

  if (!visible) return null;

  return (
    <div className="call-debug-overlay">
      <div className="call-debug-panel">
        <div className="call-debug-header">
          <span className="call-debug-title">📞 Call Debug</span>
          <div className="call-debug-tabs">
            {(['metrics', 'health', 'logs'] as const).map((t) => (
              <button
                key={t}
                className={`call-debug-tab ${tab === t ? 'active' : ''}`}
                onClick={() => setTab(t)}
              >
                {t === 'metrics' ? 'Metriken' : t === 'health' ? 'Health' : 'Logs'}
              </button>
            ))}
          </div>
          <div className="call-debug-actions">
            <button className="call-debug-refresh" onClick={refresh} disabled={loading}>
              {loading ? '⏳' : '🔄'}
            </button>
            <button className="call-debug-close" onClick={() => setVisible(false)}>✕</button>
          </div>
        </div>

        <div className="call-debug-body">
          {error && <div className="call-debug-error">{error}</div>}

          {tab === 'metrics' && metrics && <MetricsTab metrics={metrics} />}
          {tab === 'health' && health && <HealthTab health={health} />}
          {tab === 'logs' && <LogsTab logs={logs} setLogs={setLogs} />}
        </div>
      </div>
    </div>
  );
}

/* ── Metrics Tab ─────────────────────────────── */

function MetricsTab({ metrics }: { metrics: CallAnalytics }) {
  return (
    <div className="call-debug-section">
      <div className="call-debug-grid">
        <MetricCard label="Anrufe gesamt" value={metrics.total_calls} />
        <MetricCard label="Angenommen" value={metrics.answered_calls} color="#22c55e" />
        <MetricCard label="Verpasst" value={metrics.missed_calls} color="#f59e0b" />
        <MetricCard label="Abgelehnt" value={metrics.rejected_calls} color="#ef4444" />
        <MetricCard label="Fehlgeschlagen" value={metrics.failed_calls} color="#dc2626" />
        <MetricCard label="Annahmequote" value={`${metrics.answer_rate}%`} color="#3b82f6" />
        <MetricCard label="Ø Dauer" value={fmtDuration(metrics.avg_duration_seconds)} />
        <MetricCard label="Max. Dauer" value={fmtDuration(metrics.max_duration_seconds)} />
      </div>

      {metrics.daily.length > 0 && (
        <>
          <h4 className="call-debug-subtitle">Täglicher Verlauf (letzte 30 Tage)</h4>
          <div className="call-debug-daily">
            <table className="call-debug-table">
              <thead>
                <tr>
                  <th>Datum</th>
                  <th>Gesamt</th>
                  <th>Angenommen</th>
                  <th>Verpasst</th>
                  <th>Ø Dauer</th>
                </tr>
              </thead>
              <tbody>
                {metrics.daily.slice(-14).map((d) => (
                  <tr key={d.date}>
                    <td>{new Date(d.date).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' })}</td>
                    <td>{d.total_calls}</td>
                    <td>{d.answered_calls}</td>
                    <td>{d.missed_calls}</td>
                    <td>{fmtDuration(d.avg_duration)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}

/* ── Health Tab ──────────────────────────────── */

function HealthTab({ health }: { health: CallHealth }) {
  const statusColor = health.status === 'ok' ? '#22c55e' : '#f59e0b';

  return (
    <div className="call-debug-section">
      <div className="call-debug-status" style={{ borderLeftColor: statusColor }}>
        <strong>Status:</strong>{' '}
        <span style={{ color: statusColor }}>{health.status.toUpperCase()}</span>
      </div>

      <h4 className="call-debug-subtitle">Aktive Anrufe</h4>
      <div className="call-debug-grid">
        {Object.entries(health.active_calls).length === 0 ? (
          <MetricCard label="Keine" value={0} />
        ) : (
          Object.entries(health.active_calls).map(([status, count]) => (
            <MetricCard key={status} label={status} value={count} />
          ))
        )}
      </div>

      <h4 className="call-debug-subtitle">Warnungen</h4>
      <div className="call-debug-grid">
        <MetricCard
          label="Veraltete Ringing"
          value={health.stale_ringing}
          color={health.stale_ringing > 0 ? '#ef4444' : '#22c55e'}
        />
        <MetricCard
          label="Fehler (1h)"
          value={health.recent_failures}
          color={health.recent_failures >= 10 ? '#ef4444' : '#22c55e'}
        />
        <MetricCard
          label="Signaling-Fehler (1h)"
          value={health.signaling_errors}
          color={health.signaling_errors >= 50 ? '#ef4444' : '#22c55e'}
        />
      </div>
    </div>
  );
}

/* ── Logs Tab ────────────────────────────────── */

type SecurityLogEntry = {
  id: number;
  user_id: number | null;
  event_type: string;
  severity: string;
  details: Record<string, unknown> | null;
  created_at: string;
};

function LogsTab({ logs, setLogs }: { logs: SecurityLogEntry[]; setLogs: (l: SecurityLogEntry[]) => void }) {
  const [loading, setLoading] = useState(false);

  const loadLogs = useCallback(async () => {
    setLoading(true);
    try {
      // Use admin realtime endpoint which includes security logs
      // For now, display whatever we've captured from realtime events
      const resp = await fetch('http://localhost/chat-api/public/health/calls', { credentials: 'include' });
      if (resp.ok) {
        // Health endpoint doesn't return logs, but we keep the log tab
        // as a placeholder for realtime call events captured via WebSocket
      }
    } catch {
      // silently ignore
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadLogs(); }, [loadLogs]);

  return (
    <div className="call-debug-section">
      <p className="call-debug-hint">
        Signaling-Events werden live über den WebSocket-Channel empfangen.
        Drücke Ctrl+Shift+K zum Schließen.
      </p>
      {logs.length === 0 ? (
        <div className="call-debug-empty">Keine Call-Logs in dieser Sitzung</div>
      ) : (
        <div className="call-debug-log-list">
          {logs.map((log) => (
            <div key={log.id} className={`call-debug-log-entry severity-${log.severity}`}>
              <span className="log-time">
                {new Date(log.created_at).toLocaleTimeString('de-DE')}
              </span>
              <span className="log-event">{log.event_type}</span>
              <span className="log-severity">{log.severity}</span>
              {log.details && (
                <span className="log-details">{JSON.stringify(log.details)}</span>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/* ── Shared components ───────────────────────── */

function MetricCard({ label, value, color }: { label: string; value: string | number; color?: string }) {
  return (
    <div className="call-debug-metric">
      <div className="metric-value" style={color ? { color } : undefined}>
        {value}
      </div>
      <div className="metric-label">{label}</div>
    </div>
  );
}

function fmtDuration(seconds: number): string {
  if (seconds === 0) return '0s';
  const m = Math.floor(seconds / 60);
  const s = Math.round(seconds % 60);
  if (m === 0) return `${s}s`;
  return `${m}m ${s}s`;
}
