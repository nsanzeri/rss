<?php
require_once __DIR__ . '/_layout.php';
$HIDE_NAV = empty($_SESSION["user_id"]);
page_header('search');

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
      AND EXISTS (
        SELECT 1 FROM user_calendars uc
        WHERE uc.user_id = p.user_id AND uc.is_default = 1
      )
    LIMIT 1
  ");
	$stmt->execute([$id]);
	$profile = $stmt->fetch();
	if (!$profile) {
		$error = "That profile wasn’t found.";
	}
	
	
	// Load media for public display
	$photos = [];
	$featured_videos = [];
	$reviews = [];
	$upcoming_shows = [];
	$avg_rating = null;
	$review_count = 0;
	
	if ($profile) {
		try {
			$stmt = $pdo->prepare("SELECT id, file_path, is_primary FROM profile_photos WHERE profile_id=? ORDER BY is_primary DESC, created_at DESC");
			$stmt->execute([(int)$profile['id']]);
			$photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
			
			$stmt = $pdo->prepare("SELECT id, youtube_id, title, url FROM profile_youtube_links WHERE profile_id=? AND is_featured=1 ORDER BY created_at DESC");
			$stmt->execute([(int)$profile['id']]);
			$featured_videos = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (Throwable $e) {
			// ignore if tables aren't installed yet
		}
		
		try {
			$stmt = $pdo->prepare("SELECT rating, comment, reviewer_name, created_at FROM reviews WHERE reviewed_profile_id=? AND is_approved=1 ORDER BY created_at DESC LIMIT 25");
			$stmt->execute([(int)$profile['id']]);
			$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
			
			$stmt = $pdo->prepare("SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM reviews WHERE reviewed_profile_id=? AND is_approved=1");
			$stmt->execute([(int)$profile['id']]);
			$agg = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($agg) {
				$avg_rating = $agg['avg_rating'] !== null ? round((float)$agg['avg_rating'], 1) : null;
				$review_count = (int)$agg['cnt'];
			}
		} catch (Throwable $e) {
			// reviews optional
		}

		// Upcoming public shows (from the public calendar)
		try {
			$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$endUtc = $nowUtc->modify('+180 days');
			$stmt = $pdo->prepare("
        SELECT ce.start_utc, ce.title
        FROM calendar_events ce
        JOIN user_calendars uc ON uc.id = ce.calendar_id
        WHERE uc.user_id = ? AND uc.is_default = 1
          AND ce.status = 'busy'
          AND ce.start_utc >= ? AND ce.start_utc < ?
        ORDER BY ce.start_utc ASC
        LIMIT 10
      ");
			$stmt->execute([
				(int)$profile['user_id'],
				$nowUtc->format('Y-m-d H:i:s'),
				$endUtc->format('Y-m-d H:i:s'),
			]);
			$upcoming_shows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (Throwable $e) {
			// optional
		}
	}
	
} catch (Throwable $e) {
	$error = "Profile error: " . $e->getMessage();
	//  $error = "Profile isn’t available yet (database not connected).";
}

$title = $profile ? ($profile['name'] ?? 'Profile') : "Profile";
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($title) ?> — Ready Set Shows</title>
  <link rel="stylesheet" href="<?= h(BASE_URL) ?>/assets/css/app.css" />
<style>
  .gallery{display:grid;grid-template-columns:2fr 1fr;gap:10px;margin-bottom:16px;}
  .gallery .primary{position:relative;border-radius:16px;overflow:hidden;min-height:320px;background:rgba(0,0,0,.08);}
  .gallery .primary img{width:100%;height:100%;object-fit:cover;display:block;}
  .gallery .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
  .gallery .thumb{border-radius:16px;overflow:hidden;min-height:155px;background:rgba(0,0,0,.08);}
  .gallery .thumb img{width:100%;height:100%;object-fit:cover;display:block;}
  @media (max-width: 860px){
    .gallery{grid-template-columns:1fr;}
    .gallery .primary{min-height:260px;}
    .gallery .thumb{min-height:130px;}
  }
  .rating-line{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:6px;}
  .stars{letter-spacing:1px;}
  .section{margin-top:18px;}
  .section h3{margin:0 0 10px;}
  .review{border-top:1px solid rgba(0,0,0,.08);padding-top:12px;margin-top:12px;}
</style>
</head>
<body class="public">


  <!--main class="container" style="max-width: 980px; margin: 22px auto; padding: 0 16px;"-->
<main>
    <?php if ($error): ?>
      <div class="alert"><?= h($error) ?></div>
    <?php else: ?>
      <div class="card" style="border-radius: 18px;">
        <div class="card-body" style="padding: 18px;">

<?php if (!empty($photos)): ?>
  <?php
    $p0 = $photos[0];
    $primarySrc = h(BASE_URL) . "/" . ltrim((string)$p0['file_path'], "/");
    $thumbs = array_slice($photos, 1, 4);
  ?>
  <div class="gallery">
    <div class="primary">
      <img src="<?= h($primarySrc) ?>" alt="Primary photo">
    </div>
    <div class="grid">
      <?php foreach ($thumbs as $t): ?>
        <?php $tsrc = h(BASE_URL) . "/" . ltrim((string)$t['file_path'], "/"); ?>
        <div class="thumb"><img src="<?= h($tsrc) ?>" alt="Photo"></div>
      <?php endforeach; ?>
      <?php if (count($thumbs) < 4): ?>
        <?php for ($i = count($thumbs); $i < 4; $i++): ?>
          <div class="thumb"></div>
        <?php endfor; ?>
      <?php endif; ?>
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
              ?>
              <a class="pill" href="<?= h(BASE_URL) ?>/search.php?where=<?= rawurlencode($where ?: ($profile['zip'] ?? '')) ?>&radius=25&type=all">Search nearby</a>
              <?php $t = ((($profile['profile_type'] ?? '') === 'venue') ? 'venue' : 'artist'); ?>
              <a class="pill" href="<?= h(BASE_URL) ?>/request.php?target_profile_id=<?= (int)($profile['id'] ?? 0) ?>&target_type=<?= h($t) ?>">Request booking</a>
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

          <?php if (!empty($upcoming_shows)): ?>
            <?php
              $tzLocal = new DateTimeZone('America/Chicago');
              $fmtShow = function($utc) use ($tzLocal) {
                if (!$utc) return '';
                try {
                  $dt = new DateTimeImmutable((string)$utc, new DateTimeZone('UTC'));
                  $dt = $dt->setTimezone($tzLocal);
                  return $dt->format('D, M j • g:ia');
                } catch (Throwable $e) { return ''; }
              };
            ?>
            <div style="margin-top: 16px; border:1px solid rgba(15,23,42,0.10); border-radius:16px; padding: 12px; background: rgba(255,255,255,0.70);">
              <div style="font-weight:700;">Upcoming shows</div>
              <div style="margin-top:8px; display:grid; gap:8px;">
                <?php foreach ($upcoming_shows as $s): ?>
                  <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <div style="color: rgba(15,23,42,0.80);"><b><?= h($fmtShow($s['start_utc'] ?? null)) ?></b></div>
                    <div style="color: rgba(15,23,42,0.78); flex: 1; text-align:right; min-width: 220px;">
                      <?= h($s['title'] ?: 'Live music') ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="muted" style="margin-top:10px; font-size:12px;">From the public calendar for this profile.</div>
            </div>
          <?php endif; ?>

          <hr style="margin: 16px 0; border: none; border-top: 1px solid rgba(15,23,42,0.10);" />

          
<div class="section">
  <h3>Videos</h3>
  <?php if (!empty($featured_videos)): ?>
    <?php foreach ($featured_videos as $v): ?>
      <div style="margin-bottom:14px;">
        <div style="font-weight:600;margin-bottom:8px;"><?= h($v['title'] ?: 'Featured Video') ?></div>
        <div style="position:relative;padding-top:56.25%;border-radius:16px;overflow:hidden;border:1px solid rgba(15,23,42,0.10);">
          <iframe
            src="https://www.youtube-nocookie.com/embed/<?= h($v['youtube_id']) ?>"
            title="YouTube video"
            style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            allowfullscreen></iframe>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div style="color: rgba(15,23,42,0.72);">No featured videos yet.</div>
  <?php endif; ?>
</div>

<div class="section">
  <h3>Booking details</h3>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
    <div style="border:1px solid rgba(15,23,42,0.10);border-radius:14px;padding:12px;">
      <div style="font-weight:600;margin-bottom:6px;">Payment terms</div>
      <div style="color: rgba(15,23,42,0.78);line-height:1.4;">
        Payment terms (deposit / payment methods / timing) are worked out directly with the artist.
        Most gigs are paid in full the night of the performance.
      </div>
    </div>
    <div style="border:1px solid rgba(15,23,42,0.10);border-radius:14px;padding:12px;">
      <div style="font-weight:600;margin-bottom:6px;">Typical response time</div>
      <div style="color: rgba(15,23,42,0.78);line-height:1.4;">
        Usually responds within <strong>24 hours</strong> to booking requests.
      </div>
    </div>
  </div>
</div>

<div class="section">
  <h3>Reviews</h3>

  <?php if ($avg_rating !== null): ?>
    <div class="rating-line">
      <div class="stars" aria-label="rating"><?= str_repeat("★", (int)round($avg_rating)) . str_repeat("☆", max(0, 5 - (int)round($avg_rating))) ?></div>
      <div style="font-weight:600;"><?= h(number_format($avg_rating, 1)) ?>/5</div>
      <div style="color: rgba(15,23,42,0.72);">(<?= (int)$review_count ?>)</div>
    </div>
  <?php endif; ?>

  <?php if (!empty($reviews)): ?>
    <?php foreach ($reviews as $r): ?>
      <div class="review">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">
          <div style="font-weight:600;"><?= h($r['reviewer_name'] ?: 'Anonymous') ?></div>
          <div style="color: rgba(15,23,42,0.60);font-size:12px;"><?= h(date('M j, Y', strtotime($r['created_at']))) ?></div>
        </div>
        <div class="stars" style="margin-top:6px;"><?= str_repeat("★", (int)$r['rating']) . str_repeat("☆", max(0, 5 - (int)$r['rating'])) ?></div>
        <?php if (!empty($r['comment'])): ?>
          <div style="margin-top:8px;line-height:1.4;color: rgba(15,23,42,0.80);"><?= nl2br(h($r['comment'])) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div style="color: rgba(15,23,42,0.72);margin-top:8px;">No reviews yet.</div>
  <?php endif; ?>
</div>

<div style="color: rgba(15,23,42,0.70); font-size: 13px; margin-top:18px;">
  Discovery v1: profiles are seed data. Next step is connecting profiles to real availability + booking flows.
</div>

        </div>
      </div>
    <?php endif; ?>
  </main>

  <?= geonames_attribution_html() ?>
</body>
</html>
