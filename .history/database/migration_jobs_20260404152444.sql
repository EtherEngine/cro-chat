-- ── Migration: Job System ───────────────────────────────────────────────────────
-- Async job queue with status tracking, retry logic and pessimistic locking.

CREATE TABLE IF NOT EXISTS jobs (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  queue          VARCHAR(50)  NOT NULL DEFAULT 'default',
  type           VARCHAR(100) NOT NULL,
  payload        JSON         NOT NULL,
  status         ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 3,
  priority       TINYINT UNSIGNED NOT NULL DEFAULT 100,
  last_error     TEXT         DEFAULT NULL,
  idempotency_key VARCHAR(100) DEFAULT NULL,
  locked_by      VARCHAR(100) DEFAULT NULL,
  locked_at      TIMESTAMP    NULL DEFAULT NULL,
  available_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at     TIMESTAMP    NULL DEFAULT NULL,
  completed_at   TIMESTAMP    NULL DEFAULT NULL,

  UNIQUE KEY uq_idempotency (idempotency_key),
  INDEX idx_queue_status_available (queue, status, available_at, priority),
  INDEX idx_status_locked (status, locked_at),
  INDEX idx_type (type)
) ENGINE=InnoDB;

-- Add metadata column to attachments for post-processing data
ALTER TABLE attachments
  ADD COLUMN metadata JSON DEFAULT NULL AFTER file_size;
