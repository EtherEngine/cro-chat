/**
 * Tests for MobileMessageMenu (bottom sheet).
 *
 * Covers: rendering, quick-react strip, full emoji picker toggle,
 * all action buttons (reply/thread/edit/pin/save/delete),
 * inline delete confirmation, Escape to close, overlay click to close,
 * policy gating, busy states.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MobileMessageMenu } from '../MobileMessageMenu';
import { fakeMessage, fakeReaction, ME } from './helpers';
import type { MessagePolicy } from '../messagePolicy';

// EmojiPicker is a heavy component — stub it to keep tests fast
vi.mock('../EmojiPicker', () => ({
  EmojiPicker: ({ onSelect }: { onSelect: (e: string) => void }) => (
    <button data-testid="emoji-picker-stub" onClick={() => onSelect('🎉')}>
      EmojiPicker
    </button>
  ),
}));

// ── Default props ─────────────────────────────────────────────────────────────

const allAllowed: MessagePolicy = {
  canReact: true,
  canReply: true,
  canOpenThread: true,
  canEdit: true,
  canPin: true,
  canSave: true,
  canDelete: true,
};

const noneAllowed: MessagePolicy = {
  canReact: false,
  canReply: false,
  canOpenThread: false,
  canEdit: false,
  canPin: false,
  canSave: false,
  canDelete: false,
};

function makeProps(overrides: Partial<Parameters<typeof MobileMessageMenu>[0]> = {}) {
  return {
    policy: allAllowed,
    message: fakeMessage(),
    myId: ME.id,
    threadPanelActiveMessageId: null,
    pinBusy: false,
    saveBusy: false,
    deleteBusy: false,
    onClose: vi.fn(),
    onReact: vi.fn(),
    onReply: vi.fn(),
    onOpenThread: vi.fn(),
    onStartEdit: vi.fn(),
    onPin: vi.fn(),
    onSave: vi.fn(),
    onDelete: vi.fn(),
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  document.body.style.overflow = '';
});

// ── Rendering ─────────────────────────────────────────────────────────────────

describe('MobileMessageMenu — rendering', () => {
  it('renders the dialog', () => {
    render(<MobileMessageMenu {...makeProps()} />);
    expect(screen.getByRole('dialog', { name: /nachrichtenaktionen/i })).toBeInTheDocument();
  });

  it('renders quick-react strip when canReact', () => {
    render(<MobileMessageMenu {...makeProps()} />);
    expect(screen.getByRole('group', { name: /schnellreaktionen/i })).toBeInTheDocument();
    // 6 quick emojis + 1 more button
    const btns = screen.getAllByRole('button', { name: /👍|❤️|😂|😮|😢|🙏|\+/ });
    expect(btns.length).toBeGreaterThanOrEqual(6);
  });

  it('does not render quick-react strip when canReact is false', () => {
    render(<MobileMessageMenu {...makeProps({ policy: noneAllowed })} />);
    expect(screen.queryByRole('group', { name: /schnellreaktionen/i })).not.toBeInTheDocument();
  });

  it('locks body scroll on mount and unlocks on unmount', () => {
    const { unmount } = render(<MobileMessageMenu {...makeProps()} />);
    expect(document.body.style.overflow).toBe('hidden');
    unmount();
    expect(document.body.style.overflow).toBe('');
  });
});

// ── Quick-react ───────────────────────────────────────────────────────────────

describe('MobileMessageMenu — quick-react', () => {
  it('calls onReact and onClose when a quick emoji is clicked', async () => {
    const onReact = vi.fn();
    const onClose = vi.fn();
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps({ onReact, onClose })} />);
    await user.click(screen.getByLabelText(/👍/));
    expect(onReact).toHaveBeenCalledWith('👍');
    expect(onClose).toHaveBeenCalled();
  });

  it('marks quick emoji as active when user already reacted', () => {
    const msg = fakeMessage({ reactions: [fakeReaction('👍', [ME.id])] });
    render(<MobileMessageMenu {...makeProps({ message: msg })} />);
    const btn = screen.getByLabelText(/👍/);
    expect(btn.className).toMatch(/active/);
  });

  it('opens full EmojiPicker when + button is clicked', async () => {
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps()} />);
    await user.click(screen.getByLabelText(/weiteres emoji/i));
    expect(screen.getByTestId('emoji-picker-stub')).toBeInTheDocument();
  });

  it('calls onReact and onClose when a picker emoji is selected', async () => {
    const onReact = vi.fn();
    const onClose = vi.fn();
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps({ onReact, onClose })} />);
    await user.click(screen.getByLabelText(/weiteres emoji/i));
    await user.click(screen.getByTestId('emoji-picker-stub'));
    expect(onReact).toHaveBeenCalledWith('🎉');
    expect(onClose).toHaveBeenCalled();
  });
});

// ── Action items ──────────────────────────────────────────────────────────────

describe('MobileMessageMenu — action buttons', () => {
  it('renders reply action and calls onReply + onClose', async () => {
    const onReply = vi.fn();
    const onClose = vi.fn();
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps({ onReply, onClose })} />);
    await user.click(screen.getByRole('button', { name: /antworten/i }));
    expect(onReply).toHaveBeenCalled();
    expect(onClose).toHaveBeenCalled();
  });

  it('renders thread action and calls onOpenThread + onClose', async () => {
    const onOpenThread = vi.fn();
    const onClose = vi.fn();
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps({ onOpenThread, onClose })} />);
    await user.click(screen.getByRole('button', { name: /thread/i }));
    expect(onOpenThread).toHaveBeenCalled();
    expect(onClose).toHaveBeenCalled();
  });

  it('renders edit action and calls onStartEdit + onClose', async () => {
    const onStartEdit = vi.fn();
    const onClose = vi.fn();
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps({ onStartEdit, onClose })} />);
    await user.click(screen.getByRole('button', { name: /bearbeiten/i }));
    expect(onStartEdit).toHaveBeenCalled();
    expect(onClose).toHaveBeenCalled();
  });

  it('renders pin action and calls onPin + onClose', async () => {
    const onPin = vi.fn();
    const onClose = vi.fn();
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps({ onPin, onClose })} />);
    await user.click(screen.getByRole('button', { name: /^anpinnen$/i }));
    expect(onPin).toHaveBeenCalled();
    expect(onClose).toHaveBeenCalled();
  });

  it('disables pin button while pinBusy', () => {
    render(<MobileMessageMenu {...makeProps({ pinBusy: true })} />);
    expect(screen.getByRole('button', { name: /^anpinnen$/i })).toBeDisabled();
  });

  it('renders save action and calls onSave + onClose', async () => {
    const onSave = vi.fn();
    const onClose = vi.fn();
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps({ onSave, onClose })} />);
    await user.click(screen.getByRole('button', { name: /speichern/i }));
    expect(onSave).toHaveBeenCalled();
    expect(onClose).toHaveBeenCalled();
  });

  it('renders "Thread schließen" label when thread panel is active for this message', () => {
    const msg = fakeMessage({ id: 100 });
    render(<MobileMessageMenu {...makeProps({ message: msg, threadPanelActiveMessageId: 100 })} />);
    expect(screen.getByRole('button', { name: /thread schließen/i })).toBeInTheDocument();
  });

  it('hides actions gated by policy when permissions are false', () => {
    render(<MobileMessageMenu {...makeProps({ policy: noneAllowed })} />);
    expect(screen.queryByRole('button', { name: /bearbeiten/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /löschen/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /antworten/i })).not.toBeInTheDocument();
  });
});

// ── Delete confirmation ───────────────────────────────────────────────────────

describe('MobileMessageMenu — delete confirmation', () => {
  it('shows inline confirm when delete is clicked', async () => {
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps()} />);
    await user.click(screen.getByRole('button', { name: /löschen/i }));
    expect(screen.getByText(/wirklich löschen/i)).toBeInTheDocument();
  });

  it('calls onDelete when confirm yes is clicked', async () => {
    const onDelete = vi.fn();
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps({ onDelete })} />);
    // First click opens confirm (only button with exactly 'Löschen' + danger class)
    await user.click(screen.getByRole('button', { name: /^löschen$/i }));
    // Now the confirm row is shown; pick the .mobile-confirm-btn--yes button
    const confirmBtns = screen.getAllByRole('button', { name: /^löschen$/i });
    await user.click(confirmBtns[confirmBtns.length - 1]);
    expect(onDelete).toHaveBeenCalled();
  });

  it('hides confirm when Abbrechen is clicked', async () => {
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps()} />);
    await user.click(screen.getByRole('button', { name: /^löschen$/i }));
    // Two "Abbrechen" buttons exist: the confirm row one and the bottom cancel.
    // Click the first (the one inside the delete confirm row).
    const abbrechenBtns = screen.getAllByRole('button', { name: /^abbrechen$/i });
    await user.click(abbrechenBtns[0]);
    expect(screen.queryByText(/wirklich löschen/i)).not.toBeInTheDocument();
  });

  it('disables confirm button while deleteBusy', async () => {
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps({ deleteBusy: true })} />);
    await user.click(screen.getByRole('button', { name: /^löschen$/i }));
    // When busy the confirm button text is 'Wird gelöscht…'
    const confirmBtns = screen.getAllByRole('button', { name: /löschen|gelöscht/i });
    const confirmBtn = confirmBtns.find(
      (b) => b.classList.contains('mobile-confirm-btn--yes')
    );
    expect(confirmBtn).toBeDisabled();
  });
});

// ── Close behaviours ──────────────────────────────────────────────────────────

describe('MobileMessageMenu — close', () => {
  it('calls onClose when overlay backdrop is clicked', async () => {
    const onClose = vi.fn();
    const user = userEvent.setup();
    const { container } = render(<MobileMessageMenu {...makeProps({ onClose })} />);
    const overlay = container.querySelector('.mobile-menu-overlay')!;
    await user.click(overlay);
    expect(onClose).toHaveBeenCalled();
  });

  it('calls onClose when Cancel button is clicked', async () => {
    const onClose = vi.fn();
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps({ onClose })} />);
    await user.click(screen.getByRole('button', { name: /abbrechen/i }));
    // The standalone cancel button (not inside delete confirm)
    expect(onClose).toHaveBeenCalled();
  });

  it('calls onClose on Escape key', async () => {
    const onClose = vi.fn();
    const user = userEvent.setup();
    render(<MobileMessageMenu {...makeProps({ onClose })} />);
    await user.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalled();
  });

  it('does not call onClose when sheet itself is clicked', async () => {
    const onClose = vi.fn();
    const user = userEvent.setup();
    const { container } = render(<MobileMessageMenu {...makeProps({ onClose })} />);
    const sheet = container.querySelector('.mobile-menu-sheet')!;
    await user.click(sheet);
    expect(onClose).not.toHaveBeenCalled();
  });
});
