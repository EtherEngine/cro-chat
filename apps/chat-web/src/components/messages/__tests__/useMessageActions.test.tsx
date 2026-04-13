/**
 * Tests for useMessageActions hook.
 *
 * Covers API calls, optimistic dispatch, rollback on error and reply/thread
 * navigation dispatch for: reactions, edit, delete, pin, save.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { createContext, useContext, type ReactNode } from 'react';
import { useMessageActions } from '../useMessageActions';
import {
  fakeMessage, fakeReaction, makeAppState, resetMessageMocks,
  msgApiMocks, reactionApiMocks, pinApiMocks, savedApiMocks,
  ME, AppContext,
} from './helpers';
import { getMessagePolicy } from '../messagePolicy';

// ── Module mocks ──────────────────────────────────────────────────────────────

vi.mock('../../../api/client', () => ({
  api: {
    messages: { edit: (...a: unknown[]) => msgApiMocks.edit(...a), delete: (...a: unknown[]) => msgApiMocks.delete(...a) },
    reactions: { add: (...a: unknown[]) => reactionApiMocks.add(...a), remove: (...a: unknown[]) => reactionApiMocks.remove(...a) },
    pins: { pin: (...a: unknown[]) => pinApiMocks.pin(...a), unpin: (...a: unknown[]) => pinApiMocks.unpin(...a) },
    savedMessages: { save: (...a: unknown[]) => savedApiMocks.save(...a), unsave: (...a: unknown[]) => savedApiMocks.unsave(...a) },
  },
}));

vi.mock('../../../store', () => ({
  useApp: () => useContext(AppContext)!,
}));

// ── Render helper ─────────────────────────────────────────────────────────────

function renderActions(msgOverrides = {}, stateOverrides = {}) {
  const dispatch = vi.fn();
  const msg = fakeMessage(msgOverrides);
  const appState = makeAppState(stateOverrides);
  const policy = getMessagePolicy(msg, { userId: ME.id, spaceRole: 'member' });

  const wrapper = ({ children }: { children: ReactNode }) => (
    <AppContext.Provider value={{ state: appState as never, dispatch }}>
      {children}
    </AppContext.Provider>
  );

  const result = renderHook(() => useMessageActions(msg, policy), { wrapper });
  return { ...result, dispatch, msg, policy };
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  resetMessageMocks(fakeMessage());
});

// ── Edit ──────────────────────────────────────────────────────────────────────

describe('useMessageActions — edit', () => {
  it('returns false and skips API call when body is unchanged', async () => {
    const { result } = renderActions({ body: 'Hello world' });
    await act(async () => { result.current.initEditMode('Hello world'); });
    let ok: boolean;
    await act(async () => { ok = await result.current.handleEditSubmit(); });
    expect(ok!).toBe(false);
    expect(msgApiMocks.edit).not.toHaveBeenCalled();
  });

  it('returns false and skips API call when body is empty', async () => {
    const { result } = renderActions();
    await act(async () => { result.current.initEditMode(''); });
    let ok: boolean;
    await act(async () => { ok = await result.current.handleEditSubmit(); });
    expect(ok!).toBe(false);
    expect(msgApiMocks.edit).not.toHaveBeenCalled();
  });

  it('calls api.messages.edit and dispatches APPEND_MESSAGES on success', async () => {
    const { result, dispatch } = renderActions({ body: 'Original' });
    await act(async () => { result.current.initEditMode('Updated body'); });
    let ok: boolean;
    await act(async () => { ok = await result.current.handleEditSubmit(); });
    expect(ok!).toBe(true);
    expect(msgApiMocks.edit).toHaveBeenCalledWith(100, 'Updated body');
    expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({ type: 'APPEND_MESSAGES' }));
  });

  it('sets editError and returns false on API failure', async () => {
    msgApiMocks.edit.mockRejectedValueOnce(new Error('network'));
    const { result } = renderActions({ body: 'Original' });
    await act(async () => { result.current.initEditMode('Changed'); });
    let ok: boolean;
    await act(async () => { ok = await result.current.handleEditSubmit(); });
    expect(ok!).toBe(false);
    expect(result.current.editError).toMatch(/fehlgeschlagen/i);
  });

  it('clears editError on initEditMode', async () => {
    msgApiMocks.edit.mockRejectedValueOnce(new Error('err'));
    const { result } = renderActions({ body: 'Original' });
    await act(async () => { result.current.initEditMode('Changed'); });
    await act(async () => { await result.current.handleEditSubmit(); });
    await act(async () => { result.current.initEditMode('Try again'); });
    expect(result.current.editError).toBe('');
  });
});

// ── Delete ────────────────────────────────────────────────────────────────────

describe('useMessageActions — delete', () => {
  it('dispatches optimistic UPDATE_MESSAGE then confirms with API', async () => {
    const { result, dispatch } = renderActions();
    await act(async () => { await result.current.handleDelete(); });
    // First call: optimistic soft-delete patch
    expect(dispatch).toHaveBeenNthCalledWith(1, expect.objectContaining({
      type: 'UPDATE_MESSAGE',
      patch: expect.objectContaining({ deleted_at: expect.any(String) }),
    }));
    expect(msgApiMocks.delete).toHaveBeenCalledWith(100);
  });

  it('rolls back via APPEND_MESSAGES when API returns not ok', async () => {
    msgApiMocks.delete.mockResolvedValueOnce({ ok: false });
    const { result, dispatch, msg } = renderActions();
    await act(async () => { await result.current.handleDelete(); });
    expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({
      type: 'APPEND_MESSAGES',
      messages: [msg],
    }));
    expect(result.current.deleteError).toMatch(/fehlgeschlagen/i);
  });

  it('rolls back via APPEND_MESSAGES when API rejects', async () => {
    msgApiMocks.delete.mockRejectedValueOnce(new Error('500'));
    const { result, dispatch, msg } = renderActions();
    await act(async () => { await result.current.handleDelete(); });
    expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({
      type: 'APPEND_MESSAGES',
      messages: [msg],
    }));
  });
});

// ── Reactions ─────────────────────────────────────────────────────────────────

describe('useMessageActions — reactions', () => {
  it('adds a new reaction optimistically then reconciles', async () => {
    const { result, dispatch } = renderActions({ reactions: [] });
    await act(async () => { await result.current.handleReact('👍'); });
    // optimistic dispatch
    expect(dispatch).toHaveBeenNthCalledWith(1, expect.objectContaining({
      type: 'UPDATE_MESSAGE_REACTIONS',
      reactions: [fakeReaction('👍', [ME.id])],
    }));
    // server reconcile
    expect(dispatch).toHaveBeenNthCalledWith(2, expect.objectContaining({
      type: 'UPDATE_MESSAGE_REACTIONS',
    }));
    expect(reactionApiMocks.add).toHaveBeenCalledWith(100, '👍');
  });

  it('toggles off an existing reaction the user already added', async () => {
    const initialReactions = [fakeReaction('👍', [ME.id])];
    reactionApiMocks.remove.mockResolvedValueOnce({ reactions: [] });
    const { result, dispatch } = renderActions({ reactions: initialReactions });
    await act(async () => { await result.current.handleReact('👍'); });
    // optimistic should remove
    expect(dispatch).toHaveBeenNthCalledWith(1, expect.objectContaining({
      type: 'UPDATE_MESSAGE_REACTIONS',
      reactions: [],
    }));
    expect(reactionApiMocks.remove).toHaveBeenCalledWith(100, '👍');
  });

  it('rolls back reactions on API failure', async () => {
    const prev = [fakeReaction('❤️', [2])];
    reactionApiMocks.add.mockRejectedValueOnce(new Error('err'));
    const { result, dispatch } = renderActions({ reactions: prev });
    await act(async () => { await result.current.handleReact('👍'); });
    const lastCall = dispatch.mock.calls[dispatch.mock.calls.length - 1][0];
    expect(lastCall).toMatchObject({ type: 'UPDATE_MESSAGE_REACTIONS', reactions: prev });
  });
});

// ── Pin ───────────────────────────────────────────────────────────────────────

describe('useMessageActions — pin', () => {
  it('optimistically pins and then reconciles from server', async () => {
    const { result, dispatch } = renderActions({ is_pinned: false });
    await act(async () => { await result.current.handlePin(); });
    expect(dispatch).toHaveBeenNthCalledWith(1, expect.objectContaining({
      type: 'UPDATE_MESSAGE', patch: { is_pinned: true },
    }));
    expect(pinApiMocks.pin).toHaveBeenCalledWith(100);
    // reconcile from server
    expect(dispatch).toHaveBeenNthCalledWith(2, expect.objectContaining({
      type: 'UPDATE_MESSAGE', patch: { is_pinned: true },
    }));
  });

  it('rolls back pin on API failure', async () => {
    pinApiMocks.pin.mockRejectedValueOnce(new Error('err'));
    const { result, dispatch } = renderActions({ is_pinned: false });
    await act(async () => { await result.current.handlePin(); });
    const lastCall = dispatch.mock.calls[dispatch.mock.calls.length - 1][0];
    expect(lastCall).toMatchObject({ type: 'UPDATE_MESSAGE', patch: { is_pinned: false } });
    expect(result.current.pinError).toMatch(/fehlgeschlagen/i);
  });

  it('calls unpin when message is already pinned', async () => {
    const { result } = renderActions({ is_pinned: true });
    await act(async () => { await result.current.handlePin(); });
    expect(pinApiMocks.unpin).toHaveBeenCalledWith(100);
  });
});

// ── Save ──────────────────────────────────────────────────────────────────────

describe('useMessageActions — save', () => {
  it('optimistically saves and reconciles', async () => {
    const { result, dispatch } = renderActions({ is_saved: false });
    await act(async () => { await result.current.handleSave(); });
    expect(dispatch).toHaveBeenNthCalledWith(1, expect.objectContaining({
      type: 'UPDATE_MESSAGE', patch: { is_saved: true },
    }));
    expect(savedApiMocks.save).toHaveBeenCalledWith(100);
  });

  it('rolls back save on failure', async () => {
    savedApiMocks.save.mockRejectedValueOnce(new Error('err'));
    const { result, dispatch } = renderActions({ is_saved: false });
    await act(async () => { await result.current.handleSave(); });
    const lastCall = dispatch.mock.calls[dispatch.mock.calls.length - 1][0];
    expect(lastCall).toMatchObject({ type: 'UPDATE_MESSAGE', patch: { is_saved: false } });
    expect(result.current.saveError).toMatch(/fehlgeschlagen/i);
  });

  it('calls unsave when message is already saved', async () => {
    const { result } = renderActions({ is_saved: true });
    await act(async () => { await result.current.handleSave(); });
    expect(savedApiMocks.unsave).toHaveBeenCalledWith(100);
  });
});

// ── Navigation ────────────────────────────────────────────────────────────────

describe('useMessageActions — navigation', () => {
  it('dispatches SET_REPLY_TO with the message', async () => {
    const { result, dispatch, msg } = renderActions();
    act(() => { result.current.handleReply(); });
    expect(dispatch).toHaveBeenCalledWith({ type: 'SET_REPLY_TO', message: msg });
  });

  it('dispatches OPEN_THREAD for a root message', async () => {
    const { result, dispatch, msg } = renderActions({ thread_id: null });
    act(() => { result.current.handleOpenThread(); });
    expect(dispatch).toHaveBeenCalledWith({ type: 'OPEN_THREAD', rootMessage: msg });
  });

  it('does not dispatch OPEN_THREAD for a thread reply', async () => {
    const { result, dispatch } = renderActions({ thread_id: 5 });
    // policy.canOpenThread is false for thread replies
    act(() => { result.current.handleOpenThread(); });
    expect(dispatch).not.toHaveBeenCalledWith(expect.objectContaining({ type: 'OPEN_THREAD' }));
  });
});
