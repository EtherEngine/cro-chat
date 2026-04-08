CREATE TABLE IF NOT EXISTS pinned_messages (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id      BIGINT UNSIGNED NOT NULL,
  channel_id      INT UNSIGNED    DEFAULT NULL,
  conversation_id INT UNSIGNED    DEFAULT NULL,
  pinned_by       INT UNSIGNED    NOT NULL,
  created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pinned_message (message_id),
  INDEX idx_channel_pins (channel_id, created_at),
  INDEX idx_conversation_pins (conversation_id, created_at),
  CONSTRAINT fk_pin_message      FOREIGN KEY (message_id)      REFERENCES messages(id)      ON DELETE CASCADE,
  CONSTRAINT fk_pin_channel      FOREIGN KEY (channel_id)      REFERENCES channels(id)      ON DELETE CASCADE,
  CONSTRAINT fk_pin_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_pin_user         FOREIGN KEY (pinned_by)       REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS saved_messages (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED    NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_saved_user_message (user_id, message_id),
  INDEX idx_user_saved (user_id, created_at),
  CONSTRAINT fk_saved_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_saved_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB;
