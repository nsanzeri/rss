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
?><!doctype html>
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
            <input name="q" placeholder="Live music, band, DJ, comedy…" value="<?= h($q) ?>" />
          </div>
          <div>
            <label>Where</label>
            <input name="where" placeholder="City or zip" value="<?= h($where) ?>" />
          </div>
          <div>
            <label>When</label>
            <input name="when" placeholder="Anytime" value="<?= h($when) ?>" />
          </div>
          <button class="search-btn" type="submit">Search →</button>
        </form>
      </div>

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
