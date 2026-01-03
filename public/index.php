<?php
require_once __DIR__ . "/../core/bootstrap.php";

$user = auth_user();

// Phase 1: keep development convenient — authenticated users go straight to Ops.
// Add ?stay=1 to preview the public landing while logged in.
if ($user && empty($_GET['stay'])) {
    header("Location: " . BASE_URL . "/dashboard.php");
    exit;
}

$q = trim($_GET['q'] ?? '');
$where = trim($_GET['where'] ?? '');
$when = trim($_GET['when'] ?? '');
$radius = (int)($_GET['radius'] ?? 25);
$type = trim($_GET['type'] ?? 'all');

$allowed_radii = [5,10,25,50,100];
if (!in_array($radius, $allowed_radii, true)) { $radius = 25; }

$allowed_types = ['all','band','venue'];
if (!in_array($type, $allowed_types, true)) { $type = 'all'; }

$search_error = '';
$search_results = [];
$search_location_label = '';

/**
 * Zip-based radius search (Option A)
 * Requires:
 *  - zipcodes(zip, lat, lng, city, state)
 *  - profiles(profile_type, name, zip, city, state, genres, bio, website, is_active)
 */
if ($where !== '') {
    // Normalize ZIP (keep first 5 digits)
    if (preg_match('/(\d{5})/', $where, $mm)) {
        $zip = $mm[1];

        try {
            $pdo = db();

            $stmt = $pdo->prepare("SELECT zip, lat, lng, city, state FROM zipcodes WHERE zip=? LIMIT 1");
            $stmt->execute([$zip]);
            $origin = $stmt->fetch();

            if (!$origin) {
                $search_error = "We don’t recognize ZIP <strong>" . h($zip) . "</strong> yet. (Seed data is included in this zip; import it to enable search.)";
            } else {
                $olat = (float)$origin['lat'];
                $olng = (float)$origin['lng'];
                $search_location_label = trim(($origin['city'] ?? '') . ", " . ($origin['state'] ?? '') . " " . ($origin['zip'] ?? $zip));

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
                ";

                $params = [':olat'=>$olat, ':olng'=>$olng, ':radius'=>$radius];

                if ($type !== 'all') {
                    $sql .= " AND p.profile_type = :ptype ";
                    $params[':ptype'] = $type;
                }

                if ($q !== '') {
                    $sql .= " AND (p.name LIKE :q OR p.genres LIKE :q OR p.city LIKE :q) ";
                    $params[':q'] = '%' . $q . '%';
                }

                $sql .= " HAVING distance_miles <= :radius ORDER BY distance_miles ASC, p.name ASC LIMIT 50 ";

                $stmt = $pdo->prepare($sql);
                foreach ($params as $k=>$v) { $stmt->bindValue($k, $v); }
                $stmt->execute();
                $search_results = $stmt->fetchAll();
            }
        } catch (Throwable $e) {
            // Don’t hard-fail the landing page if DB isn't configured yet.
            $search_error = "Search isn’t available yet (database not connected).";
        }
    } else {
        $search_error = "Enter a 5-digit ZIP code to search nearby.";
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h(APP_NAME) ?> • Find live music, venues, and dates</title>
  <link rel="stylesheet" href="<?= h(BASE_URL) ?>/assets/css/app.css" />
</head>
<body class="landing">

  <header class="landing-top" aria-label="Ready Set Shows">
    <div class="landing-brand">
      <h1>READY SET SHOWS</h1>
    </div>

    <nav class="landing-nav" aria-label="Primary">
      <a href="<?= h(BASE_URL) ?>/register.php?intent=band">List Your Band</a>
      <a href="<?= h(BASE_URL) ?>/register.php?intent=venue">List Your Venue</a>
      <a href="<?= h(BASE_URL) ?>/pricing.php">Pricing</a>
      <span class="pill" style="opacity:.0; border-color:transparent;">&nbsp;</span>
      <a class="pill" href="#learn">Learn</a>
      <a class="pill" href="<?= h(BASE_URL) ?>/login.php">Log In</a>
      <a class="pill" href="<?= h(BASE_URL) ?>/register.php" style="background: rgba(124,58,237,0.22); border-color: rgba(124,58,237,0.42);">Sign Up</a>
    </nav>
  </header>

  <main>
    <section class="hero" id="top">
      <div class="hero-kicker">Go somewhere fun — or bring the fun to you</div>
      <h2 class="hero-title">Find live music. Find venues. Share dates.</h2>
      <p class="hero-sub">Search what’s happening, then follow the trail. If you’re a band or venue, listing takes minutes.</p>

      <div class="search-rail">
        <form class="search-grid" method="get" action="<?= h(BASE_URL) ?>/index.php">
          <div>
            <label>What</label>
            <input name="q" placeholder="Band, venue, genre…" value="<?= h($q) ?>" />
          </div>
          <div>
            <label>Where</label>
            <input name="where" placeholder="ZIP code" value="<?= h($where) ?>" />
          </div>
          <div>
            <label>Radius</label>
            <select name="radius">
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
            <input name="when" placeholder="Any date (optional)" value="<?= h($when) ?>" />
          </div>
          <button class="search-btn" type="submit">Search →</button>
        </form>
      </div>

      <?php if ($where !== ''): ?>
        <section class="search-results">
          <?php if ($search_error): ?>
            <div class="alert"><?= $search_error ?></div>
          <?php else: ?>
            <div class="results-head">
              <h3>Nearby results<?php if ($search_location_label): ?> near <?= h($search_location_label) ?><?php endif; ?></h3>
              <div class="results-meta"><?= count($search_results) ?> found within <?= (int)$radius ?> miles<?php if ($type !== 'all'): ?> (<?= h($type) ?>s)<?php endif; ?><?php if ($q !== ''): ?> matching “<?= h($q) ?>”<?php endif; ?></div>
            </div>

            <?php if (empty($search_results)): ?>
              <div class="results-empty">No results yet. Try a bigger radius, or search a nearby ZIP.</div>
            <?php else: ?>
              <div class="results-grid">
                <?php foreach ($search_results as $row): ?>
                  <article class="result-card">
                    <div class="result-top">
                      <div class="result-name"><?= h($row['name']) ?></div>
                      <div class="result-badges">
                        <span class="badge badge-<?= h($row['profile_type']) ?>"><?= ucfirst(h($row['profile_type'])) ?></span>
                        <span class="badge badge-distance"><?= number_format((float)$row['distance_miles'], 1) ?> mi</span>
                      </div>
                    </div>

                    <div class="result-sub">
                      <?= h(trim(($row['city'] ?? '') . ", " . ($row['state'] ?? '') . " " . ($row['zip'] ?? ''))) ?>
                      <?php if (!empty($row['genres'])): ?>
                        <span class="dot">•</span> <?= h($row['genres']) ?>
                      <?php endif; ?>
                    </div>

                    <?php if (!empty($row['bio'])): ?>
                      <div class="result-bio"><?= h($row['bio']) ?></div>
                    <?php endif; ?>

                    <div class="result-actions">
                      <a class="pill small" href="<?= h(BASE_URL) ?>/profile.php?id=<?= (int)$row['id'] ?>">View</a>
                      <?php
                        $mailto = "mailto:"; // filled on profile page; keep generic for now
                        $subject = rawurlencode("Booking inquiry: " . ($row['name'] ?? ''));
                        $body = rawurlencode("Hi! I'm interested in " . ($row['profile_type'] ?? 'your') . " listing: " . ($row['name'] ?? '') . ".\n\nDate: " . ($when ?: "TBD") . "\nLocation ZIP: " . ($where ?: "") . "\n\nThanks!");
                      ?>
                      <a class="pill small" href="mailto:?subject=<?= $subject ?>&body=<?= $body ?>">Request</a>
                      <?php if (!empty($row['website'])): ?>
                        <a class="pill small" href="<?= h($row['website']) ?>" target="_blank" rel="noopener">Website</a>
                      <?php endif; ?>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <div class="hero-hints">
        <a href="<?= h(BASE_URL) ?>/pricing.php">Calendar tools</a>
        <a href="<?= h(BASE_URL) ?>/public_availability.php">Share availability</a>
        <a href="<?= h(BASE_URL) ?>/login.php">Try Ops</a>
      </div>
    </section>

    <section class="marketing" id="learn">
      <div class="section">
        <div class="section-inner">
          <div>
            <h2>For people looking for entertainment</h2>
            <p>Search by location and date. Follow bands and venues. Get clean listings without digging through ten apps.</p>
          </div>
          <div class="kpi">
            <div class="k"><b>Simple</b><span class="muted">Type, search, go.</span></div>
            <div class="k"><b>Local</b><span class="muted">Built for scenes and neighborhoods.</span></div>
          </div>
        </div>
      </div>

      <div class="section">
        <div class="section-inner">
          <div>
            <h2>For artists</h2>
            <p>Import your schedule, add manual holds, and publish a shareable “here are my dates” page clients understand.</p>
            <div class="row" style="margin-top:14px;">
              <a class="btn" href="<?= h(BASE_URL) ?>/pricing.php">See tiers</a>
              <a class="btn primary" href="<?= h(BASE_URL) ?>/register.php?intent=band">List your band</a>
            </div>
          </div>
          <div class="kpi">
            <div class="k"><b>Month dashboard</b><span class="muted">See everything at a glance.</span></div>
            <div class="k"><b>Share links</b><span class="muted">Send dates without screenshots.</span></div>
          </div>
        </div>
      </div>

      <div class="parallax-band" aria-hidden="true"></div>

      <div class="section">
        <div class="section-inner">
          <div>
            <h2>For venues & bookers</h2>
            <p>Check availability fast. Coordinate multiple calendars. Delegate access. Keep bookings sane.</p>
            <div class="row" style="margin-top:14px;">
              <a class="btn" href="<?= h(BASE_URL) ?>/register.php?intent=venue">List a venue</a>
              <a class="btn primary" href="<?= h(BASE_URL) ?>/login.php">Try Ops</a>
            </div>
          </div>
          <div class="kpi">
            <div class="k"><b>Filters</b><span class="muted">Toggle calendars like Google Calendar.</span></div>
            <div class="k"><b>UTC-safe</b><span class="muted">Imports + manual holds stay consistent.</span></div>
          </div>
        </div>
      </div>

      <footer class="marketing-footer">
        <div class="muted">© <?= date('Y') ?> Ready Set Shows</div>
        <div class="muted">Tools first. Marketplace next.</div>
      </footer>
    </section>
  </main>

</body>
</html>
