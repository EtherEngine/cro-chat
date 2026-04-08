-- Migration: Integration Platform
-- Adds tables for API tokens, service accounts, webhooks, event subscriptions

USE cro_chat;

-- ── API Tokens ─────────────────────────────────────────────────────────────────
-- Personal or integration-level bearer tokens for API access
CREATE TABLE IF NOT EXISTS api_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NULL,           -- NULL = service account token
    service_account_id INT UNSIGNED NULL,         -- NULL = personal token
    name            VARCHAR(100) NOT NULL,
    token_hash      VARCHAR(255) NOT NULL,        -- SHA-256 of the token
    token_prefix    VARCHAR(12) NOT NULL,         -- first 8 chars for display (cro_xxxx)
    scopes          JSON NOT NULL,                -- ["messages.read","messages.write",...]
    last_used_at    DATETIME NULL,
    expires_at      DATETIME NULL,
    revoked_at      DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_hash (token_hash),
    INDEX idx_space_user (space_id, user_id),
    INDEX idx_space_sa (space_id, service_account_id),
    FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Service Accounts ───────────────────────────────────────────────────────────
-- Bot/integration accounts that act on behalf of an integration
CREATE TABLE IF NOT EXISTS service_accounts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    description     TEXT NULL,
    avatar_color    VARCHAR(7) DEFAULT '#6366F1',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_space_active (space_id, is_active),
    FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FK for api_tokens.service_account_id
ALTER TABLE api_tokens
    ADD FOREIGN KEY (service_account_id) REFERENCES service_accounts(id) ON DELETE CASCADE;

-- ── Webhooks ───────────────────────────────────────────────────────────────────
-- Outgoing webhook endpoints registered by integrations
CREATE TABLE IF NOT EXISTS webhooks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    url             VARCHAR(2048) NOT NULL,
    secret          VARCHAR(255) NOT NULL,        -- HMAC-SHA256 signing secret
    events          JSON NOT NULL,                -- ["message.created","member.joined",...]
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    failure_count   INT UNSIGNED NOT NULL DEFAULT 0,
    disabled_at     DATETIME NULL,                -- auto-disabled after repeated failures
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_space_active (space_id, is_active),
    FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Webhook Delivery Log ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webhook_id      INT UNSIGNED NOT NULL,
    event_type      VARCHAR(80) NOT NULL,
    payload         JSON NOT NULL,
    request_headers JSON NULL,
    response_status INT NULL,
    response_body   TEXT NULL,
    attempt         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status          ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
    delivered_at    DATETIME NULL,
    next_retry_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_webhook_status (webhook_id, status),
    INDEX idx_retry (status, next_retry_at),
    FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Incoming Webhooks ──────────────────────────────────────────────────────────
-- Pre-configured endpoints for external services (GitHub, Jira, etc.)
CREATE TABLE IF NOT EXISTS incoming_webhooks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    channel_id      INT UNSIGNED NOT NULL,        -- Target channel for messages
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(64) NOT NULL,          -- URL slug: /api/v1/hooks/incoming/{slug}
    provider        ENUM('generic','github','jira','gitlab','custom') NOT NULL DEFAULT 'generic',
    secret          VARCHAR(255) NULL,             -- Verification secret
    avatar_color    VARCHAR(7) DEFAULT '#F59E0B',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_slug (slug),
    INDEX idx_space (space_id),
    FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ══════════════════════════════════════════════════════════════════════════════
-- Apply same schema to test database
-- ══════════════════════════════════════════════════════════════════════════════
USE cro_chat_test;

CREATE TABLE IF NOT EXISTS api_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NULL,
    service_account_id INT UNSIGNED NULL,
    name            VARCHAR(100) NOT NULL,
    token_hash      VARCHAR(255) NOT NULL,
    token_prefix    VARCHAR(12) NOT NULL,
    scopes          JSON NOT NULL,
    last_used_at    DATETIME NULL,
    expires_at      DATETIME NULL,
    revoked_at      DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_hash (token_hash),
    INDEX idx_space_user (space_id, user_id),
    INDEX idx_space_sa (space_id, service_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_accounts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    description     TEXT NULL,
    avatar_color    VARCHAR(7) DEFAULT '#6366F1',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_space_active (space_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhooks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    url             VARCHAR(2048) NOT NULL,
    secret          VARCHAR(255) NOT NULL,
    events          JSON NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    failure_count   INT UNSIGNED NOT NULL DEFAULT 0,
    disabled_at     DATETIME NULL,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_space_active (space_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webhook_id      INT UNSIGNED NOT NULL,
    event_type      VARCHAR(80) NOT NULL,
    payload         JSON NOT NULL,
    request_headers JSON NULL,
    response_status INT NULL,
    response_body   TEXT NULL,
    attempt         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status          ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
    delivered_at    DATETIME NULL,
    next_retry_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_webhook_status (webhook_id, status),
    INDEX idx_retry (status, next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS incoming_webhooks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id        INT UNSIGNED NOT NULL,
    channel_id      INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(64) NOT NULL,
    provider        ENUM('generic','github','jira','gitlab','custom') NOT NULL DEFAULT 'generic',
    secret          VARCHAR(255) NULL,
    avatar_color    VARCHAR(7) DEFAULT '#F59E0B',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_slug (slug),
    INDEX idx_space (space_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
