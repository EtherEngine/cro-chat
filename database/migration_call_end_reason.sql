-- Migration: Constrain calls.end_reason to known enum values.
--
-- The existing end_reason values used in production are:
--   hangup          → call.hangup()
--   timeout         → call.markMissed() via reaper
--   rejected        → call.reject()
--   caller_cancelled → call.cancel()
--   network_error   → call.markFailed() default
--   ice_failed      → call.markFailed('ice_failed') from frontend
--   busy            → reserved for future use (caller/callee busy)
--
-- Run AFTER migration_calls.sql.

ALTER TABLE calls
  MODIFY COLUMN end_reason
    ENUM('hangup','timeout','network_error','ice_failed','rejected','caller_cancelled','busy')
    NULL DEFAULT NULL;
