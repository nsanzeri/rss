<?php
require_once __DIR__ . "/../core/bootstrap.php";

// Public profile page (Discovery v1)
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
	header("Location: " . BASE_URL . "/index.php");
	exit;
}

$profile = null;
$error = '';

try {
	$pdo = db();
	$stmt = $pdo->prepare("
    SELECT
      p.*,
      z.lat AS zip_lat,
      z.lng AS zip_lng,
      z.city AS zip_city,
      z.state AS zip_state
    FROM profiles p
    LEFT JOIN zipcodes z ON z.zip = p.zip
    WHERE p.id = ? AND p.is_active = 1
    LIMIT 1
  ");
	$stmt->execute([$id]);
	$profile = $stmt->fetch();
	if (!$profile) {
		$error = "That profile wasn’t found.";
	}
	
	// Media
	$photos = [];
	$featured_videos = [];
	try {
		$st = $pdo->prepare("SELECT * FROM profile_photos WHERE profile_id=? ORDER BY is_primary DESC, sort_order ASC, id ASC");
		$st->execute([$id]);
		$photos = $st->fetchAll() ?: [];
	} catch (Throwable $e) { $photos = []; }
	
	try {
		$st = $pdo->prepare("SELECT * FROM profile_youtube_links WHERE profile_id=? AND is_featured=1 ORDER BY sort_order ASC, id ASC");
		$st->execute([$id]);
		$featured_videos = $st->fetchAll() ?: [];
	} catch (Throwable $e) { $featured_videos = []; }
	
} catch (Throwable $e) {
	$error = "Profile error: " . $e->getMessage();
	//  $error = "Profile isn’t available yet (database not connected).";
}


function youtube_embed_url(?string $ytid): ?string {
	if (!$ytid) return null;
	return "https://www.youtube.com/embed/" . $ytid;
}

$title = $profile ? ($profile['name'] ?? 'Profile') : "Profile";
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($title) ?> — Ready Set Shows</title>
  <link rel="stylesheet" href="<?= h(BASE_URL) ?>/assets/css/app.css" />
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
          <a class="pill" href="<?= h(BASE_URL) ?>/index.php">Search</a>
          <a class="pill" href="<?= h(BASE_URL) ?>/login.php">Log In</a>
        </nav>
      </div>
    </div>
  </header>

  <main class="container" style="max-width: 980px; margin: 22px auto; padding: 0 16px;">
    <?php if ($error): ?>
      <div class="alert"><?= h($error) ?></div>
    <?php else: ?>
      <div class="card" style="border-radius: 18px;">
        <div class="card-body" style="padding: 18px;">

          <?php
            $primary = null;
            foreach ($photos as $ph) { if ((int)$ph['is_primary'] === 1) { $primary = $ph; break; } }
            if (!$primary && !empty($photos)) $primary = $photos[0];
          ?>
          <?php if ($primary): ?>
            <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
              <img src="<?= h(BASE_URL) ?>/<?= h($primary['file_path']) ?>"
                   alt="<?= h($profile['name'] ?? 'Profile') ?>"
                   style="width:120px;height:120px;border-radius:18px;object-fit:cover;border:1px solid rgba(15,23,42,0.12);" />
              <div>
                <div style="font-size:12px;color:rgba(15,23,42,0.65);">Photos: <?= (int)count($photos) ?></div>
              </div>
            </div>
          <?php endif; ?>


          <div style="display:flex; justify-content:space-between; gap: 12px; flex-wrap:wrap; align-items:flex-start;">
            <div>
              <h1 style="margin:0; font-size: 22px; letter-spacing: -0.02em;"><?= h($profile['name'] ?? '') ?></h1>
              <div style="margin-top: 6px; color: rgba(15,23,42,0.72);">
                <span class="badge badge-<?= h($profile['profile_type'] ?? '') ?>"><?= ucfirst(h($profile['profile_type'] ?? '')) ?></span>
                <span style="margin-left: 8px;">
                  <?= h(trim(($profile['city'] ?? $profile['zip_city'] ?? '') . ", " . ($profile['state'] ?? $profile['zip_state'] ?? '') . " " . ($profile['zip'] ?? ''))) ?>
                </span>
              </div>
              <?php if (!empty($profile['genres'])): ?>
                <div style="margin-top: 8px; color: rgba(15,23,42,0.78);"><?= h($profile['genres']) ?></div>
              <?php endif; ?>
            </div>

            <div style="display:flex; gap: 8px; flex-wrap:wrap;">
              <?php
                $when = trim($_GET['when'] ?? '');
                $where = trim($_GET['where'] ?? '');
                $subject = rawurlencode("Booking inquiry: " . ($profile['name'] ?? ''));
                $body = rawurlencode(
                  "Hi!\n\nI'm interested in " . ($profile['profile_type'] ?? 'your') . " listing: " . ($profile['name'] ?? '') . ".\n\n"
                  . "Date: " . ($when ?: "TBD") . "\n"
                  . "Location: " . ($where ?: "TBD") . "\n\n"
                  . "Thanks!"
                );
              ?>
              <a class="pill" href="<?= h(BASE_URL) ?>/search.php?where=<?= rawurlencode($where ?: ($profile['zip'] ?? '')) ?>&radius=25&type=all">Search nearby</a>
              <a class="pill" href="mailto:?subject=<?= $subject ?>&body=<?= $body ?>">Request booking</a>
              <?php if (!empty($profile['website'])): ?>
                <a class="pill" href="<?= h($profile['website']) ?>" target="_blank" rel="noopener">Website</a>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!empty($profile['bio'])): ?>
            <div style="margin-top: 14px; line-height: 1.45; color: rgba(15,23,42,0.82);">
              <?= nl2br(h($profile['bio'])) ?>
            </div>
          <?php else: ?>
            <div style="margin-top: 14px; color: rgba(15,23,42,0.72);">
              No bio yet.
            </div>
          <?php endif; ?>


          <?php if (!empty($featured_videos)): ?>
            <div style="margin-top: 14px;">
              <h3 style="margin:0 0 10px 0;">Videos</h3>
              <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:12px;">
                <?php foreach ($featured_videos as $v): ?>
                  <div style="border:1px solid rgba(15,23,42,0.10);border-radius:14px;padding:12px;">
                    <div style="font-weight:700;margin-bottom:8px;"><?= h($v['title'] ?: 'YouTube') ?></div>
                    <?php if (!empty($v['youtube_id'])): ?>
                      <div style="aspect-ratio:16/9;">
                        <iframe width="100%" height="100%"
                          src="https://www.youtube.com/embed/<?= h($v['youtube_id']) ?>"
                          title="YouTube video player"
                          frameborder="0"
                          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                          allowfullscreen></iframe>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <hr style="margin: 16px 0; border: none; border-top: 1px solid rgba(15,23,42,0.10);" />

          <div style="color: rgba(15,23,42,0.70); font-size: 13px;">
            Discovery v1: profiles are seed data + zip-based radius search. Next step is connecting profiles to real availability + booking flows.
          </div>
        </div>
      </div>
    <?php endif; ?>
  </main>

  <?= geonames_attribution_html() ?>
</body>
</html>
