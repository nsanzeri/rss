<?php
require_once __DIR__ . "/../core/bootstrap.php";

// Public "This Week" listings page
$error = '';
$rows = [];

try {
	$pdo = db();
	$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
	$endUtc = $nowUtc->modify('+7 days');

	$sql = "
    SELECT
      ce.start_utc,
      ce.end_utc,
      ce.title,
      ce.notes,
      p.id AS profile_id,
      p.profile_type,
      p.name,
      COALESCE(p.city, z.city) AS city,
      COALESCE(p.state, z.state) AS state,
      p.zip
    FROM calendar_events ce
    JOIN user_calendars uc ON uc.id = ce.calendar_id AND uc.is_default = 1
    JOIN profiles p ON p.user_id = uc.user_id AND p.is_active = 1
    LEFT JOIN zipcodes z ON z.zip = p.zip
    WHERE ce.status = 'busy'
      AND ce.start_utc >= :start_utc
      AND ce.start_utc < :end_utc
    ORDER BY ce.start_utc ASC
    LIMIT 80
  ";

	$stmt = $pdo->prepare($sql);
	$stmt->execute([
			':start_utc' => $nowUtc->format('Y-m-d H:i:s'),
			':end_utc' => $endUtc->format('Y-m-d H:i:s'),
	]);
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
	$error = $e->getMessage();
}

$tzLocal = new DateTimeZone('America/Chicago');
$fmt = function($utc) use ($tzLocal) {
	if (!$utc) return '';
	try {
		$dt = new DateTimeImmutable((string)$utc, new DateTimeZone('UTC'));
		$dt = $dt->setTimezone($tzLocal);
		return $dt->format('D, M j • g:ia');
	} catch (Throwable $e) {
		return '';
	}
};

$title = "This Week — Ready Set Shows";
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($title) ?></title>
  <link rel="stylesheet" href="<?= h(BASE_URL) ?>/assets/css/app.css" />
  <style>
    .week-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;}
    .week-head h1{margin:0;font-size:22px;letter-spacing:-0.02em;}
    .week-grid{display:grid;grid-template-columns:repeat(3, 1fr);gap:12px;margin-top:14px;}
    @media (max-width: 980px){.week-grid{grid-template-columns:repeat(2, 1fr);}}
    @media (max-width: 640px){.week-grid{grid-template-columns:1fr;}}
    .wcard{border:1px solid rgba(15,23,42,0.12);border-radius:18px;background:rgba(255,255,255,0.90);}
    .wbody{padding:14px;}
    .wtop{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;}
    .wtitle{font-weight:700;letter-spacing:-0.01em;margin:0;}
    .wmeta{color:rgba(15,23,42,0.70);font-size:13px;line-height:1.35;margin-top:6px;}
    .wtag{display:inline-flex;align-items:center;gap:6px;border:1px solid rgba(15,23,42,0.12);border-radius:999px;padding:6px 10px;font-size:12px;color:rgba(15,23,42,0.85);}
    .wtag.band{background:rgba(99,102,241,0.10);}
    .wtag.venue{background:rgba(16,185,129,0.10);}
    .wlink{display:inline-flex;margin-top:12px;}
  </style>
</head>
<body class="public">
  <header class="site-header">
    <div class="brandbar">
      <div class="brandbar-inner">
        <a href="<?= h(BASE_URL) ?>/index.php" class="brand">
          <span class="logo-square">RSS</span>
          <span class="brand-name">Ready Set Shows</span>
        </a>
        <nav class="top-actions">
          <a class="pill" href="<?= h(BASE_URL) ?>/search.php">Search</a>
          <a class="pill" href="<?= h(BASE_URL) ?>/pricing.php">Pricing</a>
          <a class="pill" href="<?= h(BASE_URL) ?>/login.php">Log In</a>
        </nav>
      </div>
    </div>
  </header>

  <main class="container" style="max-width: 1160px; margin: 18px auto; padding: 0 16px;">

    <div class="week-head">
      <div>
        <h1>What’s happening this week</h1>
        <div class="muted">Pulled from public calendars (artist/venue controlled)</div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="pill" href="<?= h(BASE_URL) ?>/index.php">Back to home</a>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="alert" style="margin-top:14px;">Listings error: <?= h($error) ?></div>
    <?php elseif (empty($rows)): ?>
      <div class="card" style="margin-top:14px;border-radius:18px;">
        <div class="card-body" style="padding:16px;">
          <div style="font-weight:700;">Nothing public on the calendar yet.</div>
          <div class="muted" style="margin-top:6px;">Tip: mark a calendar as "public" (Make Default) and import events.</div>
        </div>
      </div>
    <?php else: ?>
      <div class="week-grid">
        <?php foreach ($rows as $r): ?>
          <?php
            $ptype = (string)($r['profile_type'] ?? 'band');
            $tagClass = ($ptype === 'venue') ? 'venue' : 'band';
            $loc = trim(((string)($r['city'] ?? '')) . ", " . ((string)($r['state'] ?? '')) . " " . ((string)($r['zip'] ?? '')));
          ?>
          <div class="wcard">
            <div class="wbody">
              <div class="wtop">
                <div>
                  <div class="wtag <?= h($tagClass) ?>"><?= h(ucfirst($tagClass)) ?></div>
                </div>
                <div class="muted" style="font-size:12px;white-space:nowrap;">
                  <?= h($fmt($r['start_utc'] ?? null)) ?>
                </div>
              </div>

              <h3 class="wtitle" style="margin-top:10px;">
                <?= h($r['title'] ?: 'Live music') ?>
              </h3>

              <div class="wmeta">
                <div><b><?= h($r['name'] ?? '') ?></b></div>
                <div><?= h($loc) ?></div>
              </div>

              <a class="wlink pill" href="<?= h(BASE_URL) ?>/profile.php?id=<?= (int)$r['profile_id'] ?>">View profile →</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </main>
</body>
</html>
