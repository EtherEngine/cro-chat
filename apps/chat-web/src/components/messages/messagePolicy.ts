import type { Message } from '../../types';

export type MessagePolicy = {
  /** User may add or toggle emoji reactions. */
  canReact: boolean;
  /** User may use the reply-to composer flow. */
  canReply: boolean;
  /** User may open or create a thread on this message.
   *  False for thread replies themselves (no nested threads). */
  canOpenThread: boolean;
  /** User may edit the message body in-place. */
  canEdit: boolean;
  /** User may pin the message for the whole channel/conversation. */
  canPin: boolean;
  /** User may save the message to their personal bookmarks. */
  canSave: boolean;
  /** User may delete the message. */
  canDelete: boolean;
};

export type PolicyContext = {
  /** ID of the currently authenticated user, or null when loading. */
  userId: number | null;
  /** Space-level role of the current user ('admin' | 'owner' | 'member' | …). */
  spaceRole: string | null;
};

/**
 * Pure function — no React hooks, safe to call anywhere.
 *
 * Derives the permitted interactions for a single message based on:
 * - message ownership
 * - message type and state (text, deleted, thread reply)
 * - the caller's space-level role
 *
 * Backend access checks (channel membership, rate limits, etc.) are
 * authoritative; this layer is purely for UI affordance.
 */
export function getMessagePolicy(message: Message, ctx: PolicyContext): MessagePolicy {
  const isOwn = ctx.userId !== null && message.user_id === ctx.userId;
  const isPrivileged = ctx.spaceRole === 'admin' || ctx.spaceRole === 'owner' || ctx.spaceRole === 'moderator';

  // A deleted message is displayed as a tombstone — no interactions allowed.
  if (message.deleted_at) {
    return {
      canReact: false,
      canReply: false,
      canOpenThread: false,
      canEdit: false,
      canPin: false,
      canSave: false,
      canDelete: false,
    };
  }

  return {
    // Any member who can see the message may react.
    canReact: true,
    // Any member may reply via the composer reply-to flow.
    canReply: true,
    // Thread replies cannot spawn nested threads; root messages always can.
    canOpenThread: !message.thread_id,
    // Only the author can edit, and only plain-text messages.
    canEdit: isOwn && message.type === 'text',
    // Any member may pin (backend enforces channel/conversation membership).
    canPin: true,
    // Any member may bookmark a message for themselves.
    canSave: true,
    // Author or privileged role (admin / owner) may delete.
    canDelete: isOwn || isPrivileged,
  };
}
