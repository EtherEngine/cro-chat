import { useState } from 'react';
import { useApp } from '../../store';
import { ChannelList } from '../channels/ChannelList';
import { SearchOverlay } from '../search/SearchOverlay';
import { SettingsModal } from '../settings/SettingsModal';
import { AdminPanel } from '../admin/AdminPanel';
import { NotificationPanel } from '../notifications/NotificationPanel';

export function SidebarLeft() {
  const { state } = useApp();
  const [showSettings, setShowSettings] = useState(false);
  const [showAdmin, setShowAdmin] = useState(false);
  const [showNotifications, setShowNotifications] = useState(false);
  const user = state.user!;
  const isAdminOrOwner = state.spaceRole === 'owner' || state.spaceRole === 'admin';
  const initials = user.display_name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .slice(0, 2);

  return (
    <aside className="sidebar-left">
      <div className="user-profile">
        <div className="avatar" style={{ background: user.avatar_color }}>
          {initials}
        </div>
        <div className="user-info">
          <div className="user-name">{user.display_name}</div>
          <div className="user-title">{user.title}</div>
        </div>
        <div className="profile-actions">
          {isAdminOrOwner && (
            <button className="icon-btn admin-icon-btn" title="Admin Panel" onClick={() => setShowAdmin(true)}>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
              </svg>
            </button>
          )}
          <button className="icon-btn" title="Settings" onClick={() => setShowSettings(true)}>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="12" r="3" />
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
            </svg>
          </button>
          <div className="notification-bell-wrapper">
            <button className="icon-btn" title="Notifications" onClick={() => setShowNotifications((v) => !v)}>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                <path d="M13.73 21a2 2 0 0 1-3.46 0" />
              </svg>
              {state.notificationUnread > 0 && (
                <span className="notification-badge">
                  {state.notificationUnread > 99 ? '99+' : state.notificationUnread}
                </span>
              )}
            </button>
            {showNotifications && <NotificationPanel onClose={() => setShowNotifications(false)} />}
          </div>
        </div>
      </div>

      <div className="search-box">
        <SearchOverlay />
      </div>

      <div className="sidebar-scroll">
        <ChannelList />
      </div>

      {showSettings && <SettingsModal onClose={() => setShowSettings(false)} />}
      {showAdmin && <AdminPanel onClose={() => setShowAdmin(false)} />}
    </aside>
  );
}

