-- Migration: Push Subscriptions & Device Registrations
-- Supports Web Push (VAPID), desktop (Tauri) and future mobile (FCM/APNs)

-- ── Push subscriptions (one per device per user) ──────────────────────────
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    space_id    INT UNSIGNED NOT NULL,
    
    -- Device identification
    device_id   VARCHAR(255) NOT NULL COMMENT 'Client-generated UUID, stable per device',
    device_name VARCHAR(255) DEFAULT NULL COMMENT 'User-friendly name (e.g. "Chrome on Windows")',
    platform    ENUM('web', 'desktop', 'android', 'ios') NOT NULL DEFAULT 'web',
    
    -- Web Push (VAPID) subscription data
    endpoint    TEXT DEFAULT NULL COMMENT 'Push service endpoint URL',
    p256dh_key  TEXT DEFAULT NULL COMMENT 'Client public key (Base64)',
    auth_key    VARCHAR(255) DEFAULT NULL COMMENT 'Auth secret (Base64)',
    
    -- For FCM/APNs (future)
    push_token  TEXT DEFAULT NULL COMMENT 'FCM/APNs token',
    
    -- State
    active      TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at DATETIME DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- One subscription per device per user per space
    UNIQUE KEY uq_user_device_space (user_id, device_id, space_id),
    INDEX idx_push_user_active (user_id, active),
    INDEX idx_push_space (space_id),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Push delivery log (for reliable delivery tracking) ────────────────────
CREATE TABLE IF NOT EXISTS push_delivery_log (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id     INT UNSIGNED NOT NULL,
    notification_id     INT UNSIGNED DEFAULT NULL,
    status              ENUM('sent', 'delivered', 'failed', 'expired') NOT NULL DEFAULT 'sent',
    error               TEXT DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_push_delivery_sub (subscription_id),
    INDEX idx_push_delivery_notification (notification_id),
    INDEX idx_push_delivery_status (status, created_at),
    
    FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Offline sync cursors (track last-seen event per device) ───────────────
CREATE TABLE IF NOT EXISTS sync_cursors (
    user_id     INT UNSIGNED NOT NULL,
    device_id   VARCHAR(255) NOT NULL,
    space_id    INT UNSIGNED NOT NULL,
    
    -- Last event ID successfully processed by this device
    last_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    
    -- Last sync timestamp
    synced_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (user_id, device_id, space_id),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── VAPID key storage (one pair per space) ────────────────────────────────
CREATE TABLE IF NOT EXISTS vapid_keys (
    space_id    INT UNSIGNED NOT NULL PRIMARY KEY,
    public_key  TEXT NOT NULL,
    private_key TEXT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
