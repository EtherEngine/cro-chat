-- Analytics: Event-Tracking, Aggregation und Dashboards
-- Trennt Produkt-Events (user actions) von System-Events (jobs, errors)

USE cro_chat;

-- ── Product Events (user-level actions, privacy-aware) ──────────────────────

CREATE TABLE IF NOT EXISTS analytics_events (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id      INT UNSIGNED     NOT NULL,
  user_hash     VARCHAR(64)      NOT NULL COMMENT 'SHA-256 of user_id+daily_salt for privacy',
  event_type    VARCHAR(60)      NOT NULL COMMENT 'e.g. message.sent, search.executed, notification.clicked',
  event_category VARCHAR(30)     NOT NULL DEFAULT 'product' COMMENT 'product | system',
  channel_id    INT UNSIGNED     NULL,
  metadata      JSON             DEFAULT NULL COMMENT 'Extra context (response_time_ms, search_query_length, etc.)',
  created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_events_space_type (space_id, event_type, created_at),
  INDEX idx_events_space_cat  (space_id, event_category, created_at),
  INDEX idx_events_created    (created_at),
  INDEX idx_events_user_hash  (user_hash, created_at),
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Pre-aggregated daily metrics (materialized by aggregation job) ──────────

CREATE TABLE IF NOT EXISTS analytics_daily (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id      INT UNSIGNED     NOT NULL,
  metric_date   DATE             NOT NULL,
  metric_name   VARCHAR(60)      NOT NULL COMMENT 'dau, wau, mau, messages_sent, channels_active, ...',
  metric_value  DECIMAL(14, 2)   NOT NULL DEFAULT 0,
  breakdown     JSON             DEFAULT NULL COMMENT 'Optional breakdown by channel/type',
  created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_daily_metric (space_id, metric_date, metric_name),
  INDEX idx_daily_space_date (space_id, metric_date),
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── System events (non-user, infrastructure) ────────────────────────────────

CREATE TABLE IF NOT EXISTS analytics_system_events (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id      INT UNSIGNED     NULL,
  event_type    VARCHAR(60)      NOT NULL COMMENT 'job.completed, job.failed, api.error, api.slow_query',
  severity      ENUM('info','warning','error') NOT NULL DEFAULT 'info',
  metadata      JSON             DEFAULT NULL,
  created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sys_events_type   (event_type, created_at),
  INDEX idx_sys_events_sev    (severity, created_at),
  INDEX idx_sys_events_space  (space_id, created_at)
) ENGINE=InnoDB;


-- ══════════════════════════════════════════════════════════════════════════════
-- Mirror for test database
-- ══════════════════════════════════════════════════════════════════════════════

USE cro_chat_test;

CREATE TABLE IF NOT EXISTS analytics_events (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id      INT UNSIGNED     NOT NULL,
  user_hash     VARCHAR(64)      NOT NULL,
  event_type    VARCHAR(60)      NOT NULL,
  event_category VARCHAR(30)     NOT NULL DEFAULT 'product',
  channel_id    INT UNSIGNED     NULL,
  metadata      JSON             DEFAULT NULL,
  created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_events_space_type (space_id, event_type, created_at),
  INDEX idx_events_space_cat  (space_id, event_category, created_at),
  INDEX idx_events_created    (created_at),
  INDEX idx_events_user_hash  (user_hash, created_at),
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS analytics_daily (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id      INT UNSIGNED     NOT NULL,
  metric_date   DATE             NOT NULL,
  metric_name   VARCHAR(60)      NOT NULL,
  metric_value  DECIMAL(14, 2)   NOT NULL DEFAULT 0,
  breakdown     JSON             DEFAULT NULL,
  created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_daily_metric (space_id, metric_date, metric_name),
  INDEX idx_daily_space_date (space_id, metric_date),
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS analytics_system_events (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id      INT UNSIGNED     NULL,
  event_type    VARCHAR(60)      NOT NULL,
  severity      ENUM('info','warning','error') NOT NULL DEFAULT 'info',
  metadata      JSON             DEFAULT NULL,
  created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sys_events_type   (event_type, created_at),
  INDEX idx_sys_events_sev    (severity, created_at),
  INDEX idx_sys_events_space  (space_id, created_at)
) ENGINE=InnoDB;
