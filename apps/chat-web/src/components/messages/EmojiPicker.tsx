import { useState, useEffect, useLayoutEffect, useRef } from 'react';

const CATEGORIES: { label: string; emojis: string[] }[] = [
  {
    label: '😀 Smileys',
    emojis: [
      '😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩',
      '😘','😗','😚','😙','🥲','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🤐',
      '🤨','😐','😑','😶','😏','😒','🙄','😬','🤥','😔','😪','🤤','😴','😷','🤒','🤕',
      '🤢','🤮','🤧','🥵','🥶','🥴','😵','🤯','🤠','🥳','🥸','😎','🤓','🧐','😕','😟',
      '🙁','☹️','😮','😯','😲','😳','🥺','😦','😧','😨','😰','😥','😢','😭','😱','😖',
      '😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿','💀','☠️','💩','🤡',
    ],
  },
  {
    label: '👋 Gestures',
    emojis: [
      '👋','🤚','🖐️','✋','🖖','👌','🤌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆',
      '🖕','👇','☝️','👍','👎','✊','👊','🤛','🤜','👏','🙌','🫶','👐','🤲','🤝','🙏',
      '✍️','💅','🤳','💪','🦾','🦿','🦵','🦶','👂','🦻','👃','👁️','👀','🫦','🧠',
    ],
  },
  {
    label: '❤️ Hearts & Symbols',
    emojis: [
      '❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖',
      '💘','💝','💟','☮️','✝️','☯️','🕉️','✡️','🔯','☦️','⭐','🌟','💫','✨','⚡','🔥',
      '💥','🌈','☀️','🌤️','⛅','🌦️','🌧️','⛈️','❄️','💧','🌊','🎵','🎶','💯','✅','❌',
    ],
  },
  {
    label: '🐶 Animals',
    emojis: [
      '🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🙈',
      '🙉','🙊','🐔','🐧','🐦','🐤','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🐛',
      '🦋','🐌','🐞','🐜','🦟','🦗','🦂','🐢','🐍','🦎','🦖','🦕','🐙','🦑','🦐','🦞',
    ],
  },
  {
    label: '🍕 Food',
    emojis: [
      '🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍈','🍒','🍑','🥭','🍍','🥥','🥝',
      '🍅','🍆','🥑','🥦','🥬','🥒','🌶️','🫑','🧅','🥔','🍠','🥐','🥖','🍞','🥨','🧀',
      '🥚','🍳','🧆','🥞','🧇','🍔','🍟','🌭','🌮','🌯','🫔','🥙','🥗','🍿','🧂','🥫',
      '🍝','🍜','🍲','🍛','🍣','🍱','🥟','🦪','🍤','🍙','🍚','🍘','🍥','🥮','🍢','🍡',
      '🍧','🍨','🍦','🥧','🧁','🍰','🎂','🍮','🍭','🍬','🍫','🍿','🍩','🍪','🌰','🥜',
      '🍯','🧃','🥤','🧋','☕','🍵','🧉','🍺','🍻','🥂','🍷','🥃','🍸','🍹','🍾',
    ],
  },
  {
    label: '⚽ Activities',
    emojis: [
      '⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🏓','🏸','🏒','🥍','🏏','🥅',
      '⛳','🏹','🎣','🤿','🥊','🥋','🎽','🛹','🛼','🛷','⛸️','🥌','🎿','⛷️','🏂','🪂',
      '🏋️','🤼','🤸','⛹️','🤺','🏇','🧘','🏄','🚣','🧗','🚴','🏆','🥇','🥈','🥉','🎖️',
      '🎗️','🎫','🎟️','🎪','🤹','🎭','🩰','🎨','🎬','🎤','🎧','🎼','🎹','🥁','🎷','🎺',
      '🎸','🪕','🎻','🎲','♟️','🎯','🎳','🎮','🎰','🧩',
    ],
  },
  {
    label: '🚀 Travel',
    emojis: [
      '🚗','🚕','🚙','🚌','🚎','🏎️','🚓','🚑','🚒','🚐','🛻','🚚','🚛','🚜','🛵','🏍️',
      '🚲','🛴','🛺','🚨','🚔','🚍','🚘','🚖','🚡','🚠','🚟','🚃','🚋','🚞','🚝','🚄',
      '🚅','🚈','🚂','🚆','🚇','🚊','🚉','✈️','🛫','🛬','💺','🛶','⛵','🚤','🛥️','🛳️',
      '⛴️','🚀','🛸','🛰️','🪂','🏖️','🏝️','🏜️','🏕️','🏔️','⛰️','🌋','🗾','🏠','🏡','🏘️',
    ],
  },
  {
    label: '💡 Objects',
    emojis: [
      '⌚','📱','💻','⌨️','🖥️','🖨️','🖱️','🖲️','💾','💿','📀','📷','📸','📹','🎥','📽️',
      '🎞️','📞','☎️','📟','📠','📺','📻','🧭','⏱️','⏲️','⏰','🕰️','⌛','⏳','📡','🔋',
      '🔌','💡','🔦','🕯️','🗑️','🔧','🔨','⚒️','🛠️','⛏️','🔩','🔗','💎','🔮','🧿','💈',
      '🪬','🧲','🎁','🎈','🎀','🎊','🎉','🎎','🎐','🎏','🧧','🪅','🎑','🎃','🪔','📦',
      '📫','📪','📬','📭','📮','📯','📜','📃','📄','📑','📊','📈','📉','🗒️','🗓️','📆',
      '📅','📇','🗃️','🗂️','🗄️','📌','📍','📎','🖇️','📏','📐','✂️','🗃️','🔑','🗝️','🔐',
    ],
  },
];

interface EmojiPickerProps {
  onSelect: (emoji: string) => void;
  onClose: () => void;
}

export function EmojiPicker({ onSelect, onClose }: EmojiPickerProps) {
  const [search, setSearch] = useState('');
  const [activeCategory, setActiveCategory] = useState(0);
  const containerRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);

  // Auto-focus search on open
  useEffect(() => {
    searchRef.current?.focus();
  }, []);

  // Escape closes; click outside closes; Tab focus-trap inside dialog
  useEffect(() => {
    const handleDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') { e.stopPropagation(); onClose(); return; }
      if (e.key === 'Tab') {
        const container = containerRef.current;
        if (!container) return;
        const focusable = Array.from(
          container.querySelectorAll<HTMLElement>(
            'button:not([disabled]), input:not([disabled])'
          )
        );
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    };
    const handleClick = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        onClose();
      }
    };
    document.addEventListener('keydown', handleDown, true);
    document.addEventListener('mousedown', handleClick);
    return () => {
      document.removeEventListener('keydown', handleDown, true);
      document.removeEventListener('mousedown', handleClick);
    };
  }, [onClose]);

  // Position picker with position:fixed so it escapes overflow containers
  useLayoutEffect(() => {
    const el = containerRef.current;
    if (!el) return;
    const anchor = el.parentElement;
    if (!anchor) return;

    const anchorRect = anchor.getBoundingClientRect();
    const pickerHeight = el.offsetHeight;
    const pickerWidth = el.offsetWidth;
    const pad = 8;

    el.style.position = 'fixed';
    el.style.bottom = 'auto';
    el.style.left = 'auto';
    el.style.right = 'auto';

    // Default: above anchor, left-aligned with anchor
    let top = anchorRect.top - pickerHeight - pad;
    let left = anchorRect.left;

    // If overflows top → show below anchor
    if (top < pad) {
      top = anchorRect.bottom + pad;
    }

    // Clamp horizontal
    if (left < pad) left = pad;
    if (left + pickerWidth > window.innerWidth - pad) {
      left = window.innerWidth - pickerWidth - pad;
    }

    // Clamp vertical bottom
    if (top + pickerHeight > window.innerHeight - pad) {
      top = window.innerHeight - pickerHeight - pad;
    }

    el.style.top = `${top}px`;
    el.style.left = `${left}px`;
  }, []);

  // Arrow-key navigation inside the emoji grid (7 columns fixed)
  const COLS = 7;
  const handleGridKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
    if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) return;
    e.preventDefault();
    const grid = e.currentTarget;
    const buttons = Array.from(grid.querySelectorAll<HTMLButtonElement>('.emoji-btn'));
    const idx = buttons.indexOf(document.activeElement as HTMLButtonElement);
    if (idx === -1) return;
    let next = idx;
    if (e.key === 'ArrowRight') next = Math.min(idx + 1, buttons.length - 1);
    else if (e.key === 'ArrowLeft') next = Math.max(idx - 1, 0);
    else if (e.key === 'ArrowDown') next = Math.min(idx + COLS, buttons.length - 1);
    else if (e.key === 'ArrowUp') next = Math.max(idx - COLS, 0);
    buttons[next].focus();
  };

  const filteredCategories = search.trim()
    ? [{ label: 'Suchergebnisse', emojis: CATEGORIES.flatMap((c) => c.emojis).filter((e) => e.includes(search)) }]
    : CATEGORIES;

  return (
    <div
      className="emoji-picker"
      ref={containerRef}
      role="dialog"
      aria-modal="true"
      aria-label="Emoji auswählen"
    >
      <div className="emoji-picker-search">
        <input
          ref={searchRef}
          type="text"
          placeholder="Emoji suchen…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="emoji-search-input"
          aria-label="Emoji suchen"
          aria-autocomplete="list"
        />
      </div>
      {!search.trim() && (
        <div
          className="emoji-categories-tabs"
          role="tablist"
          aria-label="Emoji-Kategorien"
        >
          {CATEGORIES.map((cat, i) => (
            <button
              key={i}
              className={`emoji-cat-tab${activeCategory === i ? ' active' : ''}`}
              role="tab"
              aria-selected={activeCategory === i}
              aria-label={cat.label}
              onClick={() => setActiveCategory(i)}
              title={cat.label}
              type="button"
              tabIndex={activeCategory === i ? 0 : -1}
            >
              <span aria-hidden="true">{cat.emojis[0]}</span>
            </button>
          ))}
        </div>
      )}
      <div className="emoji-grid-container">
        {filteredCategories.map((cat, ci) => {
          const display = search.trim() ? cat : filteredCategories[activeCategory];
          if (!search.trim() && ci !== activeCategory) return null;
          return (
            <div key={ci} className="emoji-category" role="tabpanel" aria-label={display.label}>
              <div className="emoji-cat-label" aria-hidden="true">{display.label}</div>
              <div className="emoji-grid" onKeyDown={handleGridKeyDown}>
                {display.emojis.map((emoji, ei) => (
                  <button
                    key={ei}
                    type="button"
                    className="emoji-btn"
                    aria-label={emoji}
                    onClick={() => { onSelect(emoji); }}
                    tabIndex={ei === 0 ? 0 : -1}
                  >
                    {emoji}
                  </button>
                ))}
              </div>
            </div>
          );
        })}
        {search.trim() && filteredCategories[0].emojis.length === 0 && (
          <div className="emoji-no-results" role="status">Keine Emojis gefunden</div>
        )}
      </div>
    </div>
  );
}
