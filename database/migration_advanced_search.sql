-- ============================================================================
-- Advanced Search: saved_searches + search_history
-- ============================================================================

-- ── Production DB ────────────────────────────────────────────────────────────
USE cro_chat;

CREATE TABLE IF NOT EXISTS saved_searches (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  space_id    INT UNSIGNED NOT NULL,
  name        VARCHAR(100) NOT NULL,
  query       VARCHAR(500) NOT NULL,
  filters     JSON         DEFAULT NULL,
  notify      TINYINT(1)   NOT NULL DEFAULT 0,
  last_run_at TIMESTAMP    NULL DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
  INDEX idx_saved_user_space (user_id, space_id),
  INDEX idx_saved_notify (notify, last_run_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS search_history (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  query      VARCHAR(500) NOT NULL,
  filters    JSON         DEFAULT NULL,
  result_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_history_user (user_id, created_at)
) ENGINE=InnoDB;

-- Composite index for ranking by relevance + recency (covering index)
ALTER TABLE messages
  ADD INDEX IF NOT EXISTS idx_messages_created_deleted (deleted_at, created_at, id);

-- ── Test DB ──────────────────────────────────────────────────────────────────
USE cro_chat_test;

CREATE TABLE IF NOT EXISTS saved_searches (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  space_id    INT UNSIGNED NOT NULL,
  name        VARCHAR(100) NOT NULL,
  query       VARCHAR(500) NOT NULL,
  filters     JSON         DEFAULT NULL,
  notify      TINYINT(1)   NOT NULL DEFAULT 0,
  last_run_at TIMESTAMP    NULL DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
  INDEX idx_saved_user_space (user_id, space_id),
  INDEX idx_saved_notify (notify, last_run_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS search_history (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  query      VARCHAR(500) NOT NULL,
  filters    JSON         DEFAULT NULL,
  result_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_history_user (user_id, created_at)
) ENGINE=InnoDB;

ALTER TABLE messages
  ADD INDEX IF NOT EXISTS idx_messages_created_deleted (deleted_at, created_at, id);
