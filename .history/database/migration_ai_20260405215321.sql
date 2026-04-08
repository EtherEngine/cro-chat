-- ============================================================
-- AI Features Migration
-- Thread/Channel Summaries, Action-Item Extraction,
-- Semantic Search, Reply Suggestions
-- ============================================================

-- ── AI Summaries (LLM-generated, separate from heuristic knowledge_summaries) ──
CREATE TABLE IF NOT EXISTS ai_summaries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    scope_type      ENUM('thread','channel','conversation') NOT NULL,
    scope_id        INT UNSIGNED NOT NULL,
    title           VARCHAR(500)   NOT NULL DEFAULT '',
    summary         TEXT           NOT NULL,
    key_points      JSON           DEFAULT NULL,
    action_items    JSON           DEFAULT NULL,
    participants    JSON           DEFAULT NULL,
    message_count   INT UNSIGNED   NOT NULL DEFAULT 0,
    first_message_id INT UNSIGNED  DEFAULT NULL,
    last_message_id  INT UNSIGNED  DEFAULT NULL,
    period_start    DATETIME       DEFAULT NULL,
    period_end      DATETIME       DEFAULT NULL,
    model           VARCHAR(100)   NOT NULL DEFAULT '',
    tokens_used     INT UNSIGNED   NOT NULL DEFAULT 0,
    processing_ms   INT UNSIGNED   NOT NULL DEFAULT 0,
    created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_sum_scope (space_id, scope_type, scope_id),
    INDEX idx_ai_sum_created (space_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── AI Summary ↔ Message source links (traceability) ──
CREATE TABLE IF NOT EXISTS ai_summary_sources (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    summary_id      INT UNSIGNED NOT NULL,
    message_id      INT UNSIGNED NOT NULL,
    relevance       DECIMAL(3,2) NOT NULL DEFAULT 1.00,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_src_summary (summary_id),
    INDEX idx_ai_src_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── AI Action Items (extracted by LLM from messages) ──
CREATE TABLE IF NOT EXISTS ai_action_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    summary_id      INT UNSIGNED DEFAULT NULL,
    source_message_id INT UNSIGNED DEFAULT NULL,
    title           VARCHAR(500)   NOT NULL,
    description     TEXT           DEFAULT NULL,
    assignee_hint   VARCHAR(200)   DEFAULT NULL,
    due_hint        VARCHAR(200)   DEFAULT NULL,
    status          ENUM('open','done','dismissed') NOT NULL DEFAULT 'open',
    confidence      DECIMAL(3,2)   NOT NULL DEFAULT 0.80,
    created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ai_act_space (space_id, status),
    INDEX idx_ai_act_summary (summary_id),
    INDEX idx_ai_act_message (source_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── AI Embeddings (vector storage for semantic search) ──
CREATE TABLE IF NOT EXISTS ai_embeddings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    message_id      INT UNSIGNED NOT NULL,
    embedding       BLOB           NOT NULL,
    model           VARCHAR(100)   NOT NULL DEFAULT '',
    dimensions      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_ai_emb_msg (message_id),
    INDEX idx_ai_emb_space (space_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── AI Reply Suggestions (context-aware suggestions) ──
CREATE TABLE IF NOT EXISTS ai_suggestions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    scope_type      ENUM('thread','channel','conversation') NOT NULL,
    scope_id        INT UNSIGNED NOT NULL,
    context_message_id INT UNSIGNED DEFAULT NULL,
    suggestions     JSON           NOT NULL,
    model           VARCHAR(100)   NOT NULL DEFAULT '',
    tokens_used     INT UNSIGNED   NOT NULL DEFAULT 0,
    accepted_index  TINYINT        DEFAULT NULL,
    created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_sug_scope (user_id, scope_type, scope_id),
    INDEX idx_ai_sug_space (space_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── AI Processing Jobs (cursor + status tracking) ──
CREATE TABLE IF NOT EXISTS ai_jobs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    job_type        ENUM('summarize','extract','embed','suggest') NOT NULL,
    scope_type      ENUM('thread','channel','conversation','space') NOT NULL,
    scope_id        INT UNSIGNED DEFAULT NULL,
    last_message_id INT UNSIGNED DEFAULT NULL,
    last_run_at     DATETIME     DEFAULT NULL,
    status          ENUM('idle','running','error') NOT NULL DEFAULT 'idle',
    error_message   TEXT         DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_ai_job_unique (space_id, job_type, scope_type, scope_id),
    INDEX idx_ai_job_status (status, last_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── AI Provider config per space ──
CREATE TABLE IF NOT EXISTS ai_provider_config (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    provider        VARCHAR(50)    NOT NULL DEFAULT 'openai',
    api_key_enc     TEXT           DEFAULT NULL,
    model_summary   VARCHAR(100)   NOT NULL DEFAULT 'gpt-4o-mini',
    model_embedding VARCHAR(100)   NOT NULL DEFAULT 'text-embedding-3-small',
    model_suggest   VARCHAR(100)   NOT NULL DEFAULT 'gpt-4o-mini',
    max_tokens      INT UNSIGNED   NOT NULL DEFAULT 2000,
    temperature     DECIMAL(3,2)   NOT NULL DEFAULT 0.30,
    is_enabled      TINYINT(1)     NOT NULL DEFAULT 0,
    created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_ai_cfg_space (space_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
