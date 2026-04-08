-- ── Migration: Roles & Moderation ──────────────────────────────────────────────
-- Extends role model to owner/admin/moderator/member/guest
-- Adds moderation_actions audit log and mute support

-- 1. Extend space_members role ENUM
ALTER TABLE space_members
  MODIFY COLUMN role ENUM('owner','admin','moderator','member','guest') NOT NULL DEFAULT 'member';

-- 2. Extend channel_members role ENUM
ALTER TABLE channel_members
  MODIFY COLUMN role ENUM('admin','moderator','member','guest') NOT NULL DEFAULT 'member';

-- 3. Add muted_until columns
ALTER TABLE space_members
  ADD COLUMN muted_until TIMESTAMP NULL DEFAULT NULL AFTER role;

ALTER TABLE channel_members
  ADD COLUMN muted_until TIMESTAMP NULL DEFAULT NULL AFTER role;

-- 4. Moderation actions audit log
CREATE TABLE IF NOT EXISTS moderation_actions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id        INT UNSIGNED NOT NULL,
  channel_id      INT UNSIGNED DEFAULT NULL,
  action_type     VARCHAR(50)  NOT NULL,
  actor_id        INT UNSIGNED NOT NULL,
  target_user_id  INT UNSIGNED DEFAULT NULL,
  message_id      INT UNSIGNED DEFAULT NULL,
  reason          TEXT         DEFAULT NULL,
  metadata        JSON         DEFAULT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_space_actions (space_id, created_at),
  INDEX idx_channel_actions (channel_id, created_at),
  INDEX idx_target_user (target_user_id, created_at),
  INDEX idx_actor (actor_id, created_at),

  CONSTRAINT fk_mod_space   FOREIGN KEY (space_id)       REFERENCES spaces(id)   ON DELETE CASCADE,
  CONSTRAINT fk_mod_channel FOREIGN KEY (channel_id)     REFERENCES channels(id) ON DELETE CASCADE,
  CONSTRAINT fk_mod_actor   FOREIGN KEY (actor_id)       REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_mod_target  FOREIGN KEY (target_user_id) REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_mod_message FOREIGN KEY (message_id)     REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB;
