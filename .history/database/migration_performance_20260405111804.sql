-- ═══════════════════════════════════════════════════════════════════════════════
-- Performance Migration — crø Chat
-- Run after schema.sql. All statements are idempotent (IF NOT EXISTS).
-- Usage: mysql -u root cro_chat < migration_performance.sql
--    or: mysql -u root cro_chat_test < migration_performance.sql
-- ═══════════════════════════════════════════════════════════════════════════════

-- ── 1. space_members: reverse index for "spaces for user" lookups ──────────
--    forUser(), coMembers(), coMemberStatuses() all join on user_id first.
--    PK is (space_id, user_id) — reverse direction needed.
ALTER TABLE space_members
  ADD INDEX IF NOT EXISTS idx_user_space (user_id, space_id);

-- ── 2. conversation_members: reverse index for "conversations for user" ────
--    ConversationRepository::forUser() joins on cm.user_id.
--    PK is (conversation_id, user_id) — reverse needed.
ALTER TABLE conversation_members
  ADD INDEX IF NOT EXISTS idx_user_conversation (user_id, conversation_id);

-- ── 3. messages: covering index for channel pagination ─────────────────────
--    MessageRepository cursor query: WHERE channel_id=? AND thread_id IS NULL
--    AND deleted_at IS NULL AND m.id < ? ORDER BY m.id DESC
--    Current idx_channel_id is (channel_id, id) — missing deleted_at filter.
ALTER TABLE messages
  ADD INDEX IF NOT EXISTS idx_chan_main_cursor (channel_id, deleted_at, id);

-- ── 4. messages: covering index for conversation pagination ────────────────
ALTER TABLE messages
  ADD INDEX IF NOT EXISTS idx_conv_main_cursor (conversation_id, deleted_at, id);

-- ── 5. messages: unread count optimization ─────────────────────────────────
--    ReadReceiptRepository::unreadCounts() needs: channel_id + deleted_at +
--    thread_id IS NULL + id > last_read + user_id != current.
--    This composite helps the JOIN significantly.
ALTER TABLE messages
  ADD INDEX IF NOT EXISTS idx_chan_unread (channel_id, thread_id, deleted_at, user_id, id);

ALTER TABLE messages
  ADD INDEX IF NOT EXISTS idx_conv_unread (conversation_id, thread_id, deleted_at, user_id, id);

-- ── 6. notifications: cursor pagination index ──────────────────────────────
--    NotificationRepository paginates by (user_id, id DESC).
--    Existing idx_user_created uses created_at, not id.
ALTER TABLE notifications
  ADD INDEX IF NOT EXISTS idx_user_id_desc (user_id, id);

-- ── 7. notifications: unread count ─────────────────────────────────────────
--    COUNT(*) WHERE user_id=? AND read_at IS NULL
--    Existing idx_user_unread (user_id, read_at, created_at) works but
--    for pure count a tighter index helps.
-- (idx_user_unread already covers this — no change needed)

-- ── 8. moderation_actions: cursor pagination by id ─────────────────────────
--    ModerationRepository paginates by (space_id, id DESC) not (space_id, created_at).
ALTER TABLE moderation_actions
  ADD INDEX IF NOT EXISTS idx_space_id_desc (space_id, id);

ALTER TABLE moderation_actions
  ADD INDEX IF NOT EXISTS idx_channel_id_desc (channel_id, id);

-- ── 9. domain_events: published_at for polling ────────────────────────────
--    Already has idx_unpublished (published_at, id).
-- (no change needed)

-- ── 10. jobs: completed_at for purge ──────────────────────────────────────
ALTER TABLE jobs
  ADD INDEX IF NOT EXISTS idx_completed (status, completed_at);

-- ── 11. threads: index on thread_id for message counting ──────────────────
--    Unread thread query: WHERE m.thread_id = t.id AND m.deleted_at IS NULL
--    Thread messages use idx_thread_id (thread_id, id) — sufficient.
-- (no change needed)

-- ── 12. rate_limits: cleanup index ────────────────────────────────────────
--    Already has idx_attempted_at. Good.
-- (no change needed)


-- ═══════════════════════════════════════════════════════════════════════════════
-- EXPLAIN verification queries — run these manually to validate index usage.
-- Paste into MySQL CLI after migration.
-- ═══════════════════════════════════════════════════════════════════════════════

-- Test 1: Channel message pagination (should use idx_chan_main_cursor)
-- EXPLAIN SELECT m.id FROM messages m
--   WHERE m.channel_id = 1 AND m.deleted_at IS NULL AND m.thread_id IS NULL
--     AND m.id < 999999
--   ORDER BY m.id DESC LIMIT 51;

-- Test 2: Unread count channels (should use idx_chan_unread)
-- EXPLAIN SELECT cm.channel_id, COUNT(m.id) AS unread
--   FROM channel_members cm
--   JOIN messages m ON m.channel_id = cm.channel_id
--     AND m.deleted_at IS NULL AND m.thread_id IS NULL
--   LEFT JOIN read_receipts rr
--     ON rr.user_id = cm.user_id AND rr.channel_id = cm.channel_id
--   WHERE cm.user_id = 1
--     AND m.id > COALESCE(rr.last_read_message_id, 0)
--     AND m.user_id != 1
--   GROUP BY cm.channel_id;

-- Test 3: Notification pagination (should use idx_user_id_desc)
-- EXPLAIN SELECT n.id FROM notifications n
--   WHERE n.user_id = 1 AND n.id < 999999
--   ORDER BY n.id DESC LIMIT 21;

-- Test 4: Message search FULLTEXT (should use ft_body)
-- EXPLAIN SELECT m.id FROM messages m
--   WHERE m.deleted_at IS NULL
--     AND MATCH(m.body) AGAINST('+hello*' IN BOOLEAN MODE)
--   ORDER BY m.created_at DESC LIMIT 30;

-- Test 5: Conversation lookup for user (should use idx_user_conversation)
-- EXPLAIN SELECT c.id FROM conversations c
--   JOIN conversation_members cm ON cm.conversation_id = c.id
--   WHERE cm.user_id = 1;
