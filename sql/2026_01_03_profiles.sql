-- 2026_01_03_profiles.sql
CREATE TABLE IF NOT EXISTS profiles (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_user_id BIGINT(20) UNSIGNED NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'band',
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_owner (owner_user_id),
  UNIQUE KEY uniq_owner_slug (owner_user_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
