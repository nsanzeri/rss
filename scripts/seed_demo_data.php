<?php
// Run: php scripts/seed_demo_data.php
// Creates realistic demo data for local/dev testing.

require_once __DIR__ . "/../core/bootstrap.php";

function rand_public_key(): string {
	return bin2hex(random_bytes(16));
}

function insert_user(PDO $pdo, string $email, string $displayName): int {
	$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
	$stmt->execute([$email]);
	$existing = $stmt->fetchColumn();
	if ($existing) return (int)$existing;

	$pk = rand_public_key();
	$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
	$stmt = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, timezone, subscription_tier, public_key, created_at, updated_at) VALUES (?, NULL, ?, 'America/Chicago', 'free', ?, ?, ?)");
	$stmt->execute([$email, $displayName, $pk, $now, $now]);
	return (int)$pdo->lastInsertId();
}

function insert_profile(PDO $pdo, int $userId, string $type, string $name, string $city, string $state, string $zip, string $genres, string $bio, string $website = null): int {
	$stmt = $pdo->prepare("SELECT id FROM profiles WHERE user_id = ? LIMIT 1");
	$stmt->execute([$userId]);
	$existing = $stmt->fetchColumn();
	if ($existing) return (int)$existing;

	$stmt = $pdo->prepare("INSERT INTO profiles (user_id, profile_type, name, city, state, zip, genres, bio, website, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
	$stmt->execute([$userId, $type, $name, $city, $state, $zip, $genres, $bio, $website]);
	return (int)$pdo->lastInsertId();
}

function insert_public_calendar(PDO $pdo, int $userId, string $name, string $color = '#7c3aed'): int {
	$stmt = $pdo->prepare("SELECT id FROM user_calendars WHERE user_id = ? AND is_default = 1 LIMIT 1");
	$stmt->execute([$userId]);
	$existing = $stmt->fetchColumn();
	if ($existing) return (int)$existing;

	$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
	$stmt = $pdo->prepare("INSERT INTO user_calendars (user_id, calendar_name, calendar_color, description, source_type, source_url, is_default, created_at) VALUES (?, ?, ?, 'Public calendar', 'manual', NULL, 1, ?)");
	$stmt->execute([$userId, $name, $color, $now]);
	return (int)$pdo->lastInsertId();
}

function add_event_local(PDO $pdo, int $calendarId, DateTimeImmutable $localStart, int $minutes, string $title, string $notes = ''): void {
	$localTz = $localStart->getTimezone();
	$utcStart = $localStart->setTimezone(new DateTimeZone('UTC'));
	$utcEnd = $localStart->modify('+' . $minutes . ' minutes')->setTimezone(new DateTimeZone('UTC'));
	$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

	$stmt = $pdo->prepare("INSERT INTO calendar_events (calendar_id, external_uid, external_etag, title, notes, status, is_all_day, start_utc, end_utc, source, created_at) VALUES (?, NULL, NULL, ?, ?, 'busy', 0, ?, ?, 'manual', ?)");
	$stmt->execute([
		$calendarId,
		$title,
		$notes,
		$utcStart->format('Y-m-d H:i:s'),
		$utcEnd->format('Y-m-d H:i:s'),
		$now,
	]);
}

// -------------------------

$pdo = db();

// If we've already seeded once, bail.
$already = $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'demo_%@rss.local'")->fetchColumn();
if ((int)$already > 0) {
	echo "Demo seed data already present (" . (int)$already . " users).\n";
	exit(0);
}

$pdo->beginTransaction();

try {
	$localTz = new DateTimeZone('America/Chicago');
	$today = new DateTimeImmutable('today', $localTz);

	$bands = [
		[
			'email' => 'demo_band1@rss.local',
			'display' => 'The Neon Suburbs',
			'profile_type' => 'band',
			'city' => 'Chicago',
			'state' => 'IL',
			'zip' => '60657',
			'genres' => 'Indie Rock • Synth Pop',
			'bio' => 'A tight, dancey indie set with hooks you can sing on the first listen.',
		],
		[
			'email' => 'demo_band2@rss.local',
			'display' => 'Lake Effect Trio',
			'profile_type' => 'band',
			'city' => 'Evanston',
			'state' => 'IL',
			'zip' => '60201',
			'genres' => 'Jazz • Soul',
			'bio' => 'Smooth, modern jazz with a soulful edge. Perfect for dinners and lounges.',
		],
		[
			'email' => 'demo_band3@rss.local',
			'display' => 'Liberty Funk Circus',
			'profile_type' => 'band',
			'city' => 'Arlington Heights',
			'state' => 'IL',
			'zip' => '60004',
			'genres' => 'Funk • Classic Rock',
			'bio' => 'High-energy groove band. Big choruses. Big smiles. Zero dead air.',
		],
		[
			'email' => 'demo_band4@rss.local',
			'display' => 'Midnight Cover Co.',
			'profile_type' => 'band',
			'city' => 'Naperville',
			'state' => 'IL',
			'zip' => '60540',
			'genres' => 'Pop • Country • Rock',
			'bio' => 'A smart, crowd-friendly set that works for bars, patios, and private events.',
		],
	];

	$venues = [
		[
			'email' => 'demo_venue1@rss.local',
			'display' => 'Harbor Brewing Co.',
			'profile_type' => 'venue',
			'city' => 'Lake Villa',
			'state' => 'IL',
			'zip' => '60046',
			'genres' => 'Brewpub • Live Music',
			'bio' => 'Craft beer + patio shows. Good sound. Easy load-in.',
		],
		[
			'email' => 'demo_venue2@rss.local',
			'display' => 'Palace Bowl',
			'profile_type' => 'venue',
			'city' => 'Johnsburg',
			'state' => 'IL',
			'zip' => '60051',
			'genres' => 'Bowling • Bar • Stage',
			'bio' => 'A classic room with a stage setup that surprises people (in a good way).',
		],
		[
			'email' => 'demo_venue3@rss.local',
			'display' => 'Moretti\'s Morton Grove',
			'profile_type' => 'venue',
			'city' => 'Morton Grove',
			'state' => 'IL',
			'zip' => '60053',
			'genres' => 'Restaurant • Live Music',
			'bio' => 'Food-first, music-friendly. Great for duo/solo and light bands.',
		],
	];

	$all = array_merge($bands, $venues);

	foreach ($all as $entity) {
		$uid = insert_user($pdo, $entity['email'], $entity['display']);
		insert_profile(
			$pdo,
			$uid,
			$entity['profile_type'],
			$entity['display'],
			$entity['city'],
			$entity['state'],
			$entity['zip'],
			$entity['genres'],
			$entity['bio']
		);
		$calId = insert_public_calendar($pdo, $uid, $entity['display'] . " — Public", '#7c3aed');

		// Add 2 events in the next 7 days
		$dayOffset1 = random_int(0, 6);
		$dayOffset2 = random_int(0, 6);
		$h1 = random_int(6, 9); // 6pm-9pm
		$h2 = random_int(6, 9);
		$start1 = $today->modify("+{$dayOffset1} days")->setTime($h1, 0);
		$start2 = $today->modify("+{$dayOffset2} days")->setTime($h2, 0);

		add_event_local($pdo, $calId, $start1, 180, $entity['display'] . " — Live");
		add_event_local($pdo, $calId, $start2, 180, $entity['display'] . " — Live");
	}

	$pdo->commit();
	echo "Seeded demo data: " . count($all) . " profiles + public calendars + events.\n";
	echo "Tip: visit /public/index.php and /public/listings.php to see the public feed.\n";
} catch (Throwable $e) {
	$pdo->rollBack();
	fwrite(STDERR, "Seed failed: " . $e->getMessage() . "\n");
	exit(1);
}
