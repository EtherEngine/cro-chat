-- cro_chat Database Schema
-- MariaDB / MySQL

CREATE DATABASE IF NOT EXISTS cro_chat
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE cro_chat;

-- ── Users ──────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL DEFAULT '',
  display_name  VARCHAR(100) NOT NULL,
  title         VARCHAR(150) NOT NULL DEFAULT '',
  avatar_color  CHAR(7)      NOT NULL DEFAULT '#7C3AED',
  status        ENUM('online','away','offline') NOT NULL DEFAULT 'offline',
  last_seen_at  TIMESTAMP    NULL DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status_lastseen (status, last_seen_at)
) ENGINE=InnoDB;

-- ── Spaces (Workspaces) ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS spaces (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100)  NOT NULL,
  slug        VARCHAR(100)  NOT NULL UNIQUE,
  description VARCHAR(255)  NOT NULL DEFAULT '',
  owner_id    INT UNSIGNED  NOT NULL,
  created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS space_members (
  space_id  INT UNSIGNED NOT NULL,
  user_id   INT UNSIGNED NOT NULL,
  role      ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (space_id, user_id),
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Channels ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS channels (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id    INT UNSIGNED  NOT NULL,
  name        VARCHAR(100)  NOT NULL,
  description VARCHAR(255)  NOT NULL DEFAULT '',
  color       CHAR(7)       NOT NULL DEFAULT '#7C3AED',
  is_private  TINYINT(1)    NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED  DEFAULT NULL,
  created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (space_id)   REFERENCES spaces(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id)  ON DELETE SET NULL,
  UNIQUE KEY uq_space_channel (space_id, name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS channel_members (
  channel_id INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  role       ENUM('admin','member') NOT NULL DEFAULT 'member',
  joined_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (channel_id, user_id),
  FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Conversations (DMs & Group DMs) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS conversations (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id         INT UNSIGNED NOT NULL,
  is_group         TINYINT(1)   NOT NULL DEFAULT 0,
  title            VARCHAR(150) NOT NULL DEFAULT '',
  created_by       INT UNSIGNED DEFAULT NULL,
  avatar_url       VARCHAR(500) NOT NULL DEFAULT '',
  participant_hash CHAR(64)     DEFAULT NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (space_id)   REFERENCES spaces(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id)  ON DELETE SET NULL,
  UNIQUE KEY uq_participant_hash (space_id, participant_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversation_members (
  conversation_id INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  PRIMARY KEY (conversation_id, user_id),
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Messages ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS messages (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  body            TEXT         NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  channel_id      INT UNSIGNED DEFAULT NULL,
  conversation_id INT UNSIGNED DEFAULT NULL,
  reply_to_id     INT UNSIGNED DEFAULT NULL,
  thread_id       INT UNSIGNED DEFAULT NULL,
  idempotency_key CHAR(36)     DEFAULT NULL,
  edited_at       TIMESTAMP    NULL DEFAULT NULL,
  deleted_at      TIMESTAMP    NULL DEFAULT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
  FOREIGN KEY (channel_id)      REFERENCES channels(id)      ON DELETE CASCADE,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (reply_to_id)     REFERENCES messages(id)      ON DELETE SET NULL,
  FOREIGN KEY (thread_id)       REFERENCES threads(id)       ON DELETE CASCADE,
  UNIQUE KEY uq_idempotency (user_id, idempotency_key),
  INDEX idx_channel_id      (channel_id, id),
  INDEX idx_conversation_id (conversation_id, id),
  INDEX idx_reply_to        (reply_to_id),
  INDEX idx_thread_id       (thread_id, id),
  FULLTEXT INDEX ft_body    (body),
  -- Ensure exactly one target
  CONSTRAINT chk_target CHECK (
    (channel_id IS NOT NULL AND conversation_id IS NULL)
    OR (channel_id IS NULL AND conversation_id IS NOT NULL)
  )
) ENGINE=InnoDB;

-- ── Threads ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS threads (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  root_message_id INT UNSIGNED NOT NULL,
  channel_id      INT UNSIGNED DEFAULT NULL,
  conversation_id INT UNSIGNED DEFAULT NULL,
  reply_count     INT UNSIGNED NOT NULL DEFAULT 0,
  last_reply_at   TIMESTAMP    NULL DEFAULT NULL,
  created_by      INT UNSIGNED NOT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (root_message_id) REFERENCES messages(id) ON DELETE CASCADE,
  FOREIGN KEY (channel_id)      REFERENCES channels(id) ON DELETE CASCADE,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by)      REFERENCES users(id)    ON DELETE CASCADE,
  UNIQUE KEY uq_root_message (root_message_id),
  CONSTRAINT chk_thread_target CHECK (
    (channel_id IS NOT NULL AND conversation_id IS NULL)
    OR (channel_id IS NULL AND conversation_id IS NOT NULL)
  )
) ENGINE=InnoDB;

-- ── Message Edit History ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS message_edits (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id INT UNSIGNED NOT NULL,
  body       TEXT         NOT NULL,
  edited_by  INT UNSIGNED NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  FOREIGN KEY (edited_by)  REFERENCES users(id)    ON DELETE CASCADE,
  INDEX idx_message_edits (message_id, created_at)
) ENGINE=InnoDB;

-- ── Attachments ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS attachments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id    INT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  storage_name  VARCHAR(70)  NOT NULL,
  mime_type     VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
  file_size     INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  INDEX idx_attachment_message (message_id),
  UNIQUE KEY uq_storage_name (storage_name)
) ENGINE=InnoDB;

-- ── E2EE Key Bundles ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_keys (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  device_id       VARCHAR(64)  NOT NULL,
  identity_key    TEXT         NOT NULL,
  signed_pre_key  TEXT         NOT NULL,
  pre_key_sig     TEXT         NOT NULL,
  one_time_keys   JSON         DEFAULT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_device (user_id, device_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Per-conversation E2EE session keys ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS conversation_keys (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  device_id       VARCHAR(64)  NOT NULL,
  encrypted_key   TEXT         NOT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conv_user_device (conversation_id, user_id, device_id),
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Read Receipts ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS read_receipts (
  user_id             INT UNSIGNED NOT NULL,
  channel_id          INT UNSIGNED DEFAULT NULL,
  conversation_id     INT UNSIGNED DEFAULT NULL,
  thread_id           INT UNSIGNED DEFAULT NULL,
  last_read_message_id INT UNSIGNED NOT NULL,
  read_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_channel (user_id, channel_id),
  UNIQUE KEY uq_user_conversation (user_id, conversation_id),
  UNIQUE KEY uq_user_thread (user_id, thread_id),
  FOREIGN KEY (user_id)             REFERENCES users(id)         ON DELETE CASCADE,
  FOREIGN KEY (channel_id)          REFERENCES channels(id)      ON DELETE CASCADE,
  FOREIGN KEY (conversation_id)     REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (thread_id)           REFERENCES threads(id)       ON DELETE CASCADE,
  FOREIGN KEY (last_read_message_id) REFERENCES messages(id)     ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Message Reactions ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS message_reactions (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  emoji      VARCHAR(32)  NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  UNIQUE KEY uq_message_user_emoji (message_id, user_id, emoji),
  INDEX idx_reaction_message (message_id)
) ENGINE=InnoDB;
-- ── Message Mentions ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS message_mentions (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id        INT UNSIGNED NOT NULL,
  mentioned_user_id INT UNSIGNED NOT NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (message_id)        REFERENCES messages(id) ON DELETE CASCADE,
  FOREIGN KEY (mentioned_user_id) REFERENCES users(id)    ON DELETE CASCADE,
  UNIQUE KEY uq_message_user (message_id, mentioned_user_id),
  INDEX idx_mentioned_user (mentioned_user_id, message_id)
) ENGINE=InnoDB;

-- ── Notifications ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  type            VARCHAR(30)  NOT NULL,
  actor_id        INT UNSIGNED NOT NULL,
  message_id      INT UNSIGNED DEFAULT NULL,
  channel_id      INT UNSIGNED DEFAULT NULL,
  conversation_id INT UNSIGNED DEFAULT NULL,
  thread_id       INT UNSIGNED DEFAULT NULL,
  data            JSON         DEFAULT NULL,
  read_at         TIMESTAMP    NULL DEFAULT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
  FOREIGN KEY (actor_id)        REFERENCES users(id)         ON DELETE CASCADE,
  FOREIGN KEY (message_id)      REFERENCES messages(id)      ON DELETE CASCADE,
  FOREIGN KEY (channel_id)      REFERENCES channels(id)      ON DELETE CASCADE,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (thread_id)       REFERENCES threads(id)       ON DELETE CASCADE,
  INDEX idx_user_unread (user_id, read_at, created_at),
  INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB;

-- ── Rate Limits ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS rate_limits (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action       VARCHAR(50) NOT NULL,
  `key`        VARCHAR(255) NOT NULL,
  attempted_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_action_key (action, `key`),
  INDEX idx_attempted_at (attempted_at)
) ENGINE=InnoDB;

-- ── Search Indexes ──────────────────────────────────────────────────────────────
-- Speeds up LIKE '%…%' prefix scans and ORDER BY for channel/user search.
-- messages.body already has FULLTEXT INDEX ft_body (body) defined above.
ALTER TABLE users       ADD INDEX IF NOT EXISTS idx_display_name (display_name);
ALTER TABLE channels    ADD INDEX IF NOT EXISTS idx_channel_name (name);

-- ── Domain Events (Outbox) ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS domain_events (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type   VARCHAR(50)  NOT NULL,
  room         VARCHAR(100) NOT NULL,
  payload      JSON         NOT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  published_at TIMESTAMP    NULL DEFAULT NULL,
  INDEX idx_unpublished (published_at, id)
) ENGINE=InnoDB;
