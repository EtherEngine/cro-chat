import { createHmac, timingSafeEqual } from 'node:crypto';
import { db } from './db.js';

const TICKET_MAX_AGE_S = 30;

/**
 * Verify an HMAC-signed WS ticket.
 * Format: userId:timestamp:hmac
 * Returns userId on success, null on failure.
 */
export function verifyTicket(ticket: string, secret: string): number | null {
  const parts = ticket.split(':');
  if (parts.length !== 3) return null;

  const [userIdStr, tsStr, providedHmac] = parts;
  const userId = parseInt(userIdStr, 10);
  const ts = parseInt(tsStr, 10);

  if (isNaN(userId) || isNaN(ts)) return null;

  // Check expiry
  const age = Math.floor(Date.now() / 1000) - ts;
  if (age < 0 || age > TICKET_MAX_AGE_S) return null;

  // Verify HMAC
  const data = `${userId}:${ts}`;
  const expected = createHmac('sha256', secret).update(data).digest('hex');

  // Constant-time comparison
  if (providedHmac.length !== expected.length) return null;
  const a = Buffer.from(providedHmac, 'utf8');
  const b = Buffer.from(expected, 'utf8');
  if (!timingSafeEqual(a, b)) return null;

  return userId;
}

/**
 * Check if a user is a member of a channel.
 */
export async function isChannelMember(channelId: number, userId: number): Promise<boolean> {
  const [rows] = await db().execute<mysql.RowDataPacket[]>(
    'SELECT 1 FROM channel_members WHERE channel_id = ? AND user_id = ? LIMIT 1',
    [channelId, userId],
  );
  return (rows as any[]).length > 0;
}

/**
 * Check if a user is a member of a conversation.
 */
export async function isConversationMember(conversationId: number, userId: number): Promise<boolean> {
  const [rows] = await db().execute<mysql.RowDataPacket[]>(
    'SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1',
    [conversationId, userId],
  );
  return (rows as any[]).length > 0;
}

/**
 * Check if a user is a member of a space (for channel access to public channels).
 */
export async function isSpaceMemberForChannel(channelId: number, userId: number): Promise<boolean> {
  const [rows] = await db().execute<mysql.RowDataPacket[]>(
    `SELECT 1 FROM channels c
     JOIN space_members sm ON sm.space_id = c.space_id AND sm.user_id = ?
     WHERE c.id = ? AND c.is_private = 0
     LIMIT 1`,
    [userId, channelId],
  );
  return (rows as any[]).length > 0;
}

/**
 * Check if a user is a direct member of a space.
 */
export async function isSpaceMember(spaceId: number, userId: number): Promise<boolean> {
  const [rows] = await db().execute<mysql.RowDataPacket[]>(
    'SELECT 1 FROM space_members WHERE space_id = ? AND user_id = ? LIMIT 1',
    [spaceId, userId],
  );
  return (rows as any[]).length > 0;
}

import type mysql from 'mysql2/promise';
