<?php
require_once __DIR__ . '/_layout.php';
$HIDE_NAV = empty($_SESSION["user_id"]);
page_header('search');

// Casting request context (if user started a request and is now browsing)
$casting = $_SESSION['booking_request_draft'] ?? null;
$castingType = null;          // 'artist' | 'venue'
$castingProfileType = null;   // 'band' | 'venue'
$castingShortlistCount = 0;
$castingShortlistIds = [];
if (is_array($casting)) {
  $castingType = (($casting['target_type'] ?? 'artist') === 'venue') ? 'venue' : 'artist';
  $castingProfileType = ($castingType === 'venue') ? 'venue' : 'band';
  $short = $_SESSION['booking_shortlist'] ?? ['artist'=>[], 'venue'=>[]];
  $castingShortlistIds = array_values(array_unique(array_map('intval', $short[$castingType] ?? [])));
  $castingShortlistCount = (int)count($castingShortlistIds);
}

// Public search results page (Discovery v1)
$q = trim($_GET['q'] ?? '');
$where = trim($_GET['where'] ?? '');
$when = trim($_GET['when'] ?? '');
$radius = (int)($_GET['radius'] ?? 25);
$type = trim($_GET['type'] ?? 'all');

$allowed_radii = [0,5,10,25,50,100];
if (!in_array($radius, $allowed_radii, true)) { $radius = 25; }

$allowed_types = ['all','band','venue'];
if (!in_array($type, $allowed_types, true)) { $type = 'all'; }

$search_error = '';
$search_results = [];
$search_location_label = '';

// Search mode:
// - radius = 0 => "Anywhere" (artist-only search; ignores ZIP)
// - radius > 0 => geo search by ZIP + optional keyword filter
$mode_anywhere = ($radius === 0);

// Parse ZIP only when we are doing geo search
$zip = '';
if (!$mode_anywhere) {
	if ($where !== '' && preg_match('/(\d{5})/', $where, $mm)) {
		$zip = $mm[1];
	} elseif ($where !== '') {
		$search_error = "Enter a 5-digit ZIP code to search nearby (or choose Anywhere).";
	} else {
		// If user picked a radius but didn't provide a ZIP, guide them.
		$search_error = "Enter a 5-digit ZIP code (or choose Anywhere).";
	}
} else {
	// Anywhere search requires an artist query
	if ($q === '') {
		$search_error = "Enter an artist name to search Anywhere.";
	}
}

if (!$search_error) {
	try {
		$pdo = db();
		
		if ($mode_anywhere) {
			$search_location_label = "Anywhere";
			
			$sql = "
        SELECT
          p.id,
          p.profile_type,
          p.name,
          p.city,
          p.state,
          p.zip,
          p.genres,
          p.bio,
          p.website,
          NULL AS distance_miles
        FROM profiles p
        WHERE p.is_active = 1
          AND EXISTS (
            SELECT 1 FROM user_calendars uc
            WHERE uc.user_id = p.user_id AND uc.is_default = 1
          )
      ";
			
			$params = [];
			
			if ($type !== 'all') {
				$sql .= " AND p.profile_type = :ptype ";
				$params[':ptype'] = $type;
			}
			
			// Artist-name search only in Anywhere mode
			$sql .= " AND p.name LIKE :q ";
			$params[':q'] = '%' . $q . '%';
			
			$sql .= " ORDER BY p.name ASC LIMIT 60 ";
			
			$stmt = $pdo->prepare($sql);
			foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
			$stmt->execute();
			$search_results = $stmt->fetchAll();
			
		} else {
			// Cache-first ZIP lookup
			$origin = geo_zip_lookup($zip, $pdo);
			if (!$origin) {
				$search_error = "We don’t recognize ZIP <strong>" . h($zip) . "</strong> yet. (Seed data is included; import it to enable search.)";
			} else {
				$olat = (float)$origin['lat'];
				$olng = (float)$origin['lng'];
				$search_location_label = trim((string)($origin['city'] ?? '') . ", " . (string)($origin['state'] ?? '') . " " . (string)($origin['zip'] ?? $zip));
				
				// Bounding-box pre-filter (fast)
				$box = geo_bounding_box($olat, $olng, $radius);
				
				$sql = "
          SELECT
            p.id,
            p.profile_type,
            p.name,
            p.city,
            p.state,
            p.zip,
            p.genres,
            p.bio,
            p.website,
            (
              3959 * ACOS(
                COS(RADIANS(:olat)) * COS(RADIANS(z.lat)) *
                COS(RADIANS(z.lng) - RADIANS(:olng)) +
                SIN(RADIANS(:olat)) * SIN(RADIANS(z.lat))
              )
            ) AS distance_miles
          FROM profiles p
          JOIN zipcodes z ON z.zip = p.zip
          WHERE p.is_active = 1
            AND EXISTS (
              SELECT 1 FROM user_calendars uc
              WHERE uc.user_id = p.user_id AND uc.is_default = 1
            )
            AND z.lat BETWEEN :min_lat AND :max_lat
            AND z.lng BETWEEN :min_lng AND :max_lng
        ";
				
				$params = [
						':olat' => $olat,
						':olng' => $olng,
						':min_lat' => $box['min_lat'],
						':max_lat' => $box['max_lat'],
						':min_lng' => $box['min_lng'],
						':max_lng' => $box['max_lng'],
						':radius' => $radius,
				];
				
				if ($type !== 'all') {
					$sql .= " AND p.profile_type = :ptype ";
					$params[':ptype'] = $type;
				}
				
				if ($q !== '') {
					// When searching by ZIP, we let 'What' match name + genres + city
					$sql .= " AND (p.name LIKE :q OR p.genres LIKE :q OR p.city LIKE :q) ";
					$params[':q'] = '%' . $q . '%';
				}
				
				// NOTE: No busy_dates table in this build yet, so we don't filter by $when.
				
				$sql .= " HAVING distance_miles <= :radius ORDER BY distance_miles ASC, p.name ASC LIMIT 60 ";
				
				$stmt = $pdo->prepare($sql);
				foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
				$stmt->execute();
				$search_results = $stmt->fetchAll();
			}
		}
	} catch (Throwable $e) {
		$search_error = "Search error: " . $e->getMessage();
	}
}
$has_context = (!$search_error && ($mode_anywhere || $zip !== ''));

$title = "Search — Ready Set Shows";
?><!DOCTYPE html>
<html lang="en">
<head>
  <link rel="stylesheet" href="<?= h(BASE_URL) ?>/assets/css/app.css" />
    <style>
    /* Landing search row */
    .search-row{
      display:flex;
      flex-wrap:wrap;
      gap:12px;
      align-items:flex-end;
    }
    .search-row > div{min-width:180px; flex:1;}
    .search-row .search-btn{white-space:nowrap;}
    .search-row .when-date{max-width:180px;}
    </style>
</head>
<body>

  <!-- main class="container" style="max-width: 1160px; margin: 18px auto; padding: 0 16px;"-->
  <main>

      <div class="search-rail">
        <form class="search-grid search-row" method="get" action="<?= h(BASE_URL) ?>/search.php">
          <div>
            <label>Artist\Venue Name</label>
            <input name="q" placeholder="Band, venue, genre…" value="<?= h($q) ?>" />
          </div>
          <div>
            <label>Where</label>
            <input name="where" placeholder="ZIP code" value="<?= h($where) ?>" />
          </div>
          <div>
            <label>Radius</label>
            <select name="radius">
              <option value="0" <?= $radius===0 ? 'selected' : '' ?>>Anywhere</option>
              <?php foreach ([5,10,25,50,100] as $r): ?>
                <option value="<?= (int)$r ?>" <?= $radius===$r ? 'selected' : '' ?>><?= (int)$r ?> miles</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Type</label>
            <select name="type">
              <option value="all" <?= $type==='all' ? 'selected' : '' ?>>All</option>
              <option value="band" <?= $type==='band' ? 'selected' : '' ?>>Bands</option>
              <option value="venue" <?= $type==='venue' ? 'selected' : '' ?>>Venues</option>
            </select>
          </div>
          <div>
            <label>When</label>
            <input type="date" name="when" value="<?= h($when) ?>" class="when-date" />
          </div>
          <button class="search-btn" type="submit">Search →</button>
        </form>
      </div>

      <div class="search-meta">
  <?php if ($has_context): ?>
    <?php $rc = count($search_results); ?>
    <div class="search-title">
      <h1>
        <?= (int)$rc ?> result<?= $rc===1 ? '' : 's' ?>
        <?php if ($mode_anywhere): ?>
          for “<?= h($q) ?>” (Anywhere)
        <?php else: ?>
          near <?= h($search_location_label ?: $zip) ?>
        <?php endif; ?>
      </h1>

      <div class="muted">
        <?php if ($mode_anywhere): ?>
          Matching artist name<?php if ($type !== 'all'): ?> • <?= h(ucfirst($type)) ?>s<?php endif; ?>
        <?php else: ?>
          Discovery v1 results within <?= (int)$radius ?> miles
          <?php if ($type !== 'all'): ?> • <?= h(ucfirst($type)) ?>s<?php endif; ?>
          <?php if ($q !== ''): ?> • “<?= h($q) ?>”<?php endif; ?>
          <?php if ($when !== ''): ?> • Date filter coming soon<?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="search-title">
      <h1>Search</h1>
      <div class="muted">Enter a ZIP to see nearby bands and venues — or choose “Anywhere” and search by artist name.</div>
    </div>
  <?php endif; ?>
</div>
      </div>

      <?php if ($castingProfileType): ?>
        <div class="card" style="margin-top: 12px;">
          <div class="card-body" style="display:flex; gap:.75rem; align-items:center; justify-content:space-between; flex-wrap:wrap;">
            <div>
              <strong>Casting request active</strong>
              <div class="muted" style="margin-top:.15rem;">
                You’re building a request for <?= $castingType==='venue' ? 'venues' : 'artists' ?>. Selected: <?= (int)$castingShortlistCount ?>.
              </div>
            </div>
            <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
              <a class="pill" href="<?= h(BASE_URL) ?>/request_review.php">Review &amp; Send</a>
              <a class="pill" href="<?= h(BASE_URL) ?>/request_browse.php?type=<?= h($castingType) ?>">Quick Browse</a>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($search_error): ?>
        <div class="alert" style="margin-top: 12px;"><?= $search_error ?></div>
      <?php endif; ?>

      <?php if ($has_context): ?>
        <?php if (empty($search_results)): ?>
          <div class="results-empty" style="margin-top: 14px;">No results yet. Try a bigger radius, or search a nearby ZIP.</div>
        <?php else: ?>
          <div class="listing-grid">
            <?php foreach ($search_results as $row): ?>
              <?php
                $name = (string)($row['name'] ?? '');
                $initials = '';
                foreach (preg_split('/\s+/', trim($name)) as $part) {
                  if ($part !== '') $initials .= strtoupper(substr($part, 0, 1));
                  if (strlen($initials) >= 2) break;
                }
                if ($initials === '') $initials = 'RS';
              ?>
              <article class="listing-card">
                <a class="listing-photo" href="<?= h(BASE_URL) ?>/profile.php?id=<?= (int)$row['id'] ?>&where=<?= rawurlencode($where) ?>&when=<?= rawurlencode($when) ?>">
                  <div class="listing-badge"><?= ucfirst(h((string)$row['profile_type'])) ?></div>
                  <div class="listing-heart" aria-hidden="true">♡</div>
                  <div class="listing-initials"><?= h($initials) ?></div>
                </a>
                <div class="listing-body">
                  <div class="listing-name">
                    <a href="<?= h(BASE_URL) ?>/profile.php?id=<?= (int)$row['id'] ?>&where=<?= rawurlencode($where) ?>&when=<?= rawurlencode($when) ?>"><?= h($name) ?></a>
                  </div>
                  <div class="listing-sub">
                    <?= h(trim(($row['city'] ?? '') . ", " . ($row['state'] ?? '') . " " . ($row['zip'] ?? ''))) ?>
                    <span class="dot">•</span>
                    <?= number_format((float)$row['distance_miles'], 1) ?> mi
                  </div>
                  <?php if (!empty($row['genres'])): ?>
                    <div class="listing-tags"><?= h((string)$row['genres']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($row['bio'])): ?>
                    <div class="listing-desc"><?= h((string)$row['bio']) ?></div>
                  <?php endif; ?>
                  <div class="listing-actions">
                    <a class="pill small" href="<?= h(BASE_URL) ?>/profile.php?id=<?= (int)$row['id'] ?>&where=<?= rawurlencode($where) ?>&when=<?= rawurlencode($when) ?>">View</a>
                    <?php $t = ((($row['profile_type'] ?? '') === 'venue') ? 'venue' : 'artist'); ?>

                    <?php if ($castingProfileType && (($row['profile_type'] ?? '') === $castingProfileType)):
                      $pid = (int)$row['id'];
                      $isAdded = in_array($pid, $castingShortlistIds, true);
                    ?>
                      <form method="post" action="<?= h(BASE_URL) ?>/request_shortlist_toggle.php" style="display:inline; margin:0;">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>" />
                        <input type="hidden" name="type" value="<?= h($castingType) ?>" />
                        <input type="hidden" name="profile_id" value="<?= (int)$pid ?>" />
                        <input type="hidden" name="action" value="<?= $isAdded ? 'remove' : 'add' ?>" />
                        <input type="hidden" name="return" value="<?= h($_SERVER['REQUEST_URI'] ?? (BASE_URL . '/search.php')) ?>" />
                        <button class="pill small" type="submit"><?= $isAdded ? 'Remove' : 'Add' ?></button>
                      </form>
                    <?php else: ?>
                      <a class="pill small" href="<?= h(BASE_URL) ?>/request.php?target_profile_id=<?= (int)$row['id'] ?>&target_type=<?= h($t) ?>">Request</a>
                    <?php endif; ?>
                    <?php if (!empty($row['website'])): ?>
                      <a class="pill small" href="<?= h((string)$row['website']) ?>" target="_blank" rel="noopener">Website</a>
                    <?php endif; ?>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <?= geonames_attribution_html() ?>
    </section>
  </main>

</body>
</html>