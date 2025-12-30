-- Settings page supporting schema changes (soft delete + email change verification)
-- Run these in your database (MariaDB/MySQL)

-- 1) Soft delete support
ALTER TABLE users
  ADD COLUMN deleted_at DATETIME NULL AFTER updated_at;

-- (Recommended hardening)
ALTER TABLE users
  MODIFY timezone VARCHAR(64) NOT NULL DEFAULT 'America/Chicago';

-- 2) Email change verification table
CREATE TABLE email_changes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  new_email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  request_ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  INDEX(user_id),
  INDEX(token_hash),
  INDEX(expires_at),
  CONSTRAINT fk_ec_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Optional: enforce that only one active (unused) row per token hash is relevant via unique token_hash already indexed; token uniqueness depends on randomness.
-- Note: enforcing uniqueness on new_email while used_at is NULL requires generated columns/partial index, which MySQL doesn't support directly. We'll enforce in code.
