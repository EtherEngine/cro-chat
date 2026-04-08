import { useState, useRef, useEffect, useCallback } from 'react';
import { api } from '../../api/client';
import { useApp } from '../../store';
import type { SearchResults, SearchFilters } from '../../types';

type Tab = 'all' | 'channels' | 'users' | 'messages';

export function SearchOverlay() {
  const { state, dispatch } = useApp();
  const [query, setQuery] = useState('');
  const [tab, setTab] = useState<Tab>('all');
  const [results, setResults] = useState<SearchResults>({});
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [filters, setFilters] = useState<SearchFilters>({});
  const [showFilters, setShowFilters] = useState(false);
  const debounceRef = useRef<ReturnType<typeof setTimeout>>();
  const containerRef = useRef<HTMLDivElement>(null);

  // Close on outside click
  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, []);

  // Close on Escape
  useEffect(() => {
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') setOpen(false);
    }
    document.addEventListener('keydown', handleKey);
    return () => document.removeEventListener('keydown', handleKey);
  }, []);

  const doSearch = useCallback(
    (q: string, t: Tab, f: SearchFilters) => {
      if (q.length < 2) {
        setResults({});
        return;
      }
      setLoading(true);
      api.search
        .query(q, { ...f, type: t })
        .then(setResults)
        .catch(() => setResults({}))
        .finally(() => setLoading(false));
    },
    [],
  );

  function handleInput(value: string) {
    setQuery(value);
    setOpen(true);
    clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => doSearch(value, tab, filters), 300);
  }

  function handleTabChange(t: Tab) {
    setTab(t);
    doSearch(query, t, filters);
  }

  function handleFilterChange(next: SearchFilters) {
    setFilters(next);
    doSearch(query, tab, next);
  }

  function selectChannel(channelId: number) {
    dispatch({ type: 'SET_ACTIVE_CHANNEL', channelId });
    setOpen(false);
    setQuery('');
  }

  function selectUser(userId: number) {
    if (!state.spaceId) return;
    api.conversations.createDirect(state.spaceId, userId).then(({ conversation }) => {
      dispatch({ type: 'UPSERT_CONVERSATION', conversation });
      dispatch({ type: 'SET_ACTIVE_CONVERSATION', conversationId: conversation.id });
    });
    setOpen(false);
    setQuery('');
  }

  function jumpToMessage(msg: { id: number; channel_id: number | null; conversation_id: number | null }) {
    if (msg.channel_id) {
      if (state.activeChannelId !== msg.channel_id) {
        dispatch({ type: 'SET_ACTIVE_CHANNEL', channelId: msg.channel_id });
      }
    } else if (msg.conversation_id) {
      if (state.activeConversationId !== msg.conversation_id) {
        dispatch({ type: 'SET_ACTIVE_CONVERSATION', conversationId: msg.conversation_id });
      }
    }
    // Delay jump slightly so messages load first
    setTimeout(() => dispatch({ type: 'JUMP_TO_MESSAGE', messageId: msg.id }), 100);
    setOpen(false);
    setQuery('');
  }

  const hasResults =
    (results.channels?.length ?? 0) + (results.users?.length ?? 0) + (results.messages?.length ?? 0) > 0;

  return (
    <div className="search-overlay-container" ref={containerRef}>
      <div className="search-wrapper">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <circle cx="11" cy="11" r="8" />
          <path d="m21 21-4.35-4.35" />
        </svg>
        <input
          className="search-input"
          placeholder="Suchen…"
          value={query}
          onChange={(e) => handleInput(e.target.value)}
          onFocus={() => query.length >= 2 && setOpen(true)}
        />
        {query && (
          <button className="search-clear" onClick={() => { setQuery(''); setResults({}); setOpen(false); }}>
            ×
          </button>
        )}
      </div>

      {open && query.length >= 2 && (
        <div className="search-dropdown">
          {/* Tabs */}
          <div className="search-tabs">
            {(['all', 'channels', 'users', 'messages'] as Tab[]).map((t) => (
              <button key={t} className={`search-tab ${tab === t ? 'active' : ''}`} onClick={() => handleTabChange(t)}>
                {t === 'all' ? 'Alle' : t === 'channels' ? 'Channels' : t === 'users' ? 'User' : 'Nachrichten'}
              </button>
            ))}
            {(tab === 'all' || tab === 'messages') && (
              <button className={`search-tab filter-toggle ${showFilters ? 'active' : ''}`} onClick={() => setShowFilters(!showFilters)} title="Filter">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
                </svg>
              </button>
            )}
          </div>

          {/* Filters (messages) */}
          {showFilters && (tab === 'all' || tab === 'messages') && (
            <div className="search-filters">
              <select
                value={filters.channel_id ?? ''}
                onChange={(e) => handleFilterChange({ ...filters, channel_id: e.target.value ? Number(e.target.value) : undefined })}
              >
                <option value="">Alle Channels</option>
                {state.channels.map((c) => (
                  <option key={c.id} value={c.id}>#{c.name}</option>
                ))}
              </select>
              <input
                type="date"
                placeholder="Von"
                value={filters.after ?? ''}
                onChange={(e) => handleFilterChange({ ...filters, after: e.target.value || undefined })}
              />
              <input
                type="date"
                placeholder="Bis"
                value={filters.before ?? ''}
                onChange={(e) => handleFilterChange({ ...filters, before: e.target.value || undefined })}
              />
            </div>
          )}

          {/* Results */}
          <div className="search-results">
            {loading && <div className="search-empty">Suche…</div>}

            {!loading && !hasResults && <div className="search-empty">Keine Ergebnisse</div>}

            {/* Channels */}
            {(results.channels?.length ?? 0) > 0 && (
              <div className="search-section">
                <div className="search-section-label">Channels</div>
                {results.channels!.map((ch) => (
                  <button key={ch.id} className="search-hit" onClick={() => selectChannel(ch.id)}>
                    <span className="search-hit-icon" style={{ color: ch.color }}>#</span>
                    <span className="search-hit-name">{ch.name}</span>
                    {ch.is_private ? <span className="search-hit-badge">privat</span> : null}
                  </button>
                ))}
              </div>
            )}

            {/* Users */}
            {(results.users?.length ?? 0) > 0 && (
              <div className="search-section">
                <div className="search-section-label">User</div>
                {results.users!.map((u) => (
                  <button key={u.id} className="search-hit" onClick={() => selectUser(u.id)}>
                    <span className="search-hit-avatar" style={{ background: u.avatar_color }}>
                      {u.display_name.split(' ').map((n) => n[0]).join('').slice(0, 2)}
                    </span>
                    <span className="search-hit-name">{u.display_name}</span>
                    <span className="search-hit-meta">{u.title}</span>
                  </button>
                ))}
              </div>
            )}

            {/* Messages */}
            {(results.messages?.length ?? 0) > 0 && (
              <div className="search-section">
                <div className="search-section-label">Nachrichten</div>
                {results.messages!.map((m) => (
                  <button key={m.id} className="search-hit search-hit-message" onClick={() => jumpToMessage(m)}>
                    <div className="search-hit-msg-header">
                      <span className="search-hit-avatar small" style={{ background: m.author_color }}>
                        {m.author_name.split(' ').map((n) => n[0]).join('').slice(0, 2)}
                      </span>
                      <span className="search-hit-name">{m.author_name}</span>
                      <span className="search-hit-context">{m.context}</span>
                      <span className="search-hit-time">{new Date(m.created_at).toLocaleDateString('de')}</span>
                    </div>
                    <div className="search-hit-body">{m.body}</div>
                  </button>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
