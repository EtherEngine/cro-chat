import { useState, useEffect, useCallback, useMemo } from 'react';
import { useApp } from '../../store';
import { api } from '../../api/client';
import type { AdminStats, AdminMember, AdminChannel, ModerationAction, AdminJob, AdminJobStats, AdminNotificationStats, AdminRealtimeData } from '../../api/client';

/* ═══════════════════════════════════════════════════════════════
   Admin Panel — comprehensive workspace management for admin/owner
   ═══════════════════════════════════════════════════════════════ */

type Props = { onClose: () => void };
type Tab = 'dashboard' | 'members' | 'channels' | 'jobs' | 'notifications' | 'realtime' | 'modlog' | 'activity' | 'tools';

const TABS: { key: Tab; label: string; icon: string }[] = [
  { key: 'dashboard', label: 'Dashboard', icon: '📊' },
  { key: 'members', label: 'Benutzer', icon: '👥' },
  { key: 'channels', label: 'Kanäle', icon: '💬' },
  { key: 'jobs', label: 'Jobs', icon: '⚙️' },
  { key: 'notifications', label: 'Benachricht.', icon: '🔔' },
  { key: 'realtime', label: 'Echtzeit', icon: '📡' },
  { key: 'modlog', label: 'Audit-Log', icon: '📋' },
  { key: 'activity', label: 'Aktivität', icon: '📈' },
  { key: 'tools', label: 'Tools', icon: '🔧' },
];

const ROLE_LABELS: Record<string, string> = { owner: 'Owner', admin: 'Admin', moderator: 'Moderator', member: 'Mitglied', guest: 'Gast' };
const ROLE_COLORS: Record<string, string> = { owner: '#7c3aed', admin: '#2563eb', moderator: '#059669', member: '#6b7280', guest: '#9ca3af' };
const ROLE_ORDER = ['owner', 'admin', 'moderator', 'member', 'guest'];

const ACTION_LABELS: Record<string, string> = {
  message_delete: 'Nachricht gelöscht',
  user_mute: 'Stumm geschaltet',
  user_unmute: 'Stummschaltung aufgehoben',
  user_kick: 'Entfernt',
  role_change: 'Rolle geändert',
  channel_role_change: 'Kanalrolle geändert',
};

const ACTION_ICONS: Record<string, string> = {
  message_delete: '🗑️',
  user_mute: '🔇',
  user_unmute: '🔊',
  user_kick: '🚫',
  role_change: '🔄',
  channel_role_change: '🔄',
};

const MUTE_DURATIONS = [
  { label: '5 Minuten', value: 5 },
  { label: '15 Minuten', value: 15 },
  { label: '1 Stunde', value: 60 },
  { label: '6 Stunden', value: 360 },
  { label: '24 Stunden', value: 1440 },
  { label: '7 Tage', value: 10080 },
];

function fmtBytes(mb: number): string {
  if (mb < 1) return `${Math.round(mb * 1024)} KB`;
  if (mb >= 1024) return `${(mb / 1024).toFixed(1)} GB`;
  return `${mb.toFixed(1)} MB`;
}

function timeAgo(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime();
  const m = Math.floor(diff / 60000);
  if (m < 1) return 'gerade eben';
  if (m < 60) return `vor ${m} Min`;
  const h = Math.floor(m / 60);
  if (h < 24) return `vor ${h} Std`;
  const d = Math.floor(h / 24);
  if (d < 30) return `vor ${d} Tag${d > 1 ? 'en' : ''}`;
  return new Date(dateStr).toLocaleDateString('de-DE');
}

function initials(name: string): string {
  return name.split(' ').map((n) => n[0]).join('').slice(0, 2).toUpperCase();
}

function isMuted(mutedUntil: string | null): boolean {
  return mutedUntil !== null && new Date(mutedUntil).getTime() > Date.now();
}

export function AdminPanel({ onClose }: Props) {
  const { state } = useApp();
  const [tab, setTab] = useState<Tab>('dashboard');
  const [stats, setStats] = useState<AdminStats | null>(null);
  const [members, setMembers] = useState<AdminMember[]>([]);
  const [channels, setChannels] = useState<AdminChannel[]>([]);
  const [modLog, setModLog] = useState<ModerationAction[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  // Member management state
  const [memberSearch, setMemberSearch] = useState('');
  const [memberRoleFilter, setMemberRoleFilter] = useState<string>('all');
  const [memberStatusFilter, setMemberStatusFilter] = useState<string>('all');
  const [selectedMember, setSelectedMember] = useState<AdminMember | null>(null);
  const [actionBusy, setActionBusy] = useState(false);
  const [actionMsg, setActionMsg] = useState<{ type: 'ok' | 'err'; text: string } | null>(null);

  // Mute modal
  const [muteTarget, setMuteTarget] = useState<AdminMember | null>(null);
  const [muteDuration, setMuteDuration] = useState(60);
  const [muteReason, setMuteReason] = useState('');

  // Channel search
  const [channelSearch, setChannelSearch] = useState('');

  // Modlog filter
  const [logFilter, setLogFilter] = useState<string>('all');

  // Jobs state
  const [jobs, setJobs] = useState<AdminJob[]>([]);
  const [jobStats, setJobStats] = useState<AdminJobStats | null>(null);
  const [jobStatusFilter, setJobStatusFilter] = useState<string>('all');

  // Notifications state
  const [notifStats, setNotifStats] = useState<AdminNotificationStats | null>(null);

  // Realtime state
  const [realtimeData, setRealtimeData] = useState<AdminRealtimeData | null>(null);

  const spaceId = state.spaceId!;
  const isOwner = state.spaceRole === 'owner';

  const loadData = useCallback(() => {
    setLoading(true);
    setError('');
    Promise.all([
      api.admin.stats(spaceId),
      api.admin.members(spaceId),
      api.admin.channels(spaceId),
      api.admin.moderationLog(spaceId, 100),
      api.admin.jobs(spaceId).catch(() => null),
      api.admin.notifications(spaceId).catch(() => null),
      api.admin.realtime(spaceId).catch(() => null),
    ]).then(([s, m, ch, log, jobsRes, notifRes, rtRes]) => {
      setStats(s);
      setMembers(m.members);
      setChannels(ch.channels);
      setModLog(log.actions);
      if (jobsRes) { setJobs(jobsRes.jobs); setJobStats(jobsRes.stats); }
      if (notifRes) setNotifStats(notifRes);
      if (rtRes) setRealtimeData(rtRes);
    }).catch((err) => {
      setError(err?.message || 'Fehler beim Laden.');
    }).finally(() => setLoading(false));
  }, [spaceId]);

  useEffect(() => { loadData(); }, [loadData]);

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        if (muteTarget) { setMuteTarget(null); return; }
        if (selectedMember) { setSelectedMember(null); return; }
        onClose();
      }
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose, muteTarget, selectedMember]);

  function flash(type: 'ok' | 'err', text: string) {
    setActionMsg({ type, text });
    setTimeout(() => setActionMsg(null), 3000);
  }

  // ── Member actions ──
  async function handleRoleChange(userId: number, newRole: string) {
    setActionBusy(true);
    try {
      await api.admin.changeRole(spaceId, userId, newRole);
      setMembers((p) => p.map((m) => m.id === userId ? { ...m, role: newRole } : m));
      if (selectedMember?.id === userId) setSelectedMember((p) => p ? { ...p, role: newRole } : p);
      flash('ok', 'Rolle geändert');
    } catch (e: any) { flash('err', e?.message || 'Fehler'); }
    finally { setActionBusy(false); }
  }

  async function handleMute() {
    if (!muteTarget) return;
    setActionBusy(true);
    try {
      const res = await api.admin.muteMember(spaceId, muteTarget.id, muteDuration, muteReason || undefined);
      setMembers((p) => p.map((m) => m.id === muteTarget.id ? { ...m, muted_until: res.muted_until } : m));
      if (selectedMember?.id === muteTarget.id) setSelectedMember((p) => p ? { ...p, muted_until: res.muted_until } : p);
      flash('ok', `${muteTarget.display_name} stumm geschaltet`);
      setMuteTarget(null);
      setMuteReason('');
    } catch (e: any) { flash('err', e?.message || 'Fehler'); }
    finally { setActionBusy(false); }
  }

  async function handleUnmute(userId: number) {
    setActionBusy(true);
    try {
      await api.admin.unmuteMember(spaceId, userId);
      setMembers((p) => p.map((m) => m.id === userId ? { ...m, muted_until: null } : m));
      if (selectedMember?.id === userId) setSelectedMember((p) => p ? { ...p, muted_until: null } : p);
      flash('ok', 'Stummschaltung aufgehoben');
    } catch (e: any) { flash('err', e?.message || 'Fehler'); }
    finally { setActionBusy(false); }
  }

  async function handleRemoveMember(m: AdminMember) {
    if (!confirm(`${m.display_name} wirklich aus dem Space entfernen? Dies kann nicht rückgängig gemacht werden.`)) return;
    setActionBusy(true);
    try {
      await api.admin.removeMember(spaceId, m.id);
      setMembers((p) => p.filter((x) => x.id !== m.id));
      setSelectedMember(null);
      flash('ok', `${m.display_name} entfernt`);
    } catch (e: any) { flash('err', e?.message || 'Fehler'); }
    finally { setActionBusy(false); }
  }

  // ── Job actions ──
  async function handleRetryJob(jobId: number) {
    setActionBusy(true);
    try {
      const res = await api.admin.retryJob(spaceId, jobId);
      setJobs((p) => p.map((j) => j.id === jobId ? res.job : j));
      if (jobStats) setJobStats({ ...jobStats, failed: jobStats.failed - 1, pending: jobStats.pending + 1 });
      flash('ok', `Job #${jobId} erneut eingereiht`);
    } catch (e: any) { flash('err', e?.message || 'Fehler'); }
    finally { setActionBusy(false); }
  }

  async function handlePurgeJobs() {
    if (!confirm('Abgeschlossene und fehlgeschlagene Jobs älter als 24 Stunden löschen?')) return;
    setActionBusy(true);
    try {
      const res = await api.admin.purgeJobs(spaceId, 24);
      flash('ok', `${res.deleted} Jobs gelöscht`);
      loadData();
    } catch (e: any) { flash('err', e?.message || 'Fehler'); }
    finally { setActionBusy(false); }
  }

  // ── Filtered data ──
  const filteredMembers = useMemo(() => {
    let list = members;
    if (memberSearch) {
      const q = memberSearch.toLowerCase();
      list = list.filter((m) => m.display_name.toLowerCase().includes(q) || m.email.toLowerCase().includes(q));
    }
    if (memberRoleFilter !== 'all') list = list.filter((m) => m.role === memberRoleFilter);
    if (memberStatusFilter === 'online') list = list.filter((m) => m.status === 'online');
    if (memberStatusFilter === 'offline') list = list.filter((m) => m.status === 'offline');
    if (memberStatusFilter === 'muted') list = list.filter((m) => isMuted(m.muted_until));
    return list;
  }, [members, memberSearch, memberRoleFilter, memberStatusFilter]);

  const filteredChannels = useMemo(() => {
    if (!channelSearch) return channels;
    const q = channelSearch.toLowerCase();
    return channels.filter((c) => c.name.toLowerCase().includes(q));
  }, [channels, channelSearch]);

  const filteredLog = useMemo(() => {
    if (logFilter === 'all') return modLog;
    return modLog.filter((a) => a.action_type === logFilter);
  }, [modLog, logFilter]);

  const filteredJobs = useMemo(() => {
    if (jobStatusFilter === 'all') return jobs;
    return jobs.filter((j) => j.status === jobStatusFilter);
  }, [jobs, jobStatusFilter]);

  // ── Loading / Error state ──
  if (loading || !stats) {
    return (
      <div className="adm-backdrop" onClick={onClose}>
        <div className="adm-panel" onClick={(e) => e.stopPropagation()}>
          <div className="adm-loading">{error ? <><span className="adm-err-icon">⚠</span> {error}</> : 'Lade Admin-Panel...'}</div>
        </div>
      </div>
    );
  }

  const s = stats;
  const maxDaily = Math.max(...s.dailyMessages.map((d) => d.count), 1);

  // ═══════════════════════════════════════════
  // TAB: Dashboard
  // ═══════════════════════════════════════════
  function renderDashboard() {
    return (
      <div className="adm-tab-body">
        {/* Stat cards */}
        <div className="adm-cards">
          <StatCard icon="👥" label="Mitglieder" value={s.members.total} sub={`${s.members.online} online`} color="#7c3aed" />
          <StatCard icon="💬" label="Kanäle" value={s.channels.total} sub={`${s.channels.public_count} öff. / ${s.channels.private_count} priv.`} color="#2563eb" />
          <StatCard icon="✉️" label="Nachrichten" value={s.messages.total} sub={`${s.messages.today} heute`} color="#059669" />
          <StatCard icon="📩" label="DMs" value={s.conversations.total} color="#d97706" />
          <StatCard icon="📎" label="Dateien" value={s.attachments.total} sub={fmtBytes(s.attachments.total_mb)} color="#dc2626" />
          <StatCard icon="😀" label="Reaktionen" value={s.reactions.total} color="#db2777" />
          <StatCard icon="🧵" label="Threads" value={s.threads.total} color="#0891b2" />
          <StatCard icon="📌" label="Pins" value={s.pins.total} color="#65a30d" />
        </div>

        {/* Role distribution */}
        <div className="adm-section-row">
          <div className="adm-section-half">
            <h4 className="adm-h4">Rollen-Verteilung</h4>
            <div className="adm-role-bars">
              {ROLE_ORDER.map((r) => {
                const key = r === 'member' ? 'members' : `${r}s`;
                const count = (s.members as any)[key] as number;
                if (!count) return null;
                const pct = Math.round((count / s.members.total) * 100);
                return (
                  <div key={r} className="adm-role-bar">
                    <span className="adm-role-label" style={{ color: ROLE_COLORS[r] }}>{ROLE_LABELS[r]}</span>
                    <div className="adm-bar-track">
                      <div className="adm-bar-fill" style={{ width: `${pct}%`, background: ROLE_COLORS[r] }} />
                    </div>
                    <span className="adm-role-n">{count}</span>
                  </div>
                );
              })}
            </div>
          </div>

          <div className="adm-section-half">
            <h4 className="adm-h4">Moderation-Übersicht</h4>
            <div className="adm-mini-cards">
              <MiniCard label="Aktionen" value={s.moderation.total} color="#6b7280" />
              <MiniCard label="Gelöscht" value={s.moderation.message_deletes} color="#dc2626" />
              <MiniCard label="Mutes" value={s.moderation.mutes} color="#d97706" />
              <MiniCard label="Kicks" value={s.moderation.kicks} color="#7c2d12" />
              <MiniCard label="Rollen" value={s.moderation.role_changes} color="#4f46e5" />
            </div>
          </div>
        </div>

        {/* Daily chart */}
        <h4 className="adm-h4">Nachrichten / Tag (14 Tage)</h4>
        <div className="adm-chart">
          {s.dailyMessages.map((d) => (
            <div key={d.day} className="adm-chart-col">
              <span className="adm-chart-val">{d.count}</span>
              <div className="adm-chart-bar" style={{ height: `${Math.max((d.count / maxDaily) * 100, 4)}%` }} title={`${d.day}: ${d.count}`} />
              <span className="adm-chart-day">{d.day.slice(5)}</span>
            </div>
          ))}
          {s.dailyMessages.length === 0 && <p className="adm-empty">Keine Daten vorhanden</p>}
        </div>

        {/* Top channels + users side by side */}
        <div className="adm-section-row">
          <div className="adm-section-half">
            <h4 className="adm-h4">Top-Kanäle</h4>
            {s.topChannels.map((ch, i) => (
              <div key={ch.id} className="adm-top-row">
                <span className="adm-top-rank">#{i + 1}</span>
                <span className="adm-color-dot" style={{ background: ch.color }} />
                <span className="adm-top-name">{ch.name}</span>
                <span className="adm-top-val">{ch.message_count}</span>
              </div>
            ))}
          </div>
          <div className="adm-section-half">
            <h4 className="adm-h4">Aktivste Benutzer (30d)</h4>
            {s.topUsers.slice(0, 5).map((u, i) => (
              <div key={u.id} className="adm-top-row">
                <span className="adm-top-rank">#{i + 1}</span>
                <div className="adm-top-av" style={{ background: u.avatar_color }}>{initials(u.display_name)}</div>
                <span className="adm-top-name">{u.display_name}</span>
                <span className="adm-top-val">{u.message_count}</span>
              </div>
            ))}
          </div>
        </div>

        {/* Recent moderation */}
        {s.recentModeration.length > 0 && (
          <>
            <h4 className="adm-h4">Letzte Moderation-Aktionen</h4>
            <div className="adm-log-mini">
              {s.recentModeration.slice(0, 5).map((a) => (
                <div key={a.id} className="adm-log-row">
                  <span className="adm-log-icon">{ACTION_ICONS[a.action_type] || '📝'}</span>
                  <span className="adm-log-text">
                    <strong>{a.actor_name}</strong>
                    {' '}{ACTION_LABELS[a.action_type] || a.action_type}
                    {a.target_name && <> — <strong>{a.target_name}</strong></>}
                  </span>
                  <span className="adm-log-time">{timeAgo(a.created_at)}</span>
                </div>
              ))}
            </div>
          </>
        )}
      </div>
    );
  }

  // ═══════════════════════════════════════════
  // TAB: Members (Benutzerverwaltung)
  // ═══════════════════════════════════════════
  function renderMembers() {
    return (
      <div className="adm-tab-body">
        {/* Toolbar */}
        <div className="adm-toolbar">
          <input
            className="adm-search"
            placeholder="Benutzer suchen..."
            value={memberSearch}
            onChange={(e) => setMemberSearch(e.target.value)}
          />
          <select className="adm-filter" value={memberRoleFilter} onChange={(e) => setMemberRoleFilter(e.target.value)}>
            <option value="all">Alle Rollen</option>
            {ROLE_ORDER.map((r) => <option key={r} value={r}>{ROLE_LABELS[r]}</option>)}
          </select>
          <select className="adm-filter" value={memberStatusFilter} onChange={(e) => setMemberStatusFilter(e.target.value)}>
            <option value="all">Alle Status</option>
            <option value="online">Online</option>
            <option value="offline">Offline</option>
            <option value="muted">Stumm geschaltet</option>
          </select>
          <span className="adm-count">{filteredMembers.length} / {members.length}</span>
        </div>

        {/* Table */}
        <div className="adm-table-wrap">
          <table className="adm-table">
            <thead>
              <tr>
                <th>Benutzer</th>
                <th>Rolle</th>
                <th>Status</th>
                <th>Zuletzt gesehen</th>
                <th>Aktionen</th>
              </tr>
            </thead>
            <tbody>
              {filteredMembers.map((m) => {
                const me = m.id === state.user?.id;
                const muted = isMuted(m.muted_until);
                return (
                  <tr key={m.id} className={muted ? 'adm-row-muted' : ''}>
                    <td>
                      <div className="adm-user-cell">
                        <div className="adm-user-av" style={{ background: m.avatar_color }}>{initials(m.display_name)}</div>
                        <div>
                          <div className="adm-user-name">
                            {m.display_name}
                            {me && <span className="adm-you">Du</span>}
                            {muted && <span className="adm-muted-badge">🔇</span>}
                          </div>
                          <div className="adm-user-email">{m.email}</div>
                        </div>
                      </div>
                    </td>
                    <td>
                      {me || m.role === 'owner' || !isOwner ? (
                        <span className="adm-role-pill" style={{ background: ROLE_COLORS[m.role] + '18', color: ROLE_COLORS[m.role] }}>
                          {ROLE_LABELS[m.role]}
                        </span>
                      ) : (
                        <select
                          className="adm-role-sel"
                          value={m.role}
                          disabled={actionBusy}
                          onChange={(e) => handleRoleChange(m.id, e.target.value)}
                          style={{ borderColor: ROLE_COLORS[m.role] }}
                        >
                          {ROLE_ORDER.filter((r) => r !== 'owner').map((r) => (
                            <option key={r} value={r}>{ROLE_LABELS[r]}</option>
                          ))}
                        </select>
                      )}
                    </td>
                    <td>
                      <span className={`adm-status-dot ${m.status}`} />
                      {m.status}
                    </td>
                    <td className="adm-td-muted">
                      {m.last_seen_at ? timeAgo(m.last_seen_at) : '–'}
                    </td>
                    <td>
                      {!me && m.role !== 'owner' && (
                        <div className="adm-action-btns">
                          <button className="adm-btn-sm" title="Details" onClick={() => setSelectedMember(m)}>👤</button>
                          {muted ? (
                            <button className="adm-btn-sm adm-btn-green" title="Stummschaltung aufheben" disabled={actionBusy} onClick={() => handleUnmute(m.id)}>🔊</button>
                          ) : (
                            <button className="adm-btn-sm adm-btn-warn" title="Stumm schalten" disabled={actionBusy} onClick={() => { setMuteTarget(m); setMuteDuration(60); setMuteReason(''); }}>🔇</button>
                          )}
                          {isOwner && (
                            <button className="adm-btn-sm adm-btn-danger" title="Aus Space entfernen" disabled={actionBusy} onClick={() => handleRemoveMember(m)}>🚫</button>
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          {filteredMembers.length === 0 && <p className="adm-empty">Keine Benutzer gefunden</p>}
        </div>
      </div>
    );
  }

  // ═══════════════════════════════════════════
  // TAB: Channels
  // ═══════════════════════════════════════════
  function renderChannels() {
    return (
      <div className="adm-tab-body">
        <div className="adm-toolbar">
          <input className="adm-search" placeholder="Kanal suchen..." value={channelSearch} onChange={(e) => setChannelSearch(e.target.value)} />
          <span className="adm-count">{filteredChannels.length} Kanäle</span>
        </div>
        <div className="adm-table-wrap">
          <table className="adm-table">
            <thead>
              <tr>
                <th>Kanal</th>
                <th>Typ</th>
                <th>Mitglieder</th>
                <th>Nachrichten</th>
                <th>Letzte Aktivität</th>
              </tr>
            </thead>
            <tbody>
              {filteredChannels.map((ch) => (
                <tr key={ch.id}>
                  <td>
                    <div className="adm-ch-cell">
                      <span className="adm-ch-dot" style={{ background: ch.color }} />
                      <div>
                        <div className="adm-ch-name">{ch.name}</div>
                        {ch.description && <div className="adm-ch-desc">{ch.description}</div>}
                      </div>
                    </div>
                  </td>
                  <td>
                    <span className={`adm-ch-type ${ch.is_private ? 'private' : 'public'}`}>
                      {ch.is_private ? '🔒 Privat' : '🌐 Öffentlich'}
                    </span>
                  </td>
                  <td>{ch.member_count}</td>
                  <td>{ch.message_count}</td>
                  <td className="adm-td-muted">{ch.last_activity ? timeAgo(ch.last_activity) : '–'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    );
  }

  // ═══════════════════════════════════════════
  // TAB: Audit Log (Moderation)
  // ═══════════════════════════════════════════
  function renderModLog() {
    const actionTypes = [...new Set(modLog.map((a) => a.action_type))];
    return (
      <div className="adm-tab-body">
        <div className="adm-toolbar">
          <select className="adm-filter" value={logFilter} onChange={(e) => setLogFilter(e.target.value)}>
            <option value="all">Alle Aktionen</option>
            {actionTypes.map((t) => <option key={t} value={t}>{ACTION_LABELS[t] || t}</option>)}
          </select>
          <span className="adm-count">{filteredLog.length} Einträge</span>
        </div>
        <div className="adm-table-wrap">
          <table className="adm-table adm-table-log">
            <thead>
              <tr>
                <th>Aktion</th>
                <th>Durchgeführt von</th>
                <th>Ziel</th>
                <th>Grund</th>
                <th>Zeitpunkt</th>
              </tr>
            </thead>
            <tbody>
              {filteredLog.map((a) => {
                let meta: any = null;
                try { if (a.metadata) meta = JSON.parse(a.metadata); } catch { /* */ }
                return (
                  <tr key={a.id}>
                    <td>
                      <span className="adm-action-pill">
                        {ACTION_ICONS[a.action_type] || '📝'} {ACTION_LABELS[a.action_type] || a.action_type}
                      </span>
                    </td>
                    <td className="adm-log-actor">{a.actor_name}</td>
                    <td>{a.target_name || '–'}</td>
                    <td className="adm-td-muted">
                      {a.reason || '–'}
                      {meta?.old_role && meta?.new_role && (
                        <span className="adm-meta"> ({ROLE_LABELS[meta.old_role]} → {ROLE_LABELS[meta.new_role]})</span>
                      )}
                      {meta?.duration_minutes && (
                        <span className="adm-meta"> ({meta.duration_minutes} Min)</span>
                      )}
                    </td>
                    <td className="adm-td-muted">{timeAgo(a.created_at)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          {filteredLog.length === 0 && <p className="adm-empty">Keine Moderation-Aktionen vorhanden</p>}
        </div>
      </div>
    );
  }

  // ═══════════════════════════════════════════
  // TAB: Activity
  // ═══════════════════════════════════════════
  function renderActivity() {
    return (
      <div className="adm-tab-body">
        <div className="adm-section-row">
          <div className="adm-section-half">
            <h4 className="adm-h4">Nachrichtenstatistik</h4>
            <div className="adm-mini-cards">
              <MiniCard label="Heute" value={s.messages.today} color="#059669" />
              <MiniCard label="7 Tage" value={s.messages.last_7_days} color="#2563eb" />
              <MiniCard label="30 Tage" value={s.messages.last_30_days} color="#7c3aed" />
              <MiniCard label="Bearbeitet" value={s.messages.edited} color="#d97706" />
              <MiniCard label="Gelöscht" value={s.messages.deleted} color="#dc2626" />
              <MiniCard label="Antworten" value={s.messages.replies} color="#0891b2" />
            </div>
          </div>
          <div className="adm-section-half">
            <h4 className="adm-h4">Speicher &amp; Medien</h4>
            <div className="adm-mini-cards">
              <MiniCard label="Dateien" value={s.attachments.total} color="#dc2626" />
              <MiniCard label="Speicher" value={0} sub={fmtBytes(s.attachments.total_mb)} color="#d97706" />
              <MiniCard label="Reaktionen" value={s.reactions.total} color="#db2777" />
              <MiniCard label="Threads" value={s.threads.total} color="#0891b2" />
              <MiniCard label="Pins" value={s.pins.total} color="#65a30d" />
              <MiniCard label="DMs" value={s.conversations.total} color="#6b7280" />
            </div>
          </div>
        </div>

        <h4 className="adm-h4">Aktivste Benutzer (30 Tage)</h4>
        <div className="adm-table-wrap">
          <table className="adm-table">
            <thead>
              <tr><th>#</th><th>Benutzer</th><th>Nachrichten</th><th>Status</th></tr>
            </thead>
            <tbody>
              {s.topUsers.map((u, i) => (
                <tr key={u.id}>
                  <td className="adm-td-muted">{i + 1}</td>
                  <td>
                    <div className="adm-user-cell">
                      <div className="adm-user-av" style={{ background: u.avatar_color }}>{initials(u.display_name)}</div>
                      <span>{u.display_name}</span>
                    </div>
                  </td>
                  <td><strong>{u.message_count}</strong></td>
                  <td><span className={`adm-status-dot ${u.status}`} /> {u.status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    );
  }

  // ═══════════════════════════════════════════
  // TAB: Tools
  // ═══════════════════════════════════════════
  function renderTools() {
    return (
      <div className="adm-tab-body">
        <h4 className="adm-h4">Schnellaktionen</h4>
        <div className="adm-tools-grid">
          <button className="adm-tool-card" onClick={loadData}>
            <span className="adm-tool-icon">🔄</span>
            <span className="adm-tool-label">Daten aktualisieren</span>
            <span className="adm-tool-desc">Panel-Daten neu laden</span>
          </button>
          <button className="adm-tool-card" onClick={() => setTab('members')}>
            <span className="adm-tool-icon">👥</span>
            <span className="adm-tool-label">Benutzerverwaltung</span>
            <span className="adm-tool-desc">Rollen, Mute, Kick</span>
          </button>
          <button className="adm-tool-card" onClick={() => setTab('modlog')}>
            <span className="adm-tool-icon">📋</span>
            <span className="adm-tool-label">Audit-Log</span>
            <span className="adm-tool-desc">Alle Moderations-Aktionen</span>
          </button>
          <button className="adm-tool-card" onClick={() => setTab('channels')}>
            <span className="adm-tool-icon">💬</span>
            <span className="adm-tool-label">Kanal-Übersicht</span>
            <span className="adm-tool-desc">Alle Kanäle verwalten</span>
          </button>
        </div>

        <h4 className="adm-h4">Informationen</h4>
        <div className="adm-info-block">
          <div className="adm-info-row"><span>Deine Rolle</span><span className="adm-role-pill" style={{ background: ROLE_COLORS[state.spaceRole || 'member'] + '18', color: ROLE_COLORS[state.spaceRole || 'member'] }}>{ROLE_LABELS[state.spaceRole || 'member']}</span></div>
          <div className="adm-info-row"><span>Space-ID</span><code>{spaceId}</code></div>
          <div className="adm-info-row"><span>Mitglieder gesamt</span><span>{s.members.total}</span></div>
          <div className="adm-info-row"><span>Kanäle gesamt</span><span>{s.channels.total}</span></div>
          <div className="adm-info-row"><span>Nachrichten gesamt</span><span>{s.messages.total.toLocaleString('de-DE')}</span></div>
        </div>

        <h4 className="adm-h4">Berechtigungen</h4>
        <div className="adm-info-block">
          <div className="adm-info-row"><span>Rollen ändern</span><span>{isOwner ? '✅ Ja' : '❌ Nur Owner'}</span></div>
          <div className="adm-info-row"><span>Benutzer entfernen</span><span>{isOwner ? '✅ Ja' : '❌ Nur Owner'}</span></div>
          <div className="adm-info-row"><span>Benutzer stummschalten</span><span>✅ Ja</span></div>
          <div className="adm-info-row"><span>Audit-Log einsehen</span><span>✅ Ja</span></div>
          <div className="adm-info-row"><span>Statistiken einsehen</span><span>✅ Ja</span></div>
        </div>
      </div>
    );
  }

  // ═══════════════════════════════════════════
  // Render
  // ═══════════════════════════════════════════
  return (
    <div className="adm-backdrop" onClick={onClose}>
      <div className="adm-panel" onClick={(e) => e.stopPropagation()}>
        {/* Header */}
        <div className="adm-header">
          <div className="adm-title-row">
            <h2 className="adm-title">🛡️ Admin Panel</h2>
            <span className="adm-role-pill" style={{ background: ROLE_COLORS[state.spaceRole || 'member'] + '18', color: ROLE_COLORS[state.spaceRole || 'member'] }}>
              {ROLE_LABELS[state.spaceRole || 'member']}
            </span>
          </div>
          <button className="adm-close" onClick={onClose}>&times;</button>
        </div>

        {/* Toast */}
        {actionMsg && (
          <div className={`adm-toast ${actionMsg.type}`}>{actionMsg.text}</div>
        )}

        {/* Tabs */}
        <nav className="adm-tabs">
          {TABS.map((t) => (
            <button key={t.key} className={`adm-tab${tab === t.key ? ' active' : ''}`} onClick={() => setTab(t.key)}>
              <span className="adm-tab-icon">{t.icon}</span>
              {t.label}
            </button>
          ))}
        </nav>

        {/* Content */}
        <div className="adm-body">
          {tab === 'dashboard' && renderDashboard()}
          {tab === 'members' && renderMembers()}
          {tab === 'channels' && renderChannels()}
          {tab === 'modlog' && renderModLog()}
          {tab === 'activity' && renderActivity()}
          {tab === 'tools' && renderTools()}
        </div>

        {/* ── Member detail slide-over ── */}
        {selectedMember && (
          <div className="adm-detail-overlay" onClick={() => setSelectedMember(null)}>
            <div className="adm-detail" onClick={(e) => e.stopPropagation()}>
              <button className="adm-detail-close" onClick={() => setSelectedMember(null)}>&times;</button>
              <div className="adm-detail-av" style={{ background: selectedMember.avatar_color }}>
                {initials(selectedMember.display_name)}
              </div>
              <h3 className="adm-detail-name">{selectedMember.display_name}</h3>
              <p className="adm-detail-email">{selectedMember.email}</p>
              {selectedMember.title && <p className="adm-detail-title">{selectedMember.title}</p>}

              <div className="adm-detail-grid">
                <span>Rolle</span>
                <span className="adm-role-pill" style={{ background: ROLE_COLORS[selectedMember.role] + '18', color: ROLE_COLORS[selectedMember.role] }}>
                  {ROLE_LABELS[selectedMember.role]}
                </span>
                <span>Status</span>
                <span><span className={`adm-status-dot ${selectedMember.status}`} /> {selectedMember.status}</span>
                <span>Zuletzt gesehen</span>
                <span>{selectedMember.last_seen_at ? timeAgo(selectedMember.last_seen_at) : '–'}</span>
                <span>Stumm bis</span>
                <span>{isMuted(selectedMember.muted_until) ? new Date(selectedMember.muted_until!).toLocaleString('de-DE') : '–'}</span>
              </div>

              {selectedMember.role !== 'owner' && selectedMember.id !== state.user?.id && (
                <div className="adm-detail-actions">
                  {isOwner && (
                    <div className="adm-detail-role-row">
                      <label>Rolle ändern:</label>
                      <select
                        className="adm-role-sel"
                        value={selectedMember.role}
                        disabled={actionBusy}
                        onChange={(e) => handleRoleChange(selectedMember.id, e.target.value)}
                      >
                        {ROLE_ORDER.filter((r) => r !== 'owner').map((r) => (
                          <option key={r} value={r}>{ROLE_LABELS[r]}</option>
                        ))}
                      </select>
                    </div>
                  )}

                  <div className="adm-detail-btns">
                    {isMuted(selectedMember.muted_until) ? (
                      <button className="adm-btn adm-btn-green" disabled={actionBusy} onClick={() => handleUnmute(selectedMember.id)}>🔊 Stummschaltung aufheben</button>
                    ) : (
                      <button className="adm-btn adm-btn-warn" disabled={actionBusy} onClick={() => { setMuteTarget(selectedMember); setMuteDuration(60); setMuteReason(''); }}>🔇 Stumm schalten</button>
                    )}
                    {isOwner && (
                      <button className="adm-btn adm-btn-danger" disabled={actionBusy} onClick={() => handleRemoveMember(selectedMember)}>🚫 Aus Space entfernen</button>
                    )}
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* ── Mute modal ── */}
        {muteTarget && (
          <div className="adm-mute-overlay" onClick={() => setMuteTarget(null)}>
            <div className="adm-mute-modal" onClick={(e) => e.stopPropagation()}>
              <h3>🔇 {muteTarget.display_name} stumm schalten</h3>
              <label className="adm-mute-label">
                Dauer
                <select className="adm-mute-select" value={muteDuration} onChange={(e) => setMuteDuration(Number(e.target.value))}>
                  {MUTE_DURATIONS.map((d) => <option key={d.value} value={d.value}>{d.label}</option>)}
                </select>
              </label>
              <label className="adm-mute-label">
                Grund (optional)
                <input className="adm-mute-input" value={muteReason} onChange={(e) => setMuteReason(e.target.value)} placeholder="Grund eingeben..." maxLength={200} />
              </label>
              <div className="adm-mute-btns">
                <button className="adm-btn" onClick={() => setMuteTarget(null)}>Abbrechen</button>
                <button className="adm-btn adm-btn-warn" disabled={actionBusy} onClick={handleMute}>Stumm schalten</button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

// ── Helper components ──
function StatCard({ icon, label, value, sub, color }: { icon: string; label: string; value: number; sub?: string; color: string }) {
  return (
    <div className="adm-stat-card">
      <div className="adm-stat-icon">{icon}</div>
      <div className="adm-stat-val" style={{ color }}>{value.toLocaleString('de-DE')}</div>
      <div className="adm-stat-label">{label}</div>
      {sub && <div className="adm-stat-sub">{sub}</div>}
    </div>
  );
}

function MiniCard({ label, value, sub, color }: { label: string; value: number; sub?: string; color: string }) {
  return (
    <div className="adm-mini-card">
      <div className="adm-mini-val" style={{ color }}>{sub || value.toLocaleString('de-DE')}</div>
      <div className="adm-mini-label">{label}</div>
    </div>
  );
}
