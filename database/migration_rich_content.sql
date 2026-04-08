-- Migration: Rich Content – Snippets, Link Previews, Shared Drafts
-- Tables: snippets, link_previews, drafts, draft_collaborators

-- ══════════════════════════════════════════════════════════════
-- Production database
-- ══════════════════════════════════════════════════════════════
USE cro_chat;

-- ── Snippets (shared code / text blocks) ──────────────────────
CREATE TABLE IF NOT EXISTS snippets (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id        INT UNSIGNED  NOT NULL,
  channel_id      INT UNSIGNED  DEFAULT NULL,
  user_id         INT UNSIGNED  NOT NULL,
  title           VARCHAR(200)  NOT NULL,
  language        VARCHAR(50)   NOT NULL DEFAULT 'text',
  content         MEDIUMTEXT    NOT NULL,
  description     VARCHAR(500)  NOT NULL DEFAULT '',
  is_public       TINYINT(1)    NOT NULL DEFAULT 1,
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (space_id)   REFERENCES spaces(id)   ON DELETE CASCADE,
  FOREIGN KEY (channel_id) REFERENCES channels(id)  ON DELETE SET NULL,
  FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE,
  INDEX idx_space_id    (space_id, created_at),
  INDEX idx_user_id     (user_id),
  INDEX idx_language    (language),
  FULLTEXT INDEX ft_snippet (title, content)
) ENGINE=InnoDB;

-- ── Link Previews (unfurled metadata cache) ───────────────────
CREATE TABLE IF NOT EXISTS link_previews (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id      INT UNSIGNED  NOT NULL,
  url             VARCHAR(2048) NOT NULL,
  title           VARCHAR(500)  DEFAULT NULL,
  description     VARCHAR(1000) DEFAULT NULL,
  image_url       VARCHAR(2048) DEFAULT NULL,
  site_name       VARCHAR(200)  DEFAULT NULL,
  content_type    VARCHAR(100)  DEFAULT NULL,
  status          ENUM('pending','resolved','failed') NOT NULL DEFAULT 'pending',
  error_message   VARCHAR(500)  DEFAULT NULL,
  fetched_at      TIMESTAMP     NULL DEFAULT NULL,
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  INDEX idx_message_id (message_id),
  INDEX idx_status     (status),
  INDEX idx_url_hash   (url(255))
) ENGINE=InnoDB;

-- ── Shared Drafts ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS drafts (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id        INT UNSIGNED  NOT NULL,
  channel_id      INT UNSIGNED  DEFAULT NULL,
  conversation_id INT UNSIGNED  DEFAULT NULL,
  user_id         INT UNSIGNED  NOT NULL,
  title           VARCHAR(200)  NOT NULL DEFAULT '',
  body            MEDIUMTEXT    NOT NULL,
  format          ENUM('markdown','plaintext') NOT NULL DEFAULT 'markdown',
  is_shared       TINYINT(1)    NOT NULL DEFAULT 0,
  version         INT UNSIGNED  NOT NULL DEFAULT 1,
  published_message_id INT UNSIGNED DEFAULT NULL,
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (space_id)        REFERENCES spaces(id)        ON DELETE CASCADE,
  FOREIGN KEY (channel_id)      REFERENCES channels(id)      ON DELETE SET NULL,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
  FOREIGN KEY (published_message_id) REFERENCES messages(id) ON DELETE SET NULL,
  INDEX idx_space_user (space_id, user_id),
  INDEX idx_channel    (channel_id),
  INDEX idx_conversation (conversation_id),
  INDEX idx_shared     (is_shared, space_id)
) ENGINE=InnoDB;

-- ── Draft Collaborators ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS draft_collaborators (
  draft_id   INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  permission ENUM('view','edit') NOT NULL DEFAULT 'view',
  added_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (draft_id, user_id),
  FOREIGN KEY (draft_id) REFERENCES drafts(id)  ON DELETE CASCADE,
  FOREIGN KEY (user_id)  REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- ══════════════════════════════════════════════════════════════
-- Test database (mirror)
-- ══════════════════════════════════════════════════════════════
USE cro_chat_test;

CREATE TABLE IF NOT EXISTS snippets (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id        INT UNSIGNED  NOT NULL,
  channel_id      INT UNSIGNED  DEFAULT NULL,
  user_id         INT UNSIGNED  NOT NULL,
  title           VARCHAR(200)  NOT NULL,
  language        VARCHAR(50)   NOT NULL DEFAULT 'text',
  content         MEDIUMTEXT    NOT NULL,
  description     VARCHAR(500)  NOT NULL DEFAULT '',
  is_public       TINYINT(1)    NOT NULL DEFAULT 1,
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (space_id)   REFERENCES spaces(id)   ON DELETE CASCADE,
  FOREIGN KEY (channel_id) REFERENCES channels(id)  ON DELETE SET NULL,
  FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE,
  INDEX idx_space_id    (space_id, created_at),
  INDEX idx_user_id     (user_id),
  INDEX idx_language    (language),
  FULLTEXT INDEX ft_snippet (title, content)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS link_previews (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id      INT UNSIGNED  NOT NULL,
  url             VARCHAR(2048) NOT NULL,
  title           VARCHAR(500)  DEFAULT NULL,
  description     VARCHAR(1000) DEFAULT NULL,
  image_url       VARCHAR(2048) DEFAULT NULL,
  site_name       VARCHAR(200)  DEFAULT NULL,
  content_type    VARCHAR(100)  DEFAULT NULL,
  status          ENUM('pending','resolved','failed') NOT NULL DEFAULT 'pending',
  error_message   VARCHAR(500)  DEFAULT NULL,
  fetched_at      TIMESTAMP     NULL DEFAULT NULL,
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  INDEX idx_message_id (message_id),
  INDEX idx_status     (status),
  INDEX idx_url_hash   (url(255))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS drafts (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id        INT UNSIGNED  NOT NULL,
  channel_id      INT UNSIGNED  DEFAULT NULL,
  conversation_id INT UNSIGNED  DEFAULT NULL,
  user_id         INT UNSIGNED  NOT NULL,
  title           VARCHAR(200)  NOT NULL DEFAULT '',
  body            MEDIUMTEXT    NOT NULL,
  format          ENUM('markdown','plaintext') NOT NULL DEFAULT 'markdown',
  is_shared       TINYINT(1)    NOT NULL DEFAULT 0,
  version         INT UNSIGNED  NOT NULL DEFAULT 1,
  published_message_id INT UNSIGNED DEFAULT NULL,
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (space_id)        REFERENCES spaces(id)        ON DELETE CASCADE,
  FOREIGN KEY (channel_id)      REFERENCES channels(id)      ON DELETE SET NULL,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
  FOREIGN KEY (published_message_id) REFERENCES messages(id) ON DELETE SET NULL,
  INDEX idx_space_user (space_id, user_id),
  INDEX idx_channel    (channel_id),
  INDEX idx_conversation (conversation_id),
  INDEX idx_shared     (is_shared, space_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS draft_collaborators (
  draft_id   INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  permission ENUM('view','edit') NOT NULL DEFAULT 'view',
  added_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (draft_id, user_id),
  FOREIGN KEY (draft_id) REFERENCES drafts(id)  ON DELETE CASCADE,
  FOREIGN KEY (user_id)  REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB;
