-- Ready Set Shows (Ops) — Metrics/Events table
-- Run this once in your database (phpMyAdmin > SQL tab) to enable tracking.

CREATE TABLE IF NOT EXISTS `events` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT(20) UNSIGNED NULL,
  `event_name` VARCHAR(64) NOT NULL,
  `path` VARCHAR(255) NULL,
  `meta_json` LONGTEXT NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_events_user_created` (`user_id`, `created_at`),
  KEY `idx_events_name_created` (`event_name`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notes:
-- 1) No foreign keys yet (keeps early development friction-free).
-- 2) meta_json is LONGTEXT for maximum compatibility across MySQL versions.
