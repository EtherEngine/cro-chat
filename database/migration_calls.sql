-- Migration: 1:1 Audio Calls for Direct Conversations
-- WebRTC signaling metadata, call history & per-participant sessions

USE cro_chat;

-- ── Calls ──────────────────────────────────────────────────────────────────────
-- One row per call attempt.  Scoped to a 1:1 conversation (is_group = 0).
--
-- State machine
-- ─────────────
--   initiated → ringing  | failed
--   ringing   → accepted | rejected | missed | failed
--   accepted  → ended    | failed
--
-- Terminal states: rejected, missed, ended, failed
CREATE TABLE IF NOT EXISTS calls (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id   INT UNSIGNED    NOT NULL,
  caller_user_id    INT UNSIGNED    NOT NULL,
  callee_user_id    INT UNSIGNED    NOT NULL,

  status            ENUM('initiated','ringing','accepted','rejected','ended','missed','failed')
                        NOT NULL DEFAULT 'initiated',

  started_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP  COMMENT 'When call was initiated',
  answered_at       TIMESTAMP       NULL DEFAULT NULL                   COMMENT 'When callee accepted',
  ended_at          TIMESTAMP       NULL DEFAULT NULL                   COMMENT 'When call ended / was rejected / timed out',
  duration_seconds  INT UNSIGNED    NULL DEFAULT NULL                   COMMENT 'Computed: ended_at - answered_at',
  end_reason        VARCHAR(50)     NULL DEFAULT NULL                   COMMENT 'hangup, timeout, network_error, rejected, caller_cancelled, oow',

  created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- FK: conversation must exist and be a 1:1 DM
  CONSTRAINT fk_calls_conversation FOREIGN KEY (conversation_id)  REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_calls_caller      FOREIGN KEY (caller_user_id)    REFERENCES users(id)         ON DELETE CASCADE,
  CONSTRAINT fk_calls_callee      FOREIGN KEY (callee_user_id)    REFERENCES users(id)         ON DELETE CASCADE,

  INDEX idx_calls_conversation    (conversation_id, created_at DESC),
  INDEX idx_calls_caller          (caller_user_id, status),
  INDEX idx_calls_callee          (callee_user_id, status),
  INDEX idx_calls_active          (status, created_at)              COMMENT 'Fast lookup of initiated/ringing/accepted calls'
) ENGINE=InnoDB;

-- ── Call Sessions (per-participant WebRTC session) ──────────────────────────
-- Tracks each participant's connection lifecycle inside a call.
-- Useful for analytics, quality metrics, and future group-call extension.
CREATE TABLE IF NOT EXISTS call_sessions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  call_id       BIGINT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED    NOT NULL,
  role          ENUM('caller','callee') NOT NULL,

  joined_at     TIMESTAMP       NULL DEFAULT NULL  COMMENT 'WebRTC peer connected',
  left_at       TIMESTAMP       NULL DEFAULT NULL  COMMENT 'WebRTC peer disconnected / hung up',
  muted         TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Current mute state (last known)',
  ice_state     VARCHAR(30)     NULL DEFAULT NULL  COMMENT 'Last ICE connection state: new, checking, connected, failed, …',

  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_cs_call FOREIGN KEY (call_id) REFERENCES calls(id)  ON DELETE CASCADE,
  CONSTRAINT fk_cs_user FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE CASCADE,

  UNIQUE INDEX uq_call_user (call_id, user_id),
  INDEX idx_cs_user (user_id, created_at DESC)
) ENGINE=InnoDB;

-- ── ICE Candidates (ephemeral, signaling relay) ─────────────────────────────
-- Stored briefly for trickle-ICE when the peer is not yet subscribed to WS room.
-- Rows are auto-purged after 60 s by the application layer.
CREATE TABLE IF NOT EXISTS call_ice_candidates (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  call_id     BIGINT UNSIGNED NOT NULL,
  sender_id   INT UNSIGNED    NOT NULL,
  candidate   JSON            NOT NULL  COMMENT 'RTCIceCandidateInit JSON',
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_ice_call   FOREIGN KEY (call_id)   REFERENCES calls(id) ON DELETE CASCADE,
  CONSTRAINT fk_ice_sender FOREIGN KEY (sender_id)  REFERENCES users(id) ON DELETE CASCADE,

  INDEX idx_ice_call (call_id, created_at)
) ENGINE=InnoDB;
