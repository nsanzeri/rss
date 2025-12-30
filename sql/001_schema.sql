-- Ready Set Shows — Ops (v1)
-- MySQL 8 / utf8mb4
-- Create database first:
--   CREATE DATABASE readysetshows_ops CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Then run this file.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NULL,
  display_name VARCHAR(120) NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Chicago',
  public_key VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_public_key (public_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_oauth_identities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,              -- 'google'
  provider_user_id VARCHAR(190) NOT NULL,     -- Google sub
  email_at_provider VARCHAR(190) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_user (provider, provider_user_id),
  UNIQUE KEY uq_user_provider (user_id, provider),
  CONSTRAINT fk_uoi_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_oauth_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,              -- 'google'
  access_token TEXT NOT NULL,
  refresh_token TEXT NULL,
  token_type VARCHAR(32) NULL,
  scope TEXT NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_provider_tokens (user_id, provider),
  CONSTRAINT fk_uot_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_secrets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  namespace VARCHAR(64) NOT NULL,     -- 'oauth'
  secret_key VARCHAR(128) NOT NULL,   -- 'google_client_id', 'google_client_secret'
  secret_value TEXT NOT NULL,
  is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_secrets (namespace, secret_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_calendars (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  calendar_name VARCHAR(120) NOT NULL,
  calendar_color VARCHAR(16) NOT NULL DEFAULT '#3b82f6',
  description VARCHAR(255) NULL,
  source_type ENUM('manual','ics','google') NOT NULL DEFAULT 'manual',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_calendars_user (user_id),
  CONSTRAINT fk_user_calendars_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_imports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  calendar_id BIGINT UNSIGNED NOT NULL,
  source_url TEXT NULL,
  last_synced_at DATETIME NULL,
  last_http_status INT NULL,
  last_error VARCHAR(255) NULL,
  etag VARCHAR(255) NULL,
  last_modified VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_calendar_import (calendar_id),
  CONSTRAINT fk_calendar_imports_calendar
    FOREIGN KEY (calendar_id) REFERENCES user_calendars(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  calendar_id BIGINT UNSIGNED NOT NULL,
  external_uid VARCHAR(255) NULL,
  external_etag VARCHAR(255) NULL,
  title VARCHAR(255) NULL,
  notes TEXT NULL,
  status ENUM('busy','available','tentative') NOT NULL DEFAULT 'busy',
  is_all_day TINYINT(1) NOT NULL DEFAULT 0,
  start_utc DATETIME NOT NULL,
  end_utc DATETIME NOT NULL,
  source ENUM('manual','ics','google') NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_events_calendar_start (calendar_id, start_utc),
  KEY idx_events_uid (external_uid),
  CONSTRAINT fk_calendar_events_calendar
    FOREIGN KEY (calendar_id) REFERENCES user_calendars(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



