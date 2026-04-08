import { useState } from 'react';
import { useApp } from '../../store';
import { MemberList } from '../members/MemberList';

export function SidebarRight() {
  const { dispatch } = useApp();
  const [search, setSearch] = useState('');

  return (
    <aside className="sidebar-right">
      <div className="members-header">
        <h3>Members</h3>
        <button
          className="icon-btn"
          onClick={() => dispatch({ type: 'CLOSE_MEMBERS' })}
          title="Close"
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
          </svg>
        </button>
      </div>
      <div className="members-search">
        <div className="search-wrapper">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.35-4.35" />
          </svg>
          <input
            className="search-input"
            placeholder="Search in members"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
      </div>
      <MemberList search={search} />
    </aside>
  );
}

