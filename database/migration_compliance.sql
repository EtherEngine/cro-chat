-- Compliance & Data Management Migration
-- Retention policies, soft-delete tracking, export/anonymization support

USE cro_chat;

-- ── Retention Policies ─────────────────────────────────────────────────────────
-- Space-scoped configurable retention rules
CREATE TABLE IF NOT EXISTS retention_policies (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id        INT UNSIGNED NOT NULL,
  target          VARCHAR(30)  NOT NULL,  -- messages, attachments, notifications, events, jobs, moderation_log
  retention_days  INT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = keep forever
  hard_delete     TINYINT(1)   NOT NULL DEFAULT 0,  -- 0 = soft-delete only, 1 = hard-delete after retention
  enabled         TINYINT(1)   NOT NULL DEFAULT 1,
  created_by      INT UNSIGNED NOT NULL,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_space_target (space_id, target),
  FOREIGN KEY (space_id)   REFERENCES spaces(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Data Export Requests ──────────────────────────────────────────────────────
-- Tracks GDPR-style data export requests
CREATE TABLE IF NOT EXISTS data_export_requests (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  space_id     INT UNSIGNED NOT NULL,
  status       ENUM('pending','processing','ready','expired','failed') NOT NULL DEFAULT 'pending',
  file_path    VARCHAR(500) DEFAULT NULL,       -- path to generated export archive
  file_size    INT UNSIGNED DEFAULT NULL,
  requested_by INT UNSIGNED NOT NULL,           -- admin or user themselves
  completed_at TIMESTAMP    NULL DEFAULT NULL,
  expires_at   TIMESTAMP    NULL DEFAULT NULL,  -- auto-purge after 72h
  error        TEXT         DEFAULT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_user_exports (user_id, created_at),
  INDEX idx_space_exports (space_id, status),
  INDEX idx_expires (expires_at),
  FOREIGN KEY (user_id)      REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (space_id)     REFERENCES spaces(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by) REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Account Deletion Requests ──────────────────────────────────────────────────
-- Tracks account deletion/anonymization with grace period
CREATE TABLE IF NOT EXISTS account_deletion_requests (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  space_id        INT UNSIGNED NOT NULL,
  action          ENUM('anonymize','delete') NOT NULL DEFAULT 'anonymize',
  status          ENUM('pending','grace_period','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
  reason          TEXT         DEFAULT NULL,
  requested_by    INT UNSIGNED NOT NULL,  -- admin or user themselves
  grace_end_at    TIMESTAMP    NULL DEFAULT NULL,  -- user can cancel until this time
  completed_at    TIMESTAMP    NULL DEFAULT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_user_deletion (user_id, status),
  INDEX idx_space_deletion (space_id, status),
  INDEX idx_grace_end (grace_end_at, status),
  FOREIGN KEY (user_id)      REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (space_id)     REFERENCES spaces(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by) REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Compliance Audit Log ───────────────────────────────────────────────────────
-- Immutable log of all compliance-relevant actions
CREATE TABLE IF NOT EXISTS compliance_log (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id     INT UNSIGNED NOT NULL,
  action       VARCHAR(50)  NOT NULL,  -- retention.apply, export.request, export.complete, account.anonymize, account.delete, policy.update
  actor_id     INT UNSIGNED NOT NULL,
  target_user_id INT UNSIGNED DEFAULT NULL,
  details      JSON         DEFAULT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_space_log (space_id, created_at),
  INDEX idx_actor (actor_id, created_at),
  INDEX idx_target (target_user_id, created_at),
  FOREIGN KEY (space_id)        REFERENCES spaces(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id)        REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (target_user_id)  REFERENCES users(id)  ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Add anonymized_at to users table ──────────────────────────────────────────
ALTER TABLE users ADD COLUMN IF NOT EXISTS anonymized_at TIMESTAMP NULL DEFAULT NULL;
