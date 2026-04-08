-- ============================================================================
-- Multi-Tenancy Migration: Space-scoped notifications
-- ============================================================================
-- Adds space_id to the notifications table so that every notification is
-- explicitly tied to a workspace.  Backfills existing rows from the linked
-- channel or conversation.
-- ============================================================================

-- 1. Add the column (nullable first so the backfill works)
ALTER TABLE notifications
  ADD COLUMN space_id INT UNSIGNED DEFAULT NULL AFTER user_id;

-- 2. Backfill from channel
UPDATE notifications n
  JOIN channels c ON c.id = n.channel_id
SET n.space_id = c.space_id
WHERE n.space_id IS NULL AND n.channel_id IS NOT NULL;

-- 3. Backfill from conversation
UPDATE notifications n
  JOIN conversations cv ON cv.id = n.conversation_id
SET n.space_id = cv.space_id
WHERE n.space_id IS NULL AND n.conversation_id IS NOT NULL;

-- 4. Make NOT NULL now that all rows are filled
ALTER TABLE notifications
  MODIFY COLUMN space_id INT UNSIGNED NOT NULL;

-- 5. Add FK + index for space-scoped queries
ALTER TABLE notifications
  ADD CONSTRAINT fk_notifications_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
  ADD INDEX idx_notifications_user_space (user_id, space_id, read_at, created_at);
