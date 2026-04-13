/**
 * Tests for MessageItem component.
 *
 * Covers: toolbar visibility on hover/focus, inline edit flow,
 * delete with confirmation, reply dispatch, thread-badge click,
 * pin/save optimistic UI, deleted tombstone, ··· mobile button visibility.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useContext } from 'react';
import { MessageItem } from '../MessageItem';
import {
  fakeMessage, makeAppState, resetMessageMocks,
  msgApiMocks, reactionApiMocks, pinApiMocks, savedApiMocks,
  ME, AppContext,
} from './helpers';

// ── Module mocks ──────────────────────────────────────────────────────────────

vi.mock('../../../api/client', () => ({
  api: {
    messages: { edit: (...a: unknown[]) => msgApiMocks.edit(...a), delete: (...a: unknown[]) => msgApiMocks.delete(...a) },
    reactions: { add: (...a: unknown[]) => reactionApiMocks.add(...a), remove: (...a: unknown[]) => reactionApiMocks.remove(...a) },
    pins: { pin: (...a: unknown[]) => pinApiMocks.pin(...a), unpin: (...a: unknown[]) => pinApiMocks.unpin(...a) },
    savedMessages: { save: (...a: unknown[]) => savedApiMocks.save(...a), unsave: (...a: unknown[]) => savedApiMocks.unsave(...a) },
    attachments: { downloadUrl: (id: number) => `/files/${id}` },
  },
}));

vi.mock('../../../store', () => ({
  useApp: () => useContext(AppContext)!,
}));

// ── Helper ────────────────────────────────────────────────────────────────────

function renderItem(msgOverrides = {}, stateOverrides = {}) {
  const dispatch = vi.fn();
  const msg = fakeMessage(msgOverrides);
  const appState = makeAppState({ messages: [msg], ...stateOverrides });

  const { rerender, ...rest } = render(
    <AppContext.Provider value={{ state: appState as never, dispatch }}>
      <MessageItem message={msg} />
    </AppContext.Provider>,
  );

  return { dispatch, msg, appState, rerender, ...rest };
}

beforeEach(() => {
  resetMessageMocks(fakeMessage());
});

// ── Rendering ─────────────────────────────────────────────────────────────────

describe('MessageItem — basic rendering', () => {
  it('renders the message body', () => {
    renderItem({ body: 'Hello world' });
    expect(screen.getByText('Hello world')).toBeInTheDocument();
  });

  it('renders author display name', () => {
    renderItem();
    expect(screen.getByText('Alice')).toBeInTheDocument();
  });

  it('shows (bearbeitet) label for edited messages', () => {
    renderItem({ edited_at: new Date().toISOString() });
    expect(screen.getByText('(bearbeitet)')).toBeInTheDocument();
  });

  it('shows pin indicator for pinned messages', () => {
    renderItem({ is_pinned: true });
    expect(screen.getByTitle('Angepinnt')).toBeInTheDocument();
  });
});

// ── Deleted tombstone ─────────────────────────────────────────────────────────

describe('MessageItem — deleted tombstone', () => {
  it('renders tombstone text instead of body', () => {
    renderItem({ deleted_at: new Date().toISOString(), body: null });
    expect(screen.getByText(/Diese Nachricht wurde gelöscht/)).toBeInTheDocument();
  });

  it('does not render edit or delete buttons for deleted message', () => {
    renderItem({ deleted_at: new Date().toISOString() });
    expect(screen.queryByLabelText(/bearbeiten/i)).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/löschen/i)).not.toBeInTheDocument();
  });
});

// ── Hover toolbar ─────────────────────────────────────────────────────────────

describe('MessageItem — hover toolbar', () => {
  it('toolbar is not visible before hover', () => {
    renderItem();
    // The toolbar container has role="toolbar"
    expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();
  });

  it('toolbar appears on mouse enter', () => {
    const { container } = renderItem();
    const item = container.querySelector('.message-item')!;
    fireEvent.mouseEnter(item);
    expect(screen.getByRole('toolbar')).toBeInTheDocument();
  });

  it('toolbar disappears on mouse leave', () => {
    const { container } = renderItem();
    const item = container.querySelector('.message-item')!;
    fireEvent.mouseEnter(item);
    fireEvent.mouseLeave(item);
    expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();
  });

  it('toolbar appears on keyboard focus', () => {
    const { container } = renderItem();
    const item = container.querySelector('.message-item')!;
    fireEvent.focus(item);
    expect(screen.getByRole('toolbar')).toBeInTheDocument();
  });
});

// ── Inline edit ───────────────────────────────────────────────────────────────

describe('MessageItem — inline edit', () => {
  async function openEditor() {
    const utils = renderItem({ body: 'Original' });
    const { container } = utils;
    const user = userEvent.setup();
    fireEvent.mouseEnter(container.querySelector('.message-item')!);
    const editBtn = screen.getByLabelText(/bearbeiten/i);
    await user.click(editBtn);
    return { ...utils, user };
  }

  it('shows textarea with current body when edit button is clicked', async () => {
    await openEditor();
    const textarea = screen.getByRole('textbox', { name: /bearbeiten/i });
    expect(textarea).toBeInTheDocument();
    expect((textarea as HTMLTextAreaElement).value).toBe('Original');
  });

  it('hides message body while editing', async () => {
    const { container } = await openEditor();
    expect(container.querySelector('.message-body')).not.toBeInTheDocument();
  });

  it('hides the toolbar while editing', async () => {
    await openEditor();
    expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();
  });

  it('cancels edit on Escape and restores body', async () => {
    const { user } = await openEditor();
    const textarea = screen.getByRole('textbox', { name: /bearbeiten/i });
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('textbox', { name: /bearbeiten/i })).not.toBeInTheDocument();
    expect(screen.getByText('Original')).toBeInTheDocument();
  });

  it('submits edit on Enter and calls API', async () => {
    const { user } = await openEditor();
    const textarea = screen.getByRole('textbox', { name: /bearbeiten/i });
    await user.clear(textarea);
    await user.type(textarea, 'Updated text');
    await user.keyboard('{Enter}');
    await waitFor(() => expect(msgApiMocks.edit).toHaveBeenCalledWith(100, 'Updated text'));
  });

  it('shows error message when edit API fails', async () => {
    msgApiMocks.edit.mockRejectedValueOnce(new Error('fail'));
    const { user } = await openEditor();
    const textarea = screen.getByRole('textbox', { name: /bearbeiten/i });
    await user.clear(textarea);
    await user.type(textarea, 'New text');
    await user.keyboard('{Enter}');
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(/fehlgeschlagen/i));
  });
});

// ── Delete with confirmation ──────────────────────────────────────────────────

describe('MessageItem — delete with confirmation', () => {
  async function openDeleteConfirm() {
    const utils = renderItem();
    const user = userEvent.setup();
    fireEvent.mouseEnter(utils.container.querySelector('.message-item')!);
    // Open "..." dropdown then click Löschen
    fireEvent.click(screen.getByRole('toolbar').querySelector('.toolbar-btn--more')!);
    fireEvent.click(screen.getByRole('menuitem', { name: /löschen/i }));
    return { ...utils, user };
  }

  it('shows confirmation dialog after clicking delete', async () => {
    await openDeleteConfirm();
    expect(screen.getByRole('alertdialog')).toBeInTheDocument();
  });

  it('cancels delete on Abbrechen and hides confirm', async () => {
    const { user } = await openDeleteConfirm();
    await user.click(screen.getByRole('button', { name: /abbrechen/i }));
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
  });

  it('calls api.messages.delete on confirm and dispatches optimistic patch', async () => {
    const { dispatch, user } = await openDeleteConfirm();
    await user.click(screen.getByRole('button', { name: /löschen/i, hidden: false }));
    await waitFor(() => expect(msgApiMocks.delete).toHaveBeenCalledWith(100));
    expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({
      type: 'UPDATE_MESSAGE',
      patch: expect.objectContaining({ deleted_at: expect.any(String) }),
    }));
  });

  it('shows delete error when API fails', async () => {
    msgApiMocks.delete.mockRejectedValueOnce(new Error('err'));
    const { user } = await openDeleteConfirm();
    await user.click(screen.getByRole('button', { name: /löschen/i }));
    await waitFor(() =>
      expect(screen.getByRole('alert')).toHaveTextContent(/fehlgeschlagen/i),
    );
  });
});

// ── Reply ─────────────────────────────────────────────────────────────────────

describe('MessageItem — reply', () => {
  it('dispatches SET_REPLY_TO when reply button clicked', () => {
    const { dispatch } = renderItem();
    fireEvent.mouseEnter(screen.getByRole('group'));
    fireEvent.click(screen.getByRole('toolbar').querySelector('.toolbar-btn--more')!);
    fireEvent.click(screen.getByRole('menuitem', { name: /antworten/i }));
    expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({ type: 'SET_REPLY_TO' }));
  });
});

// ── Thread badge ──────────────────────────────────────────────────────────────

describe('MessageItem — thread badge', () => {
  it('shows thread badge when message has a thread', () => {
    renderItem({ thread: { id: 5, reply_count: 3, last_reply_at: null }, thread_id: null });
    const badge = screen.getByTitle('Thread öffnen');
    expect(badge).toBeInTheDocument();
    expect(badge.textContent).toMatch(/3/);
    expect(badge.textContent).toMatch(/Antworten/);
  });

  it('dispatches OPEN_THREAD on thread badge click', async () => {
    const { dispatch } = renderItem({
      thread: { id: 5, reply_count: 1, last_reply_at: null },
      thread_id: null,
    });
    const user = userEvent.setup();
    await user.click(screen.getByTitle('Thread öffnen'));
    expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({ type: 'OPEN_THREAD' }));
  });

  it('does not show thread badge for thread replies (canOpenThread=false)', () => {
    renderItem({
      thread_id: 5,
      thread: { id: 5, reply_count: 2, last_reply_at: null },
    });
    expect(screen.queryByTitle('Thread öffnen')).not.toBeInTheDocument();
  });
});

// ── Pin / Save ────────────────────────────────────────────────────────────────

describe('MessageItem — pin', () => {
  it('calls pin API when pin button clicked in toolbar', async () => {
    renderItem({ is_pinned: false });
    fireEvent.mouseEnter(screen.getByRole('group'));
    fireEvent.click(screen.getByRole('toolbar').querySelector('.toolbar-btn--more')!);
    fireEvent.click(screen.getByRole('menuitem', { name: /anpinnen/i }));
    await waitFor(() => expect(pinApiMocks.pin).toHaveBeenCalledWith(100));
  });

  it('shows pin error when API fails', async () => {
    pinApiMocks.pin.mockRejectedValueOnce(new Error('err'));
    renderItem({ is_pinned: false });
    fireEvent.mouseEnter(screen.getByRole('group'));
    fireEvent.click(screen.getByRole('toolbar').querySelector('.toolbar-btn--more')!);
    fireEvent.click(screen.getByRole('menuitem', { name: /anpinnen/i }));
    await waitFor(() =>
      expect(screen.getByRole('alert')).toHaveTextContent(/fehlgeschlagen/i),
    );
  });
});

describe('MessageItem — save', () => {
  it('calls save API when save button clicked', async () => {
    renderItem({ is_saved: false });
    fireEvent.mouseEnter(screen.getByRole('group'));
    fireEvent.click(screen.getByRole('toolbar').querySelector('.toolbar-btn--more')!);
    fireEvent.click(screen.getByRole('menuitem', { name: /speichern/i }));
    await waitFor(() => expect(savedApiMocks.save).toHaveBeenCalledWith(100));
  });
});

// ── ··· mobile button ─────────────────────────────────────────────────────────

describe('MessageItem — ··· more button', () => {
  it('renders the more button', () => {
    renderItem();
    expect(screen.getByLabelText('Weitere Aktionen')).toBeInTheDocument();
  });

  it('opens mobile menu when ··· button is clicked', async () => {
    const user = userEvent.setup();
    renderItem();
    await user.click(screen.getByLabelText('Weitere Aktionen'));
    expect(screen.getByRole('dialog', { name: /nachrichtenaktionen/i })).toBeInTheDocument();
  });

  it('··· button is hidden while editing', async () => {
    const user = userEvent.setup();
    const { container } = renderItem({ body: 'Edit me' });
    fireEvent.mouseEnter(container.querySelector('.message-item')!);
    await user.click(screen.getByLabelText(/bearbeiten/i));
    expect(screen.queryByLabelText('Weitere Aktionen')).not.toBeInTheDocument();
  });
});
