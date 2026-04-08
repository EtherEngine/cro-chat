-- Migration: Knowledge Layer
-- Adds tables for thread/channel summaries, topics, decisions, and knowledge extraction

USE cro_chat;

-- ── Knowledge Topics ───────────────────────────────────────────────────────────
-- Extracted topics/themes from conversations
CREATE TABLE IF NOT EXISTS knowledge_topics (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    channel_id      INT UNSIGNED NULL,
    name            VARCHAR(200) NOT NULL,
    slug            VARCHAR(200) NOT NULL,
    description     TEXT NULL,
    message_count   INT UNSIGNED NOT NULL DEFAULT 0,
    last_activity   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_space_channel (space_id, channel_id),
    INDEX idx_slug (space_id, slug),
    INDEX idx_activity (space_id, last_activity DESC),
    FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Knowledge Decisions ────────────────────────────────────────────────────────
-- Extracted decisions from conversations (e.g. "We decided to use React")
CREATE TABLE IF NOT EXISTS knowledge_decisions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    channel_id      INT UNSIGNED NULL,
    topic_id        INT UNSIGNED NULL,
    title           VARCHAR(500) NOT NULL,
    description     TEXT NULL,
    status          ENUM('proposed','accepted','rejected','superseded') NOT NULL DEFAULT 'accepted',
    decided_at      DATETIME NULL,
    decided_by      INT UNSIGNED NULL,
    source_message_id INT UNSIGNED NULL,       -- The message where the decision was made
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_space (space_id),
    INDEX idx_topic (topic_id),
    INDEX idx_status (space_id, status),
    FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE SET NULL,
    FOREIGN KEY (topic_id) REFERENCES knowledge_topics(id) ON DELETE SET NULL,
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (source_message_id) REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Knowledge Summaries ────────────────────────────────────────────────────────
-- Thread, channel or time-range summaries
CREATE TABLE IF NOT EXISTS knowledge_summaries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    scope_type      ENUM('thread','channel','conversation','daily','weekly') NOT NULL,
    scope_id        INT UNSIGNED NULL,         -- thread_id, channel_id, or conversation_id
    title           VARCHAR(500) NOT NULL,
    summary         TEXT NOT NULL,
    key_points      JSON NULL,                 -- ["point 1", "point 2", ...]
    participants    JSON NULL,                 -- [user_id, ...]
    message_count   INT UNSIGNED NOT NULL DEFAULT 0,
    first_message_id INT UNSIGNED NULL,
    last_message_id  INT UNSIGNED NULL,
    period_start    DATETIME NULL,             -- For time-range summaries
    period_end      DATETIME NULL,
    generated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scope (space_id, scope_type, scope_id),
    INDEX idx_period (space_id, scope_type, period_start),
    FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Knowledge Entries ──────────────────────────────────────────────────────────
-- Individual knowledge items extracted from messages (facts, how-tos, links, etc.)
CREATE TABLE IF NOT EXISTS knowledge_entries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    topic_id        INT UNSIGNED NULL,
    entry_type      ENUM('fact','howto','link','reference','definition','action_item') NOT NULL DEFAULT 'fact',
    title           VARCHAR(500) NOT NULL,
    content         TEXT NOT NULL,
    tags            JSON NULL,                 -- ["tag1", "tag2"]
    confidence      DECIMAL(3,2) NOT NULL DEFAULT 1.00,  -- 0.00 – 1.00
    extracted_by    ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    created_by      INT UNSIGNED NULL,
    source_message_id INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_space_topic (space_id, topic_id),
    INDEX idx_type (space_id, entry_type),
    FULLTEXT idx_ft_knowledge (title, content),
    FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
    FOREIGN KEY (topic_id) REFERENCES knowledge_topics(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (source_message_id) REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Knowledge Source Links ─────────────────────────────────────────────────────
-- Links knowledge items back to their source messages (many-to-many)
CREATE TABLE IF NOT EXISTS knowledge_sources (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_id        INT UNSIGNED NULL,
    summary_id      INT UNSIGNED NULL,
    decision_id     INT UNSIGNED NULL,
    message_id      INT UNSIGNED NOT NULL,
    relevance       DECIMAL(3,2) NOT NULL DEFAULT 1.00,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entry (entry_id),
    INDEX idx_summary (summary_id),
    INDEX idx_decision (decision_id),
    INDEX idx_message (message_id),
    FOREIGN KEY (entry_id) REFERENCES knowledge_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (summary_id) REFERENCES knowledge_summaries(id) ON DELETE CASCADE,
    FOREIGN KEY (decision_id) REFERENCES knowledge_decisions(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Knowledge Jobs Tracking ────────────────────────────────────────────────────
-- Tracks which threads/channels have been summarized and when
CREATE TABLE IF NOT EXISTS knowledge_jobs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    scope_type      ENUM('thread','channel','conversation','daily','weekly') NOT NULL,
    scope_id        INT UNSIGNED NULL,
    last_message_id INT UNSIGNED NULL,         -- Cursor: last processed message
    last_run_at     DATETIME NULL,
    next_run_at     DATETIME NULL,
    status          ENUM('idle','running','error') NOT NULL DEFAULT 'idle',
    error_message   TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_scope (space_id, scope_type, scope_id),
    INDEX idx_next_run (status, next_run_at),
    FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ══════════════════════════════════════════════════════════════════════════════
-- Apply same schema to test database
-- ══════════════════════════════════════════════════════════════════════════════
USE cro_chat_test;

CREATE TABLE IF NOT EXISTS knowledge_topics (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    channel_id      INT UNSIGNED NULL,
    name            VARCHAR(200) NOT NULL,
    slug            VARCHAR(200) NOT NULL,
    description     TEXT NULL,
    message_count   INT UNSIGNED NOT NULL DEFAULT 0,
    last_activity   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_space_channel (space_id, channel_id),
    INDEX idx_slug (space_id, slug),
    INDEX idx_activity (space_id, last_activity DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_decisions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    channel_id      INT UNSIGNED NULL,
    topic_id        INT UNSIGNED NULL,
    title           VARCHAR(500) NOT NULL,
    description     TEXT NULL,
    status          ENUM('proposed','accepted','rejected','superseded') NOT NULL DEFAULT 'accepted',
    decided_at      DATETIME NULL,
    decided_by      INT UNSIGNED NULL,
    source_message_id INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_space (space_id),
    INDEX idx_topic (topic_id),
    INDEX idx_status (space_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_summaries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    scope_type      ENUM('thread','channel','conversation','daily','weekly') NOT NULL,
    scope_id        INT UNSIGNED NULL,
    title           VARCHAR(500) NOT NULL,
    summary         TEXT NOT NULL,
    key_points      JSON NULL,
    participants    JSON NULL,
    message_count   INT UNSIGNED NOT NULL DEFAULT 0,
    first_message_id INT UNSIGNED NULL,
    last_message_id  INT UNSIGNED NULL,
    period_start    DATETIME NULL,
    period_end      DATETIME NULL,
    generated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scope (space_id, scope_type, scope_id),
    INDEX idx_period (space_id, scope_type, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_entries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    topic_id        INT UNSIGNED NULL,
    entry_type      ENUM('fact','howto','link','reference','definition','action_item') NOT NULL DEFAULT 'fact',
    title           VARCHAR(500) NOT NULL,
    content         TEXT NOT NULL,
    tags            JSON NULL,
    confidence      DECIMAL(3,2) NOT NULL DEFAULT 1.00,
    extracted_by    ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    created_by      INT UNSIGNED NULL,
    source_message_id INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_space_topic (space_id, topic_id),
    INDEX idx_type (space_id, entry_type),
    FULLTEXT idx_ft_knowledge (title, content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_sources (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_id        INT UNSIGNED NULL,
    summary_id      INT UNSIGNED NULL,
    decision_id     INT UNSIGNED NULL,
    message_id      INT UNSIGNED NOT NULL,
    relevance       DECIMAL(3,2) NOT NULL DEFAULT 1.00,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entry (entry_id),
    INDEX idx_summary (summary_id),
    INDEX idx_decision (decision_id),
    INDEX idx_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_jobs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    scope_type      ENUM('thread','channel','conversation','daily','weekly') NOT NULL,
    scope_id        INT UNSIGNED NULL,
    last_message_id INT UNSIGNED NULL,
    last_run_at     DATETIME NULL,
    next_run_at     DATETIME NULL,
    status          ENUM('idle','running','error') NOT NULL DEFAULT 'idle',
    error_message   TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_scope (space_id, scope_type, scope_id),
    INDEX idx_next_run (status, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
