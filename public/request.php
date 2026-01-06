<?php
require_once __DIR__ . "/../core/bootstrap.php";

// Public booking request (first pass)
// Flow:
// 1) /request.php (collect date/location/budget + contact)
// 2) /request_browse.php (browse artists/venues, add to shortlist)
// 3) /request_review.php (send invites)

$user = auth_user();
$pdo = db();

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function post_str(string $k): string { return trim((string)($_POST[$k] ?? '')); }

$prefill_target_id = (int)($_GET['target_profile_id'] ?? 0);
$prefill_target_type = trim((string)($_GET['target_type'] ?? 'artist'));
if (!in_array($prefill_target_type, ['artist','venue'], true)) $prefill_target_type = 'artist';

$error = '';
$flash = '';

// Draft defaults
$draft = $_SESSION['booking_request_draft'] ?? [
	'event_title' => '',
	'event_date' => '',
	'start_time' => '',
	'end_time' => '',
	'venue_name' => '',
	'venue_address' => '',
	'city' => '',
	'state' => '',
	'zip' => '',
	'budget_min' => '',
	'budget_max' => '',
	'notes' => '',
	'contact_name' => '',
	'contact_email' => '',
	'contact_phone' => '',
	'auto_fallback' => 0,
	'requester_profile_id' => 0,
	'requester_type' => $user ? 'user' : 'guest',
	'target_type' => ($prefill_target_id > 0 ? $prefill_target_type : 'artist'),
];

// Logged-in users can optionally choose a requester profile (artist or venue)
$myProfiles = [];
if ($user) {
	try {
		$stmt = $pdo->prepare("SELECT id, name, profile_type FROM profiles WHERE user_id=? AND deleted_at IS NULL ORDER BY created_at DESC");
		$stmt->execute([$user['id']]);
		$myProfiles = $stmt->fetchAll() ?: [];
	} catch (Throwable $e) {
		$myProfiles = [];
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	try {
		csrf_validate($_POST['csrf'] ?? '');
		$draft['target_type'] = post_str('target_type') ?: ($prefill_target_id > 0 ? $prefill_target_type : 'artist');
		if (!in_array($draft['target_type'], ['artist','venue'], true)) $draft['target_type'] = 'artist';
		$draft['event_title'] = post_str('event_title');
		$draft['event_date'] = post_str('event_date');
		$draft['start_time'] = post_str('start_time');
		$draft['end_time'] = post_str('end_time');
		$draft['venue_name'] = post_str('venue_name');
		$draft['venue_address'] = post_str('venue_address');
		$draft['city'] = post_str('city');
		$draft['state'] = post_str('state');
		$draft['zip'] = post_str('zip');
		$draft['budget_min'] = post_str('budget_min');
		$draft['budget_max'] = post_str('budget_max');
		$draft['notes'] = post_str('notes');
		$draft['auto_fallback'] = isset($_POST['auto_fallback']) ? 1 : 0;

		if ($user) {
			$draft['requester_type'] = post_str('requester_type') ?: 'user';
			if (!in_array($draft['requester_type'], ['user','artist','venue'], true)) $draft['requester_type'] = 'user';
			$draft['requester_profile_id'] = (int)($_POST['requester_profile_id'] ?? 0);
			if ($draft['requester_profile_id'] < 0) $draft['requester_profile_id'] = 0;
			// contact info optional when logged in
			$draft['contact_name'] = post_str('contact_name');
			$draft['contact_email'] = post_str('contact_email');
			$draft['contact_phone'] = post_str('contact_phone');
		} else {
			$draft['requester_type'] = 'guest';
			$draft['requester_profile_id'] = 0;
			$draft['contact_name'] = post_str('contact_name');
			$draft['contact_email'] = post_str('contact_email');
			$draft['contact_phone'] = post_str('contact_phone');
			if ($draft['contact_email'] === '') throw new Exception('Email is required so the artist can reply.');
		}

		if ($draft['event_date'] === '') throw new Exception('Event date is required.');
		if ($draft['venue_name'] === '' && $draft['venue_address'] === '' && $draft['city'] === '') {
			throw new Exception('Please add at least a venue name or city so artists understand where this is.');
		}

		$_SESSION['booking_request_draft'] = $draft;

		// Optional: if user clicked "Request" from a profile, prefill the shortlist with that single target.
		if ($prefill_target_id > 0) {
			if (!isset($_SESSION['booking_shortlist'])) {
				$_SESSION['booking_shortlist'] = ['artist'=>[], 'venue'=>[]];
			}
			$_SESSION['booking_request_draft']['target_type'] = $prefill_target_type;
			$_SESSION['booking_shortlist'][$prefill_target_type] = [$prefill_target_id];
			header('Location: ' . BASE_URL . '/request_review.php');
			exit;
		}

		header('Location: ' . BASE_URL . '/request_browse.php?type=' . urlencode($draft['target_type']));
		exit;
	} catch (Throwable $e) {
		$error = $e->getMessage();
	}
}

?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="csrf" content="<?= h2(csrf_token()) ?>" />
	<title>New Booking Request • <?= h2(APP_NAME) ?></title>
	<link rel="stylesheet" href="<?= h2(BASE_URL) ?>/assets/css/app.css" />
</head>
<body>
<div class="public-shell">
	<header class="public-header">
		<a class="public-brand" href="<?= h2(BASE_URL) ?>/index.php">Ready Set Shows</a>
		<nav class="public-nav">
			<a href="<?= h2(BASE_URL) ?>/search.php">Search</a>
			<a href="<?= h2(BASE_URL) ?>/listings.php">This Week</a>
			<?php if ($user): ?>
				<a href="<?= h2(BASE_URL) ?>/dashboard.php">Dashboard</a>
			<?php else: ?>
				<a href="<?= h2(BASE_URL) ?>/login.php">Login</a>
			<?php endif; ?>
		</nav>
	</header>

	<main class="public-content" style="max-width: 880px; margin: 0 auto; padding: 1.25rem;">
		<div class="card">
			<div class="card-body">
				<h1 style="margin-top:0;">Create a booking request</h1>
				<p class="muted" style="margin-top:-0.25rem;">Pick a date, add details, then choose one or more artists/venues to send it to.</p>

				<?php if ($error): ?>
					<div class="alert alert--error"><?= h2($error) ?></div>
				<?php endif; ?>

				<form method="post" class="form">
					<input type="hidden" name="csrf" value="<?= h2(csrf_token()) ?>" />

					<div class="form-grid">
					<div class="span-2">
						<label>I'm looking for</label>
						<select name="target_type">
							<option value="artist" <?= ($draft['target_type']==='artist'?'selected':'') ?>>Artists</option>
							<option value="venue" <?= ($draft['target_type']==='venue'?'selected':'') ?>>Venues</option>
						</select>
					</div>

						<div class="span-2">
							<label>Event title (optional)</label>
							<input type="text" name="event_title" value="<?= h2($draft['event_title']) ?>" placeholder="e.g., Patio music, private party" />
						</div>

						<div>
							<label>Date *</label>
							<input type="date" name="event_date" value="<?= h2($draft['event_date']) ?>" required />
						</div>
						<div>
							<label>Start time</label>
							<input type="time" name="start_time" value="<?= h2($draft['start_time']) ?>" />
						</div>
						<div>
							<label>End time</label>
							<input type="time" name="end_time" value="<?= h2($draft['end_time']) ?>" />
						</div>

						<div class="span-2">
							<label>Venue name</label>
							<input type="text" name="venue_name" value="<?= h2($draft['venue_name']) ?>" placeholder="e.g., Moretti's" />
						</div>
						<div class="span-2">
							<label>Address</label>
							<input type="text" name="venue_address" value="<?= h2($draft['venue_address']) ?>" placeholder="Street address (optional)" />
						</div>
						<div>
							<label>City</label>
							<input type="text" name="city" value="<?= h2($draft['city']) ?>" />
						</div>
						<div>
							<label>State</label>
							<input type="text" name="state" value="<?= h2($draft['state']) ?>" />
						</div>
						<div>
							<label>ZIP</label>
							<input type="text" name="zip" value="<?= h2($draft['zip']) ?>" maxlength="5" />
						</div>

						<div>
							<label>Budget min</label>
							<input type="number" step="0.01" name="budget_min" value="<?= h2($draft['budget_min']) ?>" placeholder="0" />
						</div>
						<div>
							<label>Budget max</label>
							<input type="number" step="0.01" name="budget_max" value="<?= h2($draft['budget_max']) ?>" placeholder="0" />
						</div>

						<div class="span-2">
							<label>Notes</label>
							<textarea name="notes" rows="4" placeholder="Vibe, set length, load-in notes, song requests, etc."><?= h2($draft['notes']) ?></textarea>
						</div>

						<div class="span-2" style="display:flex; gap:.75rem; align-items:center;">
							<label style="display:flex; gap:.5rem; align-items:center; margin:0;">
								<input type="checkbox" name="auto_fallback" <?= $draft['auto_fallback'] ? 'checked' : '' ?> />
								<span><strong>Auto-send to the next choice</strong> if the first artist declines.</span>
							</label>
						</div>

						<?php if ($user): ?>
							<div>
								<label>Requesting as</label>
								<select name="requester_type">
									<option value="user" <?= $draft['requester_type']==='user'?'selected':'' ?>>Me (user)</option>
									<option value="artist" <?= $draft['requester_type']==='artist'?'selected':'' ?>>Artist profile</option>
									<option value="venue" <?= $draft['requester_type']==='venue'?'selected':'' ?>>Venue profile</option>
								</select>
							</div>
							<div>
								<label>Profile (optional)</label>
								<select name="requester_profile_id">
									<option value="0">— none —</option>
									<?php foreach ($myProfiles as $p): ?>
										<option value="<?= (int)$p['id'] ?>" <?= ((int)$draft['requester_profile_id']===(int)$p['id'])?'selected':'' ?>><?= h2($p['name']) ?> (<?= h2($p['profile_type']) ?>)</option>
									<?php endforeach; ?>
								</select>
							</div>

							<div>
								<label>Contact name (optional)</label>
								<input type="text" name="contact_name" value="<?= h2($draft['contact_name']) ?>" />
							</div>
							<div>
								<label>Contact email (optional)</label>
								<input type="email" name="contact_email" value="<?= h2($draft['contact_email']) ?>" />
							</div>
							<div class="span-2">
								<label>Contact phone (optional)</label>
								<input type="text" name="contact_phone" value="<?= h2($draft['contact_phone']) ?>" />
							</div>
						<?php else: ?>
							<div>
								<label>Your name</label>
								<input type="text" name="contact_name" value="<?= h2($draft['contact_name']) ?>" />
							</div>
							<div>
								<label>Your email *</label>
								<input type="email" name="contact_email" value="<?= h2($draft['contact_email']) ?>" required />
							</div>
							<div class="span-2">
								<label>Your phone (optional)</label>
								<input type="text" name="contact_phone" value="<?= h2($draft['contact_phone']) ?>" />
							</div>
						<?php endif; ?>
					</div>

					<div style="display:flex; gap:.75rem; margin-top:1rem;">
						<button class="btn btn--primary" type="submit">Next: choose artists</button>
						<a class="btn" href="<?= h2(BASE_URL) ?>/search.php">Browse first</a>
					</div>
				</form>
			</div>
		</div>
	</main>
</div>
</body>
</html>
