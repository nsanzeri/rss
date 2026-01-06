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

$allowed_radii = [0,5,10,25,50,100];
if (!in_array($radius, $allowed_radii, true)) { $radius = 25; }

$allowed_types = ['all','band','venue'];
if (!in_array($type, $allowed_types, true)) { $type = 'all'; }

// Results now live on /search.php (clean separation + lets us build a true results UI).
// If the user lands on /index.php with search params, bounce them to the results page.
if ($where !== '' || $q !== '' || $when !== '') {
	$qs = http_build_query([
			'q' => $q,
			'where' => $where,
			'when' => $when,
			'radius' => $radius,
			'type' => $type,
	]);
	header("Location: " . BASE_URL . "/search.php?" . $qs);
	exit;
}

// ---------------------------------------------------------------------
// Homepage "flavor feed"
// - Uses only calendar_events rows (no extra joins to external sources)
// - Uses only public calendars (repurposed: user_calendars.is_default = 1)
// - Pulls upcoming week, no duplicate profiles
// ---------------------------------------------------------------------
$flavor_bands = [];
$flavor_venues = [];
$flavor_error = '';

try {
	$pdo = db();
	$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
	$endUtc = $nowUtc->modify('+7 days');

	// Helper query: next event per profile within the window.
	$sql = "
    SELECT
      p.id AS profile_id,
      p.profile_type,
      p.name,
      COALESCE(p.city, z.city) AS city,
      COALESCE(p.state, z.state) AS state,
      p.zip,
      MIN(ce.start_utc) AS next_start_utc,
      SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(ce.title,'') ORDER BY ce.start_utc ASC SEPARATOR '||'), '||', 1) AS next_title
    FROM profiles p
    JOIN user_calendars uc ON uc.user_id = p.user_id AND uc.is_default = 1
    JOIN calendar_events ce ON ce.calendar_id = uc.id
    LEFT JOIN zipcodes z ON z.zip = p.zip
    WHERE p.is_active = 1
      AND ce.status = 'busy'
      AND ce.start_utc >= :start_utc
      AND ce.start_utc < :end_utc
    GROUP BY p.id
    ORDER BY RAND()
    LIMIT 24
  ";
	$stmt = $pdo->prepare($sql);
	$stmt->execute([
			':start_utc' => $nowUtc->format('Y-m-d H:i:s'),
			':end_utc' => $endUtc->format('Y-m-d H:i:s'),
	]);
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

	foreach ($rows as $r) {
		if (($r['profile_type'] ?? '') === 'venue') {
			$flavor_venues[] = $r;
		} else {
			// Default: treat everything else as "band" for public discovery.
			$flavor_bands[] = $r;
		}
	}

	$flavor_bands = array_slice($flavor_bands, 0, 8);
	$flavor_venues = array_slice($flavor_venues, 0, 8);

} catch (Throwable $e) {
	// Homepage should still render even if DB isn't connected yet.
	$flavor_error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h(APP_NAME) ?> • Find live music, venues, and dates</title>
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

    /* Parallax sections */
    .parallax{
      position:relative;
      min-height:70vh;
      display:flex;
      align-items:center;
      padding:70px 0;
      overflow:hidden;
      background-attachment: fixed;
      background-size: cover;
      background-position: center;
      border-top: 1px solid rgba(255,255,255,0.08);
    }
    @media (max-width: 900px){
      .parallax{ background-attachment: scroll; min-height: auto; padding:50px 0;}
    }
    .parallax::before{
      content:"";
      position:absolute; inset:0;
      background: rgba(0,0,0,0.55);
      backdrop-filter: blur(1px);
    }
    .parallax .parallax-inner{
      position:relative;
      width:min(1100px, calc(100% - 32px));
      margin:0 auto;
      display:grid;
      grid-template-columns: 1.2fr .8fr;
      gap:22px;
      align-items:center;
    }
    @media (max-width: 900px){
      .parallax .parallax-inner{ grid-template-columns:1fr; }
    }
    .parallax .panel{
      background: rgba(10,10,10,0.55);
      border: 1px solid rgba(255,255,255,0.10);
      border-radius: 18px;
      padding: 18px;
    }
    .parallax .kicker{letter-spacing:.12em; text-transform:uppercase; font-size:.78rem; opacity:.85;}
    .parallax h2{margin:6px 0 10px;}
    .parallax p{margin:0 0 12px; line-height:1.55;}
    .parallax .cta-row{display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;}
    .parallax .cta-row a{display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border-radius: 999px; border:1px solid rgba(255,255,255,0.18);}
    .parallax .cta-row a.primary{background: rgba(99,102,241,0.18); border-color: rgba(99,102,241,0.35);}
    .parallax .bullets{display:grid; gap:10px;}
    .parallax .bullet{display:flex; gap:10px; align-items:flex-start;}
    .parallax .dot{width:10px; height:10px; border-radius:999px; background: rgba(255,255,255,0.75); margin-top:6px; flex:0 0 auto;}
    /* Section backgrounds (no external images needed) */
    .p-listings{background-image: radial-gradient(1200px 600px at 20% 10%, rgba(99,102,241,.45), transparent 55%), radial-gradient(900px 500px at 80% 70%, rgba(16,185,129,.30), transparent 55%), linear-gradient(135deg, #0b1020, #05060a);}
    .p-bookings{background-image: radial-gradient(1200px 600px at 70% 20%, rgba(236,72,153,.35), transparent 55%), radial-gradient(900px 500px at 25% 75%, rgba(245,158,11,.25), transparent 55%), linear-gradient(135deg, #120510, #05060a);}
    .p-cal{background-image: radial-gradient(1200px 600px at 30% 15%, rgba(59,130,246,.40), transparent 55%), radial-gradient(900px 500px at 75% 80%, rgba(139,92,246,.30), transparent 55%), linear-gradient(135deg, #071226, #05060a);}
    .p-tools{background-image: radial-gradient(1200px 600px at 60% 10%, rgba(34,197,94,.25), transparent 55%), radial-gradient(900px 500px at 20% 80%, rgba(148,163,184,.20), transparent 55%), linear-gradient(135deg, #081018, #05060a);}

    /* Flavor feed */
    .flavor-wrap{width:min(1100px, calc(100% - 32px)); margin: 0 auto; padding: 18px 0 10px;}
    .flavor-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:10px;}
    .flavor-head h3{margin:0;font-size: 16px;letter-spacing:-0.01em;}
    .flavor-head .muted{opacity:.75;font-size:13px;}
    .flavor-rail{display:flex;gap:12px;overflow:auto;padding:12px 2px 18px;scroll-snap-type:x mandatory;}
    .flavor-card{min-width: 280px; max-width: 320px; scroll-snap-align:start; background: rgba(10,10,10,0.55); border:1px solid rgba(255,255,255,0.10); border-radius: 18px; padding: 14px;}
    .flavor-card .name{font-weight:700;letter-spacing:-0.01em;}
    .flavor-card .meta{opacity:.82;font-size:13px;margin-top:6px;line-height:1.35;}
    .flavor-card .tag{display:inline-flex;align-items:center;gap:6px;border:1px solid rgba(255,255,255,0.14);border-radius:999px;padding:6px 10px;font-size:12px;opacity:.9;margin-top:10px;}
    .flavor-card a{color:inherit;text-decoration:none;}
    .flavor-card:hover{border-color: rgba(124,58,237,0.55);}
  </style>

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
      <h2 class="hero-title">Discover live music, book entertainment, and manage your shows — all in one place.</h2>
      <p class="hero-sub">Search what’s happening, then follow the trail. If you’re a band or venue, listing takes minutes.</p>

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

      <div class="hero-hints">
        <a href="<?= h(BASE_URL) ?>/pricing.php">Calendar tools</a>
        <a href="<?= h(BASE_URL) ?>/public_availability.php">Share availability</a>
        <a href="<?= h(BASE_URL) ?>/listings.php">This week</a>
        <a href="<?= h(BASE_URL) ?>/login.php">Try Ops</a>
      </div>
    </section>

    <?php
      $tzLocal = new DateTimeZone('America/Chicago');
      $fmtDay = function($utc) use ($tzLocal) {
        if (!$utc) return '';
        try {
          $dt = new DateTimeImmutable((string)$utc, new DateTimeZone('UTC'));
          $dt = $dt->setTimezone($tzLocal);
          return $dt->format('D, M j');
        } catch (Throwable $e) { return ''; }
      };
    ?>

    <?php if (!empty($flavor_bands) || !empty($flavor_venues)): ?>
      <section class="flavor-wrap" aria-label="This week">
        <div class="flavor-head">
          <div>
            <h3>What’s happening this week</h3>
            <div class="muted">A quick peek from public calendars (no duplicates)</div>
          </div>
          <div>
            <a class="pill" href="<?= h(BASE_URL) ?>/listings.php">View the full week →</a>
          </div>
        </div>

        <?php if (!empty($flavor_bands)): ?>
          <div class="flavor-head" style="margin-top:14px;">
            <h3>Bands</h3>
            <div class="muted"><?= count($flavor_bands) ?> picks</div>
          </div>
          <div class="flavor-rail" role="list">
            <?php foreach ($flavor_bands as $it): ?>
              <div class="flavor-card" role="listitem">
                <a href="<?= h(BASE_URL) ?>/profile.php?id=<?= (int)$it['profile_id'] ?>">
                  <div class="name"><?= h($it['name'] ?? '') ?></div>
                  <div class="meta">
                    <div><b><?= h($fmtDay($it['next_start_utc'] ?? null)) ?></b> • <?= h($it['next_title'] ?? 'Show') ?></div>
                    <div><?= h(trim(($it['city'] ?? '') . ", " . ($it['state'] ?? '') . " " . ($it['zip'] ?? ''))) ?></div>
                  </div>
                  <div class="tag">View profile →</div>
                </a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($flavor_venues)): ?>
          <div class="flavor-head" style="margin-top:4px;">
            <h3>Venues</h3>
            <div class="muted"><?= count($flavor_venues) ?> picks</div>
          </div>
          <div class="flavor-rail" role="list">
            <?php foreach ($flavor_venues as $it): ?>
              <div class="flavor-card" role="listitem">
                <a href="<?= h(BASE_URL) ?>/profile.php?id=<?= (int)$it['profile_id'] ?>">
                  <div class="name"><?= h($it['name'] ?? '') ?></div>
                  <div class="meta">
                    <div><b><?= h($fmtDay($it['next_start_utc'] ?? null)) ?></b> • <?= h($it['next_title'] ?? 'Show') ?></div>
                    <div><?= h(trim(($it['city'] ?? '') . ", " . ($it['state'] ?? '') . " " . ($it['zip'] ?? ''))) ?></div>
                  </div>
                  <div class="tag">View profile →</div>
                </a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    
    <!-- Scroll story: what Ready Set Shows does -->
    <section class="parallax p-listings" aria-label="Listings">
      <div class="parallax-inner">
        <div class="panel">
          <div class="kicker">Listings</div>
          <h2>Find artists and venues that actually fit the gig</h2>
          <p>Search by name and location, then jump straight to profiles with photos, YouTube, and contact-ready info. No dead ends, no spreadsheets.</p>
          <div class="cta-row">
            <a class="primary" href="<?= h(BASE_URL) ?>/search.php">Browse listings →</a>
            <a href="<?= h(BASE_URL) ?>/pricing.php">See plans</a>
          </div>
        </div>
        <div class="panel">
          <div class="bullets">
            <div class="bullet"><span class="dot"></span><div><b>Profiles</b><div class="muted">Photos + YouTube links + tight bio.</div></div></div>
            <div class="bullet"><span class="dot"></span><div><b>Location-aware</b><div class="muted">ZIP + radius filtering.</div></div></div>
            <div class="bullet"><span class="dot"></span><div><b>Fast contact</b><div class="muted">Booking flow (no hunting for emails).</div></div></div>
          </div>
        </div>
      </div>
    </section>

    <section class="parallax p-bookings" aria-label="Bookings">
      <div class="parallax-inner">
        <div class="panel">
          <div class="kicker">Bookings</div>
          <h2>Turn “Are you available?” into a trackable request</h2>
          <p>Capture booking requests, respond, and keep a clean status trail. You’ll be able to measure response time as a real metric—not a vibe.</p>
          <div class="cta-row">
            <a class="primary" href="<?= h(BASE_URL) ?>/public_availability.php">Share availability →</a>
            <a href="<?= h(BASE_URL) ?>/login.php">Try Ops</a>
          </div>
        </div>
        <div class="panel">
          <div class="bullets">
            <div class="bullet"><span class="dot"></span><div><b>Request → status</b><div class="muted">Inquiry, pending, confirmed.</div></div></div>
            <div class="bullet"><span class="dot"></span><div><b>Follow-up ready</b><div class="muted">Central place for conversations.</div></div></div>
            <div class="bullet"><span class="dot"></span><div><b>Response time</b><div class="muted">Measured from first response.</div></div></div>
          </div>
        </div>
      </div>
    </section>

    <section class="parallax p-cal" aria-label="Calendar management">
      <div class="parallax-inner">
        <div class="panel">
          <div class="kicker">Calendar Management</div>
          <h2>Import, overlay, and block dates—without timezone pain</h2>
          <p>Link external calendars, import a date range, and add manual blocks. Edit and delete blocks right from the dashboard calendar widget.</p>
          <div class="cta-row">
            <a class="primary" href="<?= h(BASE_URL) ?>/manage_calendars.php">Manage calendars →</a>
            <a href="<?= h(BASE_URL) ?>/dashboard.php">Open dashboard</a>
          </div>
        </div>
        <div class="panel">
          <div class="bullets">
            <div class="bullet"><span class="dot"></span><div><b>ICS import</b><div class="muted">Bring in the dates you want.</div></div></div>
            <div class="bullet"><span class="dot"></span><div><b>Manual blocks</b><div class="muted">Busy/available holds.</div></div></div>
            <div class="bullet"><span class="dot"></span><div><b>Widget editing</b><div class="muted">Change entries from the calendar view.</div></div></div>
          </div>
        </div>
      </div>
    </section>

    <section class="parallax p-tools" aria-label="Tools">
      <div class="parallax-inner">
        <div class="panel">
          <div class="kicker">Tools</div>
          <h2>Little utilities that save real hours</h2>
          <p>From availability checks to pretty printing and imports—tools are the backbone while the marketplace grows.</p>
          <div class="cta-row">
            <a class="primary" href="<?= h(BASE_URL) ?>/pricing.php">Explore tools →</a>
            <a href="<?= h(BASE_URL) ?>/register.php?intent=band">List your band</a>
          </div>
        </div>
        <div class="panel">
          <div class="bullets">
            <div class="bullet"><span class="dot"></span><div><b>Creator-first</b><div class="muted">Built for working musicians.</div></div></div>
            <div class="bullet"><span class="dot"></span><div><b>Ops-ready</b><div class="muted">Booking + calendar workflows.</div></div></div>
            <div class="bullet"><span class="dot"></span><div><b>Ship fast</b><div class="muted">Incremental improvements weekly.</div></div></div>
          </div>
        </div>
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
