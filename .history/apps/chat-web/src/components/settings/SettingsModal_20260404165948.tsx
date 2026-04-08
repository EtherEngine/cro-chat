import { useState, useEffect } from 'react';
import { useApp } from '../../store';
import { api } from '../../api/client';

type Props = { onClose: () => void };

type Section =
  | 'profil'
  | 'benachrichtigungen'
  | 'darstellung'
  | 'datenschutz'
  | 'sicherheit'
  | 'entwickler'
  | 'workspace';

const SECTIONS: { key: Section; label: string; icon: string }[] = [
  { key: 'profil', label: 'Profil', icon: '👤' },
  { key: 'benachrichtigungen', label: 'Benachrichtigungen', icon: '🔔' },
  { key: 'darstellung', label: 'Darstellung', icon: '🎨' },
  { key: 'datenschutz', label: 'Datenschutz', icon: '🔒' },
  { key: 'sicherheit', label: 'Sicherheit', icon: '🛡️' },
  { key: 'entwickler', label: 'Entwickler', icon: '🧑‍💻' },
  { key: 'workspace', label: 'Workspace', icon: '🏢' },
];

// ── localStorage helpers ──
const STORAGE_KEY = 'cro-settings';

type StoredSettings = {
  notifMentions: boolean;
  notifDMs: boolean;
  notifThreads: boolean;
  notifSound: boolean;
  fontSize: 'small' | 'normal' | 'large';
  compactMode: boolean;
  showAvatars: boolean;
  showOnlineStatus: boolean;
  showReadReceipts: boolean;
  showTypingIndicator: boolean;
  debugMode: boolean;
  showQueryStats: boolean;
};

const defaultSettings: StoredSettings = {
  notifMentions: true,
  notifDMs: true,
  notifThreads: true,
  notifSound: true,
  fontSize: 'normal',
  compactMode: false,
  showAvatars: true,
  showOnlineStatus: true,
  showReadReceipts: true,
  showTypingIndicator: true,
  debugMode: false,
  showQueryStats: false,
};

function loadSettings(): StoredSettings {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) return { ...defaultSettings, ...JSON.parse(raw) };
  } catch { /* ignore */ }
  return { ...defaultSettings };
}

function saveSettings(s: StoredSettings) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(s));
}

const FONT_SIZE_MAP = { small: '13px', normal: '15px', large: '17px' } as const;

/** Apply visual settings to the document so they take effect outside the modal too. */
export function applyVisualSettings() {
  const s = loadSettings();
  document.documentElement.style.setProperty('--app-font-size', FONT_SIZE_MAP[s.fontSize]);
  document.documentElement.classList.toggle('compact-mode', s.compactMode);
  document.documentElement.classList.toggle('hide-avatars', !s.showAvatars);
}

export function getSettings(): StoredSettings {
  return loadSettings();
}

export function SettingsModal({ onClose }: Props) {
  const { state, dispatch } = useApp();
  const [active, setActive] = useState<Section>('profil');

  // Profil state
  const [displayName, setDisplayName] = useState(state.user?.display_name ?? '');
  const [title, setTitle] = useState(state.user?.title ?? '');
  const [profileSaved, setProfileSaved] = useState(false);
  const [profileError, setProfileError] = useState('');

  // Load stored settings
  const [settings, setSettings] = useState<StoredSettings>(loadSettings);

  // Sicherheit
  const [currentPw, setCurrentPw] = useState('');
  const [newPw, setNewPw] = useState('');
  const [confirmPw, setConfirmPw] = useState('');
  const [pwError, setPwError] = useState('');
  const [pwSuccess, setPwSuccess] = useState('');

  // Persist + apply whenever settings change
  function updateSetting<K extends keyof StoredSettings>(key: K, value: StoredSettings[K]) {
    setSettings((prev) => {
      const next = { ...prev, [key]: value };
      saveSettings(next);
      // Apply visual changes immediately
      requestAnimationFrame(() => applyVisualSettings());
      return next;
    });
  }

  // Close on Escape
  useEffect(() => {
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose();
    }
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
  }, [onClose]);

  async function handleProfileSave() {
    if (!state.user) return;
    setProfileError('');
    setProfileSaved(false);
    const trimmedName = displayName.trim();
    if (!trimmedName) {
      setProfileError('Anzeigename darf nicht leer sein.');
      return;
    }
    try {
      const res = await api.users.updateProfile({ display_name: trimmedName, title: title.trim() });
      dispatch({ type: 'SET_USER', user: res.user });
      setProfileSaved(true);
      setTimeout(() => setProfileSaved(false), 2000);
    } catch (err: any) {
      setProfileError(err?.message || 'Profil konnte nicht gespeichert werden.');
    }
  }

  async function handlePasswordChange() {
    setPwError('');
    setPwSuccess('');
    if (!currentPw) {
      setPwError('Aktuelles Passwort eingeben.');
      return;
    }
    if (!newPw || newPw.length < 8) {
      setPwError('Neues Passwort muss mindestens 8 Zeichen lang sein.');
      return;
    }
    if (newPw !== confirmPw) {
      setPwError('Passwörter stimmen nicht überein.');
      return;
    }
    try {
      await api.users.changePassword({ current_password: currentPw, new_password: newPw });
      setPwSuccess('Passwort erfolgreich geändert.');
      setCurrentPw('');
      setNewPw('');
      setConfirmPw('');
    } catch (err: any) {
      setPwError(err?.message || 'Passwort konnte nicht geändert werden.');
    }
  }

  function handleLogout() {
    api.auth.logout().finally(() => {
      dispatch({ type: 'SET_USER', user: null as any });
      window.location.reload();
    });
  }

  const user = state.user!;
  const initials = user.display_name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .slice(0, 2);

  function renderContent() {
    switch (active) {
      case 'profil':
        return (
          <div className="settings-panel">
            <h3>Profil</h3>
            <div className="settings-profile-card">
              <div className="avatar avatar-lg" style={{ background: user.avatar_color }}>
                {initials}
              </div>
              <div>
                <div className="settings-profile-name">{user.display_name}</div>
                <div className="settings-profile-email">{user.email}</div>
              </div>
            </div>
            <label className="settings-label">
              Anzeigename
              <input
                type="text"
                className="settings-input"
                value={displayName}
                onChange={(e) => setDisplayName(e.target.value)}
                maxLength={50}
              />
            </label>
            <label className="settings-label">
              Titel / Position
              <input
                type="text"
                className="settings-input"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                maxLength={100}
              />
            </label>
            <div className="settings-actions">
              <button className="settings-btn-primary" onClick={handleProfileSave}>
                Speichern
              </button>
              {profileSaved && <span className="settings-success">✓ Gespeichert</span>}
              {profileError && <span className="settings-error">{profileError}</span>}
            </div>
            <hr className="settings-divider" />
            <button className="settings-btn-danger" onClick={handleLogout}>
              Abmelden
            </button>
          </div>
        );

      case 'benachrichtigungen':
        return (
          <div className="settings-panel">
            <h3>Benachrichtigungen</h3>
            <p className="settings-description">Lege fest, worüber du benachrichtigt wirst.</p>
            <label className="settings-toggle">
              <input type="checkbox" checked={notifMentions} onChange={(e) => setNotifMentions(e.target.checked)} />
              Erwähnungen (@mentions)
            </label>
            <label className="settings-toggle">
              <input type="checkbox" checked={notifDMs} onChange={(e) => setNotifDMs(e.target.checked)} />
              Direktnachrichten
            </label>
            <label className="settings-toggle">
              <input type="checkbox" checked={notifThreads} onChange={(e) => setNotifThreads(e.target.checked)} />
              Thread-Antworten
            </label>
            <hr className="settings-divider" />
            <label className="settings-toggle">
              <input type="checkbox" checked={notifSound} onChange={(e) => setNotifSound(e.target.checked)} />
              Benachrichtigungston
            </label>
          </div>
        );

      case 'darstellung':
        return (
          <div className="settings-panel">
            <h3>Darstellung</h3>
            <label className="settings-label">
              Schriftgröße
              <select
                className="settings-select"
                value={fontSize}
                onChange={(e) => setFontSize(e.target.value as any)}
              >
                <option value="small">Klein</option>
                <option value="normal">Normal</option>
                <option value="large">Groß</option>
              </select>
            </label>
            <label className="settings-toggle">
              <input type="checkbox" checked={compactMode} onChange={(e) => setCompactMode(e.target.checked)} />
              Kompaktmodus
            </label>
            <label className="settings-toggle">
              <input type="checkbox" checked={showAvatars} onChange={(e) => setShowAvatars(e.target.checked)} />
              Avatare anzeigen
            </label>
          </div>
        );

      case 'datenschutz':
        return (
          <div className="settings-panel">
            <h3>Datenschutz</h3>
            <p className="settings-description">Steuere, welche Informationen andere über dich sehen.</p>
            <label className="settings-toggle">
              <input
                type="checkbox"
                checked={showOnlineStatus}
                onChange={(e) => setShowOnlineStatus(e.target.checked)}
              />
              Online-Status anzeigen
            </label>
            <label className="settings-toggle">
              <input
                type="checkbox"
                checked={showReadReceipts}
                onChange={(e) => setShowReadReceipts(e.target.checked)}
              />
              Lesebestätigungen senden
            </label>
            <label className="settings-toggle">
              <input
                type="checkbox"
                checked={showTypingIndicator}
                onChange={(e) => setShowTypingIndicator(e.target.checked)}
              />
              Tipp-Indikator anzeigen
            </label>
          </div>
        );

      case 'sicherheit':
        return (
          <div className="settings-panel">
            <h3>Sicherheit</h3>
            <p className="settings-description">Ändere dein Passwort.</p>
            <label className="settings-label">
              Aktuelles Passwort
              <input
                type="password"
                className="settings-input"
                value={currentPw}
                onChange={(e) => setCurrentPw(e.target.value)}
                autoComplete="current-password"
              />
            </label>
            <label className="settings-label">
              Neues Passwort
              <input
                type="password"
                className="settings-input"
                value={newPw}
                onChange={(e) => setNewPw(e.target.value)}
                autoComplete="new-password"
              />
            </label>
            <label className="settings-label">
              Passwort bestätigen
              <input
                type="password"
                className="settings-input"
                value={confirmPw}
                onChange={(e) => setConfirmPw(e.target.value)}
                autoComplete="new-password"
              />
            </label>
            {pwError && <p className="settings-error">{pwError}</p>}
            {pwSuccess && <p className="settings-success">{pwSuccess}</p>}
            <div className="settings-actions">
              <button className="settings-btn-primary" onClick={handlePasswordChange}>
                Passwort ändern
              </button>
            </div>
            <hr className="settings-divider" />
            <h4>Aktive Sitzungen</h4>
            <p className="settings-description">Du bist aktuell angemeldet auf diesem Gerät.</p>
          </div>
        );

      case 'entwickler':
        return (
          <div className="settings-panel">
            <h3>Entwickler</h3>
            <p className="settings-description">Optionen für die Entwicklung und Fehlersuche.</p>
            <label className="settings-toggle">
              <input type="checkbox" checked={debugMode} onChange={(e) => setDebugMode(e.target.checked)} />
              Debug-Modus (Console-Logs)
            </label>
            <label className="settings-toggle">
              <input type="checkbox" checked={showQueryStats} onChange={(e) => setShowQueryStats(e.target.checked)} />
              Query-Statistiken anzeigen
            </label>
            <hr className="settings-divider" />
            <h4>API-Info</h4>
            <div className="settings-info-grid">
              <span className="settings-info-label">Backend</span>
              <code>http://localhost/chat-api</code>
              <span className="settings-info-label">Frontend</span>
              <code>http://localhost:5173</code>
              <span className="settings-info-label">User-ID</span>
              <code>{user.id}</code>
              <span className="settings-info-label">Space-ID</span>
              <code>{state.spaceId ?? '–'}</code>
            </div>
          </div>
        );

      case 'workspace':
        return (
          <div className="settings-panel">
            <h3>Workspace</h3>
            <p className="settings-description">Einstellungen für den aktuellen Workspace.</p>
            <div className="settings-info-grid">
              <span className="settings-info-label">Name</span>
              <span>crø</span>
              <span className="settings-info-label">Mitglieder</span>
              <span>27</span>
              <span className="settings-info-label">Kanäle</span>
              <span>{state.channels.length}</span>
            </div>
            <hr className="settings-divider" />
            <h4>Gefahrenzone</h4>
            <p className="settings-description">Diese Aktionen können nicht rückgängig gemacht werden.</p>
            <button className="settings-btn-danger" disabled>
              Workspace verlassen
            </button>
          </div>
        );
    }
  }

  return (
    <div className="settings-backdrop" onClick={onClose}>
      <div className="settings-modal" onClick={(e) => e.stopPropagation()}>
        <nav className="settings-nav">
          <div className="settings-nav-header">Einstellungen</div>
          {SECTIONS.map((s) => (
            <button
              key={s.key}
              className={`settings-nav-item${active === s.key ? ' active' : ''}`}
              onClick={() => setActive(s.key)}
            >
              <span className="settings-nav-icon">{s.icon}</span>
              {s.label}
            </button>
          ))}
        </nav>
        <div className="settings-content">
          <button className="settings-close" onClick={onClose}>
            &times;
          </button>
          {renderContent()}
        </div>
      </div>
    </div>
  );
}
