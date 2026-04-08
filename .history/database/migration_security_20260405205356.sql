-- Migration: Enterprise Security
-- SSO, MFA, Device Tracking, Session Management, Security Logging, Abuse Detection

-- ── SSO Identity Providers ───────────────────────────────────
CREATE TABLE IF NOT EXISTS sso_providers (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id        INT UNSIGNED NOT NULL,
  provider_type   ENUM('oidc','saml') NOT NULL,
  name            VARCHAR(100) NOT NULL,
  slug            VARCHAR(100) NOT NULL,
  client_id       VARCHAR(500) NOT NULL DEFAULT '',
  client_secret_enc VARCHAR(1000) NOT NULL DEFAULT '',
  issuer_url      VARCHAR(500) NOT NULL DEFAULT '',
  authorization_url VARCHAR(500) NOT NULL DEFAULT '',
  token_url       VARCHAR(500) NOT NULL DEFAULT '',
  userinfo_url    VARCHAR(500) NOT NULL DEFAULT '',
  jwks_url        VARCHAR(500) NOT NULL DEFAULT '',
  saml_idp_entity_id VARCHAR(500) NOT NULL DEFAULT '',
  saml_sso_url    VARCHAR(500) NOT NULL DEFAULT '',
  saml_certificate TEXT,
  scopes          VARCHAR(500) NOT NULL DEFAULT 'openid profile email',
  attribute_map   JSON DEFAULT NULL,
  auto_provision  TINYINT(1) NOT NULL DEFAULT 1,
  enforce_sso     TINYINT(1) NOT NULL DEFAULT 0,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_space_slug (space_id, slug),
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── SSO User Links ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sso_user_links (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  provider_id     INT UNSIGNED NOT NULL,
  external_id     VARCHAR(500) NOT NULL,
  external_email  VARCHAR(255) NOT NULL DEFAULT '',
  external_name   VARCHAR(255) NOT NULL DEFAULT '',
  access_token_enc VARCHAR(2000) DEFAULT NULL,
  refresh_token_enc VARCHAR(2000) DEFAULT NULL,
  token_expires_at TIMESTAMP NULL DEFAULT NULL,
  last_login_at   TIMESTAMP NULL DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_provider_external (provider_id, external_id),
  UNIQUE KEY uq_user_provider (user_id, provider_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (provider_id) REFERENCES sso_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── MFA (TOTP + Recovery Codes) ──────────────────────────────
CREATE TABLE IF NOT EXISTS user_mfa (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  method          ENUM('totp','webauthn') NOT NULL DEFAULT 'totp',
  secret_enc      VARCHAR(500) NOT NULL DEFAULT '',
  recovery_codes_enc TEXT,
  is_enabled      TINYINT(1) NOT NULL DEFAULT 0,
  verified_at     TIMESTAMP NULL DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_method (user_id, method),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Device Tracking ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_devices (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  device_hash     CHAR(64) NOT NULL,
  device_name     VARCHAR(255) NOT NULL DEFAULT 'Unknown',
  device_type     ENUM('desktop','mobile','tablet','unknown') NOT NULL DEFAULT 'unknown',
  browser         VARCHAR(100) NOT NULL DEFAULT '',
  os              VARCHAR(100) NOT NULL DEFAULT '',
  ip_address      VARCHAR(45) NOT NULL DEFAULT '',
  is_trusted      TINYINT(1) NOT NULL DEFAULT 0,
  last_active_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  first_seen_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_device_hash (user_id, device_hash),
  INDEX idx_user_active (user_id, last_active_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Session Management ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_sessions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  session_token   CHAR(64) NOT NULL UNIQUE,
  device_id       INT UNSIGNED DEFAULT NULL,
  ip_address      VARCHAR(45) NOT NULL DEFAULT '',
  user_agent      VARCHAR(500) NOT NULL DEFAULT '',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  mfa_verified    TINYINT(1) NOT NULL DEFAULT 0,
  expires_at      TIMESTAMP NOT NULL,
  last_activity_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_active_sess (user_id, is_active),
  INDEX idx_token (session_token),
  INDEX idx_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (device_id) REFERENCES user_devices(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Security Audit Log ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS security_log (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED DEFAULT NULL,
  event_type      VARCHAR(50) NOT NULL,
  severity        ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  ip_address      VARCHAR(45) NOT NULL DEFAULT '',
  user_agent      VARCHAR(500) NOT NULL DEFAULT '',
  details         JSON DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_events (user_id, event_type),
  INDEX idx_type_severity (event_type, severity),
  INDEX idx_created (created_at),
  INDEX idx_ip (ip_address)
) ENGINE=InnoDB;

-- ── Abuse Detection State ────────────────────────────────────
CREATE TABLE IF NOT EXISTS abuse_scores (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_type    ENUM('ip','user') NOT NULL,
  subject_key     VARCHAR(255) NOT NULL,
  score           INT NOT NULL DEFAULT 0,
  violations      JSON DEFAULT NULL,
  blocked_until   TIMESTAMP NULL DEFAULT NULL,
  last_violation_at TIMESTAMP NULL DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_subject (subject_type, subject_key),
  INDEX idx_blocked (blocked_until),
  INDEX idx_score (score)
) ENGINE=InnoDB;

-- ── Secrets Vault ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS secrets_vault (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  key_name        VARCHAR(100) NOT NULL UNIQUE,
  encrypted_value TEXT NOT NULL,
  version         INT UNSIGNED NOT NULL DEFAULT 1,
  rotated_at      TIMESTAMP NULL DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Add MFA fields to users ──────────────────────────────────
ALTER TABLE users ADD COLUMN mfa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER last_seen_at;
ALTER TABLE users ADD COLUMN sso_only TINYINT(1) NOT NULL DEFAULT 0 AFTER mfa_enabled;
