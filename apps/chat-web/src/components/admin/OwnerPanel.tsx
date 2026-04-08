import { useState, useEffect } from 'react';
import { useApp } from '../../store';
import { api } from '../../api/client';
import type { AdminStats, AdminMember, ModerationAction } from '../../api/client';

type Props = { onClose: () => void };
type Tab = 'overview' | 'members' | 'moderation' | 'channels' | 'activity';

const TABS: { key: Tab; label: string }[] = [
  { key: 'overview', label: 'Übersicht' },
  { key: 'members', label: 'Mitglieder' },
  { key: 'channels', label: 'Kanäle' },
  { key: 'activity', label: 'Aktivität' },
  { key: 'moderation', label: 'Moderation' },
];

const ROLE_LABELS: Record<string, string> = {
  owner: 'Owner',
  admin: 'Admin',
  moderator: 'Moderator',
  member: 'Mitglied',
  guest: 'Gast',
};

const ROLE_COLORS: Record<string, string> = {
  owner: '#7c3aed',
  admin: '#2563eb',
  moderator: '#059669',
  member: '#6b7280',
  guest: '#9ca3af',
};

const ACTION_LABELS: Record<string, string> = {
  message_delete: 'Nachricht gelöscht',
  user_mute: 'Benutzer stumm',
  user_unmute: 'Stummschaltung aufgehoben',
  user_kick: 'Benutzer entfernt',
  role_change: 'Rolle geändert',
  channel_role_change: 'Kanalrolle geändert',
};

function formatBytes(mb: number): string {
  if (mb < 1) return `${Math.round(mb * 1024)} KB`;
  if (mb >= 1024) return `${(mb / 1024).toFixed(1)} GB`;
  return `${mb.toFixed(1)} MB`;
}

function ago(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'gerade eben';
  if (mins < 60) return `vor ${mins} Min.`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `vor ${hrs} Std.`;
  const days = Math.floor(hrs / 24);
  return `vor ${days} Tag${days > 1 ? 'en' : ''}`;
}

export function OwnerPanel({ onClose }: Props) {
  const { state } = useApp();
  const [tab, setTab] = useState<Tab>('overview');
  const [stats, setStats] = useState<AdminStats | null>(null);
  const [members, setMembers] = useState<AdminMember[]>([]);
  const [modLog, setModLog] = useState<ModerationAction[]>([]);
  const [loading, setLoading] = useState(true);
  const [roleChanging, setRoleChanging] = useState<number | null>(null);
  const [error, setError] = useState('');

  const spaceId = state.spaceId!;

  useEffect(() => {
    setLoading(true);
    setError('');
    Promise.all([
      api.admin.stats(spaceId),
      api.admin.members(spaceId),
      api.admin.moderationLog(spaceId),
    ]).then(([s, m, l]) => {
      setStats(s);
      setMembers(m.members);
      setModLog(l.log);
    }).catch((err) => {
      setError(err?.message || 'Fehler beim Laden der Statistiken.');
    }).finally(() => setLoading(false));
  }, [spaceId]);

  useEffect(() => {
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose();
    }
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
  }, [onClose]);

  async function handleRoleChange(userId: number, newRole: string) {
    if (userId === state.user?.id) return;
    setRoleChanging(userId);
    try {
      await api.admin.changeRole(spaceId, userId, newRole);
      setMembers((prev) => prev.map((m) => (m.id === userId ? { ...m, role: newRole } : m)));
    } catch (err: any) {
      alert(err?.message || 'Rolle konnte nicht geändert werden.');
    } finally {
      setRoleChanging(null);
    }
  }

  if (loading || !stats) {
    return (
      <div className="owner-backdrop" onClick={onClose}>
        <div className="owner-modal" onClick={(e) => e.stopPropagation()}>
          <div className="owner-loading">{error || 'Lade Statistiken...'}</div>
        </div>
      </div>
    );
  }

  const maxMessages = Math.max(...stats.dailyMessages.map((d) => d.count), 1);
  const s = stats; // narrowed non-null binding for nested functions

  function renderOverview() {
    return (
      <div className="owner-tab-content">
        <div className="owner-stat-grid">
          <StatCard label="Mitglieder" value={s.members.total} sub={`${s.members.online} online, ${s.members.away} abwesend`} color="#7c3aed" />
          <StatCard label="Kanäle" value={s.channels.total} sub={`${s.channels.public_count} öffentlich, ${s.channels.private_count} privat`} color="#2563eb" />
          <StatCard label="Nachrichten" value={s.messages.total} sub={`${s.messages.today} heute, ${s.messages.last_7_days} letzte 7d`} color="#059669" />
          <StatCard label="DMs" value={s.conversations.total} color="#d97706" />
          <StatCard label="Dateien" value={s.attachments.total} sub={formatBytes(s.attachments.total_mb)} color="#dc2626" />
          <StatCard label="Reaktionen" value={s.reactions.total} color="#db2777" />
          <StatCard label="Threads" value={s.threads.total} color="#0891b2" />
          <StatCard label="Pins" value={s.pins.total} color="#65a30d" />
        </div>

        <h4 className="owner-section-title">Rollen-Verteilung</h4>
        <div className="owner-role-bars">
          {(['owner', 'admin', 'moderator', 'member', 'guest'] as const).map((r) => {
            const count = s.members[r === 'member' ? 'members' : `${r}s` as keyof typeof s.members] as number;
            if (count === 0) return null;
            const pct = Math.round((count / s.members.total) * 100);
            return (
              <div key={r} className="owner-role-bar">
                <span className="owner-role-label" style={{ color: ROLE_COLORS[r] }}>{ROLE_LABELS[r]}</span>
                <div className="owner-bar-track">
                  <div className="owner-bar-fill" style={{ width: `${pct}%`, background: ROLE_COLORS[r] }} />
                </div>
                <span className="owner-role-count">{count}</span>
              </div>
            );
          })}
        </div>

        <h4 className="owner-section-title">Moderation</h4>
        <div className="owner-stat-grid owner-stat-grid-small">
          <StatCard label="Aktionen gesamt" value={s.moderation.total} color="#6b7280" small />
          <StatCard label="Msg gelöscht" value={s.moderation.message_deletes} color="#dc2626" small />
          <StatCard label="Stummschaltungen" value={s.moderation.mutes} color="#d97706" small />
          <StatCard label="Kicks" value={s.moderation.kicks} color="#7c2d12" small />
          <StatCard label="Rollenänderungen" value={s.moderation.role_changes} color="#4f46e5" small />
        </div>
      </div>
    );
  }

  function renderMembers() {
    const isOwner = state.spaceRole === 'owner';
    return (
      <div className="owner-tab-content">
        <div className="owner-member-count">{members.length} Mitglieder</div>
        <div className="owner-member-list">
          {members.map((m) => {
            const initials = m.display_name.split(' ').map((n) => n[0]).join('').slice(0, 2);
            const isSelf = m.id === state.user?.id;
            return (
              <div key={m.id} className="owner-member-row">
                <div className="owner-member-avatar" style={{ background: m.avatar_color }}>{initials}</div>
                <div className="owner-member-info">
                  <div className="owner-member-name">
                    {m.display_name}
                    {isSelf && <span className="owner-self-tag">Du</span>}
                  </div>
                  <div className="owner-member-email">{m.email}</div>
                </div>
                <div className="owner-member-status">
                  <span className={`owner-status-dot ${m.status}`} />
                  {m.status}
                </div>
                {isSelf || !isOwner ? (
                  <span className="owner-role-badge" style={{ background: ROLE_COLORS[m.role] + '20', color: ROLE_COLORS[m.role] }}>
                    {ROLE_LABELS[m.role] || m.role}
                  </span>
                ) : (
                  <select
                    className="owner-role-select"
                    value={m.role}
                    onChange={(e) => handleRoleChange(m.id, e.target.value)}
                    disabled={roleChanging === m.id || m.role === 'owner'}
                    style={{ borderColor: ROLE_COLORS[m.role] }}
                  >
                    <option value="admin">Admin</option>
                    <option value="moderator">Moderator</option>
                    <option value="member">Mitglied</option>
                    <option value="guest">Gast</option>
                  </select>
                )}
              </div>
            );
          })}
        </div>
      </div>
    );
  }

  function renderChannels() {
    return (
      <div className="owner-tab-content">
        <h4 className="owner-section-title">Top-Kanäle nach Nachrichten</h4>
        <div className="owner-top-list">
          {s.topChannels.map((ch, i) => (
            <div key={ch.id} className="owner-top-item">
              <span className="owner-top-rank">#{i + 1}</span>
              <span className="owner-top-color" style={{ background: ch.color }} />
              <span className="owner-top-name">{ch.name}</span>
              <span className="owner-top-meta">{ch.member_count} Mitglieder</span>
              <span className="owner-top-value">{ch.message_count} Nachrichten</span>
            </div>
          ))}
          {s.topChannels.length === 0 && <p className="owner-empty">Keine Kanäle vorhanden</p>}
        </div>

        <h4 className="owner-section-title">Kanal-Übersicht</h4>
        <div className="owner-stat-grid owner-stat-grid-small">
          <StatCard label="Gesamt" value={s.channels.total} color="#2563eb" small />
          <StatCard label="Öffentlich" value={s.channels.public_count} color="#059669" small />
          <StatCard label="Privat" value={s.channels.private_count} color="#dc2626" small />
        </div>
      </div>
    );
  }

  function renderActivity() {
    return (
      <div className="owner-tab-content">
        <h4 className="owner-section-title">Nachrichten / Tag (letzte 14 Tage)</h4>
        <div className="owner-chart">
          {s.dailyMessages.map((d) => (
            <div key={d.day} className="owner-chart-bar-wrapper">
              <div
                className="owner-chart-bar"
                style={{ height: `${Math.max((d.count / maxMessages) * 100, 4)}%` }}
                title={`${d.day}: ${d.count} Nachrichten`}
              />
              <span className="owner-chart-label">{d.day.slice(5)}</span>
            </div>
          ))}
          {s.dailyMessages.length === 0 && <p className="owner-empty">Keine Daten</p>}
        </div>

        <h4 className="owner-section-title">Aktivste Benutzer (30 Tage)</h4>
        <div className="owner-top-list">
          {s.topUsers.map((u, i) => {
            const initials = u.display_name.split(' ').map((n) => n[0]).join('').slice(0, 2);
            return (
              <div key={u.id} className="owner-top-item">
                <span className="owner-top-rank">#{i + 1}</span>
                <div className="owner-top-avatar" style={{ background: u.avatar_color }}>{initials}</div>
                <span className="owner-top-name">{u.display_name}</span>
                <span className="owner-top-value">{u.message_count} Nachrichten</span>
              </div>
            );
          })}
        </div>

        <h4 className="owner-section-title">Nachrichtenstatistik</h4>
        <div className="owner-stat-grid owner-stat-grid-small">
          <StatCard label="Bearbeitet" value={s.messages.edited} color="#d97706" small />
          <StatCard label="Gelöscht" value={s.messages.deleted} color="#dc2626" small />
          <StatCard label="Antworten" value={s.messages.replies} color="#0891b2" small />
          <StatCard label="Letzte 30d" value={s.messages.last_30_days} color="#059669" small />
        </div>
      </div>
    );
  }

  function renderModeration() {
    return (
      <div className="owner-tab-content">
        <h4 className="owner-section-title">Letzte Moderation-Aktionen</h4>
        {modLog.length === 0 ? (
          <p className="owner-empty">Keine Aktionen vorhanden</p>
        ) : (
          <div className="owner-mod-log">
            {modLog.map((a) => (
              <div key={a.id} className="owner-mod-item">
                <div className="owner-mod-header">
                  <span className="owner-mod-badge">{ACTION_LABELS[a.action_type] || a.action_type}</span>
                  <span className="owner-mod-time">{ago(a.created_at)}</span>
                </div>
                <div className="owner-mod-detail">
                  <strong>{a.actor_name}</strong>
                  {a.target_name && <> → <strong>{a.target_name}</strong></>}
                </div>
                {a.reason && <div className="owner-mod-reason">„{a.reason}"</div>}
              </div>
            ))}
          </div>
        )}
      </div>
    );
  }

  return (
    <div className="owner-backdrop" onClick={onClose}>
      <div className="owner-modal" onClick={(e) => e.stopPropagation()}>
        <div className="owner-header">
          <h2 className="owner-title">⚙ Owner Panel</h2>
          <button className="owner-close" onClick={onClose}>&times;</button>
        </div>
        <nav className="owner-tabs">
          {TABS.map((t) => (
            <button
              key={t.key}
              className={`owner-tab${tab === t.key ? ' active' : ''}`}
              onClick={() => setTab(t.key)}
            >
              {t.label}
            </button>
          ))}
        </nav>
        <div className="owner-body">
          {tab === 'overview' && renderOverview()}
          {tab === 'members' && renderMembers()}
          {tab === 'channels' && renderChannels()}
          {tab === 'activity' && renderActivity()}
          {tab === 'moderation' && renderModeration()}
        </div>
      </div>
    </div>
  );
}

function StatCard({ label, value, sub, color, small }: { label: string; value: number; sub?: string; color: string; small?: boolean }) {
  return (
    <div className={`owner-stat-card${small ? ' small' : ''}`}>
      <div className="owner-stat-value" style={{ color }}>{value.toLocaleString('de-DE')}</div>
      <div className="owner-stat-label">{label}</div>
      {sub && <div className="owner-stat-sub">{sub}</div>}
    </div>
  );
}
