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
