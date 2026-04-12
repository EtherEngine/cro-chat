-- Migration: Call History – structured call messages in conversation timeline
-- Adds type + call_id to messages so call events appear inline with chat messages.

ALTER TABLE messages
  ADD COLUMN type    ENUM('text','call') NOT NULL DEFAULT 'text' AFTER body,
  ADD COLUMN call_id BIGINT UNSIGNED DEFAULT NULL AFTER type,
  ADD INDEX idx_call_id (call_id),
  ADD INDEX idx_type (conversation_id, type, id),
  ADD CONSTRAINT fk_messages_call FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE SET NULL;

-- Relax body NOT NULL for system messages (call events store JSON, but body may be NULL for future types)
ALTER TABLE messages MODIFY COLUMN body TEXT NULL;
