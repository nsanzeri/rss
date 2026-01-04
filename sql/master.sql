-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jan 03, 2026 at 11:40 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

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

--
-- Dumping data for table `app_secrets`
--

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

--
-- Dumping data for table `calendar_imports`
--

INSERT INTO `calendar_imports` VALUES(22, 17, 'https://calendar.google.com/calendar/ical/d14ahc6pg4djqn91jsv2j8nut4%40group.calendar.google.com/public/basic.ics', NULL, NULL, NULL, NULL, NULL, '2025-12-28 10:49:05', NULL);
INSERT INTO `calendar_imports` VALUES(23, 18, 'https://calendar.google.com/calendar/ical/pjjfdgelvdjtuvrr89tun3nu7k%40group.calendar.google.com/public/basic.ics', '2025-12-30 22:12:07', 200, NULL, NULL, NULL, '2025-12-28 12:04:19', '2025-12-30 15:12:07');

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
-- Table structure for table `events`
--

DROP TABLE IF EXISTS `events`;
CREATE TABLE `events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `event_name` varchar(64) NOT NULL,
  `path` varchar(255) DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `events`
--

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

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` VALUES(1, 1, '6e0e9728345033f75d61cf20f1ec37f73e6a61377bc38fe9d4268d88ce2f8af0', '2025-12-30 18:20:04', '2025-12-30 10:20:35', '2025-12-30 10:20:04', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36');

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  profile_type VARCHAR(32) NOT NULL DEFAULT 'artist',
  name VARCHAR(190) NOT NULL,
  city VARCHAR(120) DEFAULT NULL,
  state VARCHAR(32) DEFAULT NULL,
  zip VARCHAR(16) DEFAULT NULL,
  genres VARCHAR(255) DEFAULT NULL,
  bio TEXT DEFAULT NULL,
  website VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_profiles_user (user_id),
  KEY idx_profiles_active (is_active),
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  profile_id BIGINT UNSIGNED DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'inquiry',
  event_title VARCHAR(190) NOT NULL,
  event_date DATE DEFAULT NULL,
  start_time TIME DEFAULT NULL,
  end_time TIME DEFAULT NULL,
  venue_name VARCHAR(190) DEFAULT NULL,
  venue_address VARCHAR(255) DEFAULT NULL,
  city VARCHAR(120) DEFAULT NULL,
  state VARCHAR(32) DEFAULT NULL,
  zip VARCHAR(16) DEFAULT NULL,
  contact_name VARCHAR(120) DEFAULT NULL,
  contact_email VARCHAR(190) DEFAULT NULL,
  contact_phone VARCHAR(64) DEFAULT NULL,
  fee DECIMAL(10,2) DEFAULT NULL,
  deposit DECIMAL(10,2) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_bookings_user (user_id),
  KEY idx_bookings_status (status),
  KEY idx_bookings_date (event_date),
  CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_bookings_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `profiles`
--

INSERT INTO `profiles` VALUES(1, 1, 'band', 'My Profile', 'my-profile', 1, '2026-01-03 21:10:17', '2026-01-03 21:10:17');

-- --------------------------------------------------------

--
-- Table structure for table `subscription_limits`
--

DROP TABLE IF EXISTS `subscription_limits`;
CREATE TABLE `subscription_limits` (
  `tier` varchar(32) NOT NULL,
  `display_name` varchar(64) NOT NULL,
  `users_per_account` int(11) NOT NULL DEFAULT 1,
  `calendars_total` int(11) NOT NULL DEFAULT 1,
  `calendar_import_sources` int(11) NOT NULL DEFAULT 0,
  `public_links` int(11) NOT NULL DEFAULT 1,
  `entities_total` int(11) NOT NULL DEFAULT 1,
  `venues_managed` int(11) NOT NULL DEFAULT 0,
  `bands_managed` int(11) NOT NULL DEFAULT 0,
  `staff_delegation` tinyint(1) NOT NULL DEFAULT 0,
  `roles_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `embeds_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `reporting_level` int(11) NOT NULL DEFAULT 0,
  `priority_placement` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subscription_limits`
--

INSERT INTO `subscription_limits` VALUES('free', 'Backstage Pass', 1, 1, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, '2025-12-30 11:54:56', '2025-12-30 11:54:56');
INSERT INTO `subscription_limits` VALUES('pro', 'Circuit Pro', 50, 200, 20, 200, 100, 50, 50, 1, 1, 1, 2, 1, '2025-12-30 11:54:56', '2025-12-30 11:54:56');
INSERT INTO `subscription_limits` VALUES('solo', 'Headliner Solo', 1, 3, 2, 3, 2, 0, 1, 0, 0, 0, 1, 0, '2025-12-30 11:54:56', '2025-12-30 11:54:56');
INSERT INTO `subscription_limits` VALUES('team', 'Headliner Crew', 6, 8, 4, 8, 4, 0, 3, 1, 1, 0, 1, 0, '2025-12-30 11:54:56', '2025-12-30 11:54:56');
INSERT INTO `subscription_limits` VALUES('venue', 'House Booker', 10, 20, 6, 20, 10, 5, 2, 1, 1, 1, 1, 0, '2025-12-30 11:54:56', '2025-12-30 11:54:56');

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
  `subscription_tier` varchar(32) NOT NULL DEFAULT 'free',
  `public_key` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
-- Table structure for table `zipcodes`
--

DROP TABLE IF EXISTS `zipcodes`;
CREATE TABLE `zipcodes` (
  `zip` char(5) NOT NULL,
  `city` varchar(128) DEFAULT NULL,
  `state` char(2) DEFAULT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--
-- Table structure for table `zip_lookup_cache`
--

DROP TABLE IF EXISTS `zip_lookup_cache`;
CREATE TABLE `zip_lookup_cache` (
  `zip` varchar(10) NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


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
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_events_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_events_name_created` (`event_name`,`created_at`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `token_hash` (`token_hash`),
  ADD KEY `expires_at` (`expires_at`);

--
-- Indexes for table `profiles`
--
ALTER TABLE `profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_owner_slug` (`owner_user_id`,`slug`),
  ADD KEY `idx_owner` (`owner_user_id`);

--
-- Indexes for table `subscription_limits`
--
ALTER TABLE `subscription_limits`
  ADD PRIMARY KEY (`tier`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD UNIQUE KEY `uq_users_public_key` (`public_key`),
  ADD KEY `idx_users_subscription_tier` (`subscription_tier`);

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
-- Indexes for table `zipcodes`
--
ALTER TABLE `zipcodes`
  ADD PRIMARY KEY (`zip`),
  ADD KEY `idx_lat` (`lat`),
  ADD KEY `idx_lng` (`lng`),
  ADD KEY `idx_zip_lat_lng` (`lat`,`lng`);

--
-- Indexes for table `zip_lookup_cache`
--
ALTER TABLE `zip_lookup_cache`
  ADD PRIMARY KEY (`zip`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `app_secrets`
--
ALTER TABLE `app_secrets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `calendar_events`
--
ALTER TABLE `calendar_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1609;

--
-- AUTO_INCREMENT for table `calendar_imports`
--
ALTER TABLE `calendar_imports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `email_changes`
--
ALTER TABLE `email_changes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1114;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `profiles`
--
ALTER TABLE `profiles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `user_calendars`
--
ALTER TABLE `user_calendars`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `user_oauth_identities`
--
ALTER TABLE `user_oauth_identities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_oauth_tokens`
--
ALTER TABLE `user_oauth_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

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
