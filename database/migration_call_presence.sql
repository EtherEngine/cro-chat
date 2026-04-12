-- ============================================================
-- Call-Aware Presence: Extend users.status with call states
-- ============================================================
-- Adds 'ringing', 'in_call', and 'dnd' to the presence ENUM.
-- These states are set/cleared by CallService and take priority
-- over the normal heartbeat-driven online/away cycle.
--
-- NOTE: ALTER COLUMN on an ENUM is safe: existing rows keep their
-- current value as long as the old values are preserved at the
-- same ordinal positions.
-- ============================================================

ALTER TABLE users
  MODIFY COLUMN status
    ENUM('online','away','offline','ringing','in_call','dnd')
    NOT NULL DEFAULT 'offline';
