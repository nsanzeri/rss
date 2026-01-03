-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Dec 30, 2025 at 06:22 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `readysetshows`
--
CREATE DATABASE IF NOT EXISTS `readysetshows` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `readysetshows`;

-- --------------------------------------------------------

--
-- Table structure for table `app_secrets`
--

DROP TABLE IF EXISTS `app_secrets`;
CREATE TABLE `app_secrets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `namespace` varchar(64) NOT NULL,
  `secret_key` varchar(128) NOT NULL,
  `secret_value` text NOT NULL,
  `is_encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calendar_events`
--

DROP TABLE IF EXISTS `calendar_events`;
CREATE TABLE `calendar_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `calendar_id` bigint(20) UNSIGNED NOT NULL,
  `external_uid` varchar(255) DEFAULT NULL,
  `external_etag` varchar(255) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('busy','available','tentative') NOT NULL DEFAULT 'busy',
  `is_all_day` tinyint(1) NOT NULL DEFAULT 0,
  `start_utc` datetime NOT NULL,
  `end_utc` datetime NOT NULL,
  `source` enum('manual','ics','google') NOT NULL DEFAULT 'manual',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calendar_imports`
--

DROP TABLE IF EXISTS `calendar_imports`;
CREATE TABLE `calendar_imports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `calendar_id` bigint(20) UNSIGNED NOT NULL,
  `source_url` text DEFAULT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  `last_http_status` int(11) DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `etag` varchar(255) DEFAULT NULL,
  `last_modified` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_changes`
--

DROP TABLE IF EXISTS `email_changes`;
CREATE TABLE `email_changes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `new_email` varchar(190) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `request_ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `request_ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `display_name` varchar(120) DEFAULT NULL,
  `timezone` varchar(64) NOT NULL DEFAULT 'America/Chicago',
  `public_key` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_calendars`
--

DROP TABLE IF EXISTS `user_calendars`;
CREATE TABLE `user_calendars` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `calendar_name` varchar(120) NOT NULL,
  `calendar_color` varchar(16) NOT NULL DEFAULT '#3b82f6',
  `description` varchar(255) DEFAULT NULL,
  `source_type` enum('manual','ics','google') NOT NULL DEFAULT 'manual',
  `source_url` text DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_oauth_identities`
--

DROP TABLE IF EXISTS `user_oauth_identities`;
CREATE TABLE `user_oauth_identities` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `provider` varchar(32) NOT NULL,
  `provider_user_id` varchar(190) NOT NULL,
  `email_at_provider` varchar(190) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_oauth_tokens`
--

DROP TABLE IF EXISTS `user_oauth_tokens`;
CREATE TABLE `user_oauth_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `provider` varchar(32) NOT NULL,
  `access_token` text NOT NULL,
  `refresh_token` text DEFAULT NULL,
  `token_type` varchar(32) DEFAULT NULL,
  `scope` text DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `app_secrets`
--
ALTER TABLE `app_secrets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_app_secrets` (`namespace`,`secret_key`);

--
-- Indexes for table `calendar_events`
--
ALTER TABLE `calendar_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_events_calendar_start` (`calendar_id`,`start_utc`),
  ADD KEY `idx_events_uid` (`external_uid`);

--
-- Indexes for table `calendar_imports`
--
ALTER TABLE `calendar_imports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_calendar_import` (`calendar_id`);

--
-- Indexes for table `email_changes`
--
ALTER TABLE `email_changes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `token_hash` (`token_hash`),
  ADD KEY `expires_at` (`expires_at`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `token_hash` (`token_hash`),
  ADD KEY `expires_at` (`expires_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD UNIQUE KEY `uq_users_public_key` (`public_key`);

--
-- Indexes for table `user_calendars`
--
ALTER TABLE `user_calendars`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_calendars_user` (`user_id`);

--
-- Indexes for table `user_oauth_identities`
--
ALTER TABLE `user_oauth_identities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_provider_user` (`provider`,`provider_user_id`),
  ADD UNIQUE KEY `uq_user_provider` (`user_id`,`provider`);

--
-- Indexes for table `user_oauth_tokens`
--
ALTER TABLE `user_oauth_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_provider_tokens` (`user_id`,`provider`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `app_secrets`
--
ALTER TABLE `app_secrets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calendar_events`
--
ALTER TABLE `calendar_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calendar_imports`
--
ALTER TABLE `calendar_imports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_changes`
--
ALTER TABLE `email_changes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_calendars`
--
ALTER TABLE `user_calendars`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_oauth_identities`
--
ALTER TABLE `user_oauth_identities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_oauth_tokens`
--
ALTER TABLE `user_oauth_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `calendar_events`
--
ALTER TABLE `calendar_events`
  ADD CONSTRAINT `fk_calendar_events_calendar` FOREIGN KEY (`calendar_id`) REFERENCES `user_calendars` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `calendar_imports`
--
ALTER TABLE `calendar_imports`
  ADD CONSTRAINT `fk_calendar_imports_calendar` FOREIGN KEY (`calendar_id`) REFERENCES `user_calendars` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `email_changes`
--
ALTER TABLE `email_changes`
  ADD CONSTRAINT `fk_ec_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_calendars`
--
ALTER TABLE `user_calendars`
  ADD CONSTRAINT `fk_user_calendars_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_oauth_identities`
--
ALTER TABLE `user_oauth_identities`
  ADD CONSTRAINT `fk_uoi_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_oauth_tokens`
--
ALTER TABLE `user_oauth_tokens`
  ADD CONSTRAINT `fk_uot_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

DROP TABLE IF EXISTS `subscription_limits`;
CREATE TABLE subscription_limits (
  tier VARCHAR(32) NOT NULL PRIMARY KEY,   -- free, solo, team, venue, pro
  display_name VARCHAR(64) NOT NULL,       -- Backstage Pass, Headliner Solo, etc

  users_per_account INT NOT NULL DEFAULT 1,
  calendars_total INT NOT NULL DEFAULT 1,
  calendar_import_sources INT NOT NULL DEFAULT 0,
  public_links INT NOT NULL DEFAULT 1,

  entities_total INT NOT NULL DEFAULT 1,   -- total managed org objects
  venues_managed INT NOT NULL DEFAULT 0,
  bands_managed INT NOT NULL DEFAULT 0,

  staff_delegation TINYINT(1) NOT NULL DEFAULT 0,  -- can invite staff to manage?
  roles_enabled TINYINT(1) NOT NULL DEFAULT 0,     -- admin/member etc
  embeds_enabled TINYINT(1) NOT NULL DEFAULT 0,    -- embed calendars
  reporting_level INT NOT NULL DEFAULT 0,          -- 0 none, 1 basic, 2 advanced
  priority_placement TINYINT(1) NOT NULL DEFAULT 0,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO subscription_limits
(tier, display_name, users_per_account, calendars_total, calendar_import_sources, public_links, entities_total, venues_managed, bands_managed, staff_delegation, roles_enabled, embeds_enabled, reporting_level, priority_placement)
VALUES
('free',  'Backstage Pass', 1, 1, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0),
('solo',  'Headliner Solo', 1, 3, 2, 3, 2, 0, 1, 0, 0, 0, 1, 0),
('team',  'Headliner Crew', 6, 8, 4, 8, 4, 0, 3, 1, 1, 0, 1, 0),
('venue', 'House Booker',   10, 20, 6, 20, 10, 5, 2, 1, 1, 1, 1, 0),
('pro',   'Circuit Pro',    50, 200, 20, 200, 100, 50, 50, 1, 1, 1, 2, 1);

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

-- Ready Set Shows (Discovery v1)
-- Zip-based radius search seed data
-- Import into your DB once (phpMyAdmin → Import), then the landing page search will work.

-- ZIP coordinates (small Chicagoland seed)
CREATE TABLE IF NOT EXISTS zipcodes (
  zip VARCHAR(10) NOT NULL PRIMARY KEY,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  city VARCHAR(120) NULL,
  state VARCHAR(2) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Public discovery directory entries: bands + venues
CREATE TABLE IF NOT EXISTS profiles (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  profile_type ENUM('band','venue') NOT NULL,
  name VARCHAR(190) NOT NULL,
  city VARCHAR(120) NULL,
  state VARCHAR(2) NULL,
  zip VARCHAR(10) NOT NULL,
  genres VARCHAR(255) NULL,
  bio TEXT NULL,
  website VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_profiles_zip (zip),
  KEY idx_profiles_type (profile_type),
  CONSTRAINT fk_profiles_zip FOREIGN KEY (zip) REFERENCES zipcodes(zip)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upsert ZIP rows
INSERT INTO zipcodes (zip, lat, lng, city, state) VALUES
('60614', 41.9227000, -87.6533000, 'Chicago', 'IL'),
('60657', 41.9397000, -87.6530000, 'Chicago', 'IL'),
('60611', 41.8949000, -87.6216000, 'Chicago', 'IL'),
('60601', 41.8864000, -87.6231000, 'Chicago', 'IL'),
('60640', 41.9718000, -87.6632000, 'Chicago', 'IL'),
('60010', 42.1526000, -88.1362000, 'Barrington', 'IL'),
('60067', 42.1140000, -88.0420000, 'Palatine', 'IL'),
('60193', 42.0110000, -88.0830000, 'Schaumburg', 'IL'),
('60007', 42.0080000, -87.9920000, 'Elk Grove Village', 'IL'),
('60089', 42.1662000, -87.9631000, 'Buffalo Grove', 'IL')
ON DUPLICATE KEY UPDATE
  lat=VALUES(lat), lng=VALUES(lng), city=VALUES(city), state=VALUES(state);

-- Seed discovery profiles (feel free to edit/replace)
INSERT INTO profiles (profile_type, name, city, state, zip, genres, bio, website, is_active) VALUES
('band', 'Nick Sanzeri (Solo)', 'Chicago', 'IL', '60614', 'Classic rock • Funk • Pop', 'Singing bassist bringing full-band energy in a solo setup. High value, high vibe.', 'https://nicksanzeri.com', 1),
('band', 'Hooked On Sonics', 'Chicago', 'IL', '60657', 'Rock • Dance • Party', 'Live party set for bars, patios, private events.', NULL, 1),
('band', 'Libido Funk Circus', 'Chicago', 'IL', '60640', 'Funk • Soul • Groove', 'Modern funk with throwback swagger. Dance floor first.', NULL, 1),
('band', 'Sir Gigz-a-lot Showcase', 'Chicago', 'IL', '60611', 'Variety • Covers • Originals', 'Rotating roster of performers for venues and events.', NULL, 1),
('band', 'Northwest Suburbs Cover Kings', 'Palatine', 'IL', '60067', '90s • Classic rock • Pop', 'Crowd-pleasers and singalongs. Easy to book.', NULL, 1),

('venue', 'The Broken Oar', 'Port Barrington', 'IL', '60010', 'Waterfront • Live music', 'Patio vibes, summer shows, and river traffic. Great for party bands.', NULL, 1),
('venue', 'Reefpoint Brewhouse', 'Racine', 'WI', '60089', 'Brewpub • Live music', 'Food, beer, and live entertainment nights.', NULL, 1),
('venue', 'Moretti’s', 'Schaumburg', 'IL', '60193', 'Sports bar • Live music', 'High-energy nights with crowd favorites.', NULL, 1),
('venue', 'Main Street Bar & Grill', 'Barrington', 'IL', '60010', 'Neighborhood bar • Live music', 'Local hangout with rotating bands and duos.', NULL, 1),
('venue', 'Palace Bowl Lounge', 'Lake Zurich', 'IL', '60067', 'Bowling • Lounge • Live music', 'Late-night entertainment with built-in crowd.', NULL, 1);

-- Note:
-- If you already imported this once, re-running it may create duplicates in profiles.
-- You can TRUNCATE profiles and re-run if you want a clean slate.
