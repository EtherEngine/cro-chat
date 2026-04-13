import { useState, useCallback } from 'react';
import type { Message, Reaction } from '../../types';
import { api } from '../../api/client';
import { useApp } from '../../store';
import type { MessagePolicy } from './messagePolicy';

/**
 * Owns all mutation state and async handlers for a single message.
 *
 * Display state (hovered, isEditing, showReactionPicker, showDeleteConfirm)
 * is intentionally NOT managed here — that belongs to the rendering layer.
 */
export function useMessageActions(message: Message, policy: MessagePolicy) {
  const { state, dispatch } = useApp();
  const myId = state.user?.id ?? -1;

  // ── Edit ──────────────────────────────────────────────────────────────────
  const [editBody, setEditBody] = useState(message.body ?? '');
  const [editBusy, setEditBusy] = useState(false);
  const [editError, setEditError] = useState('');

  /** Seed edit body when the editor is first opened. */
  const initEditMode = useCallback((body: string) => {
    setEditBody(body);
    setEditError('');
  }, []);

  const handleEditBodyChange = useCallback((val: string) => {
    setEditBody(val);
    setEditError('');
  }, []);

  /** Reset mutation state; caller is responsible for closing the editor. */
  const resetEditState = useCallback(() => {
    setEditBody(message.body ?? '');
    setEditError('');
  }, [message.body]);

  /**
   * Submit the edit. Returns true on success so the caller can close edit
   * mode in its own display state.
   */
  const handleEditSubmit = useCallback(async (): Promise<boolean> => {
    const trimmed = editBody.trim();
    if (!trimmed || trimmed === message.body || editBusy) return false;
    setEditBusy(true);
    setEditError('');
    try {
      const res = await api.messages.edit(message.id, trimmed);
      dispatch({ type: 'APPEND_MESSAGES', messages: [res.message] });
      return true;
    } catch {
      setEditError('Speichern fehlgeschlagen. Bitte erneut versuchen.');
      return false;
    } finally {
      setEditBusy(false);
    }
  }, [editBody, message.id, message.body, editBusy, dispatch]);

  const saveDisabled = editBusy || !editBody.trim() || editBody.trim() === message.body;

  // ── Delete ────────────────────────────────────────────────────────────────
  const [deleteBusy, setDeleteBusy] = useState(false);
  const [deleteError, setDeleteError] = useState('');

  const handleDelete = useCallback(async () => {
    if (deleteBusy) return;
    setDeleteBusy(true);
    setDeleteError('');
    // Optimistic soft-delete — surgical patch keeps all other fields intact
    const now = new Date().toISOString();
    dispatch({ type: 'UPDATE_MESSAGE', messageId: message.id, patch: { body: null, deleted_at: now } });
    try {
      const res = await api.messages.delete(message.id);
      if (!res.ok) throw new Error('Delete rejected by server');
    } catch {
      // Full snapshot rollback via mergeMessages upsert
      dispatch({ type: 'APPEND_MESSAGES', messages: [message] });
      setDeleteError('Löschen fehlgeschlagen. Bitte erneut versuchen.');
    } finally {
      setDeleteBusy(false);
    }
  }, [message, deleteBusy, dispatch]);

  // ── Pin ───────────────────────────────────────────────────────────────────
  const [pinBusy, setPinBusy] = useState(false);
  const [pinError, setPinError] = useState('');

  const handlePin = useCallback(async () => {
    if (pinBusy) return;
    setPinBusy(true);
    setPinError('');
    const willPin = !message.is_pinned;
    // Optimistic
    dispatch({ type: 'UPDATE_MESSAGE', messageId: message.id, patch: { is_pinned: willPin } });
    try {
      const res = message.is_pinned
        ? await api.pins.unpin(message.id)
        : await api.pins.pin(message.id);
      // Reconcile: trust server's authoritative is_pinned value
      dispatch({ type: 'UPDATE_MESSAGE', messageId: message.id, patch: { is_pinned: res.pinned } });
    } catch {
      // Rollback to pre-mutation state
      dispatch({ type: 'UPDATE_MESSAGE', messageId: message.id, patch: { is_pinned: message.is_pinned } });
      setPinError(willPin ? 'Annadeln fehlgeschlagen.' : 'Nadel entfernen fehlgeschlagen.');
    } finally {
      setPinBusy(false);
    }
  }, [message, pinBusy, dispatch]);

  // ── Save ──────────────────────────────────────────────────────────────────
  const [saveBusy, setSaveBusy] = useState(false);
  const [saveError, setSaveError] = useState('');

  const handleSave = useCallback(async () => {
    if (saveBusy) return;
    setSaveBusy(true);
    setSaveError('');
    const willSave = !message.is_saved;
    // Optimistic
    dispatch({ type: 'UPDATE_MESSAGE', messageId: message.id, patch: { is_saved: willSave } });
    try {
      const res = message.is_saved
        ? await api.savedMessages.unsave(message.id)
        : await api.savedMessages.save(message.id);
      // Reconcile: trust server's authoritative is_saved value
      dispatch({ type: 'UPDATE_MESSAGE', messageId: message.id, patch: { is_saved: res.saved } });
    } catch {
      // Rollback to pre-mutation state
      dispatch({ type: 'UPDATE_MESSAGE', messageId: message.id, patch: { is_saved: message.is_saved } });
      setSaveError(willSave ? 'Speichern fehlgeschlagen.' : 'Entfernen fehlgeschlagen.');
    } finally {
      setSaveBusy(false);
    }
  }, [message, saveBusy, dispatch]);

  // ── Reactions ─────────────────────────────────────────────────────────────
  // Note: closing the reaction picker is display state; the caller handles it.
  const handleReact = useCallback(async (emoji: string) => {
    const prev: Reaction[] = message.reactions ?? [];
    const existing = prev.find((r) => r.emoji === emoji);
    const alreadyReacted = existing?.user_ids.includes(myId) ?? false;

    let optimistic: Reaction[];
    if (alreadyReacted) {
      optimistic = prev
        .map((r) =>
          r.emoji === emoji
            ? { ...r, count: r.count - 1, user_ids: r.user_ids.filter((id) => id !== myId) }
            : r
        )
        .filter((r) => r.count > 0);
    } else if (existing) {
      optimistic = prev.map((r) =>
        r.emoji === emoji
          ? { ...r, count: r.count + 1, user_ids: [...r.user_ids, myId] }
          : r
      );
    } else {
      optimistic = [...prev, { emoji, count: 1, user_ids: [myId] }];
    }

    dispatch({ type: 'UPDATE_MESSAGE_REACTIONS', messageId: message.id, reactions: optimistic });
    try {
      const res = alreadyReacted
        ? await api.reactions.remove(message.id, emoji)
        : await api.reactions.add(message.id, emoji);
      dispatch({ type: 'UPDATE_MESSAGE_REACTIONS', messageId: message.id, reactions: res.reactions });
    } catch {
      dispatch({ type: 'UPDATE_MESSAGE_REACTIONS', messageId: message.id, reactions: prev });
    }
  }, [message.id, message.reactions, myId, dispatch]);

  // ── Navigation ────────────────────────────────────────────────────────────
  const handleReply = useCallback(() => {
    dispatch({ type: 'SET_REPLY_TO', message });
  }, [message, dispatch]);

  const handleOpenThread = useCallback(() => {
    if (!policy.canOpenThread) return;
    dispatch({ type: 'OPEN_THREAD', rootMessage: message });
  }, [message, policy.canOpenThread, dispatch]);

  return {
    myId,
    // edit
    editBody, editBusy, editError, saveDisabled,
    initEditMode, handleEditBodyChange, resetEditState, handleEditSubmit,
    // delete
    deleteBusy, deleteError, handleDelete,
    // pin
    pinBusy, pinError, handlePin,
    // save
    saveBusy, saveError, handleSave,
    // reactions
    handleReact,
    // navigation
    handleReply, handleOpenThread,
  };
}
