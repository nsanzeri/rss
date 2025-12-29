<?php
require_once __DIR__ . "/../core/bootstrap.php";
$user = auth_user();
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Ready Set Shows — Ops • Calendar control + instant availability</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="<?= h(BASE_URL) ?>/assets/css/app.css">
</head>
<body>
<div class="page">
  <header class="nav">
    <div class="nav-left">
      <div class="logo-mark">
        <div class="logo-mark-inner">
          <div class="logo-bolt"></div>
          <div class="logo-ring"></div>
        </div>
      </div>
      <div class="brand">
        <div class="brand-title">Ready Set Shows</div>
        <div class="brand-subtitle">Ops • Availability + Calendars</div>
      </div>
    </div>

    <div class="nav-right">
      <?php if ($user): ?>
        <a class="btn btn-ghost" href="<?= h(BASE_URL) ?>/dashboard.php">Dashboard</a>
        <a class="btn" href="<?= h(BASE_URL) ?>/check_availability.php">Check Availability</a>
      <?php else: ?>
        <a class="btn btn-ghost" href="<?= h(BASE_URL) ?>/login.php">Log in</a>
        <a class="btn" href="<?= h(BASE_URL) ?>/register.php">Create account</a>
      <?php endif; ?>
    </div>
  </header>

  <main class="hero">
    <section class="hero-copy">
      <div class="eyebrow">Built for working musicians</div>
      <h1>Instant availability — without calendar chaos.</h1>
      <p class="lead">
        Connect your calendars (ICS now, Google later), add holds, and generate a clean list of available dates you can
        send to agents, venues, and clients in seconds.
      </p>

      <div class="hero-actions">
        <?php if ($user): ?>
          <a class="btn btn-lg" href="<?= h(BASE_URL) ?>/check_availability.php">Generate available dates</a>
          <a class="btn btn-lg secondary" href="<?= h(BASE_URL) ?>/manage_calendars.php">Manage calendars</a>
        <?php else: ?>
          <a class="btn btn-lg" href="<?= h(BASE_URL) ?>/register.php">Start free</a>
          <a class="btn btn-lg secondary" href="<?= h(BASE_URL) ?>/login.php">Log in</a>
        <?php endif; ?>
      </div>

      <div class="hero-bullets">
        <div class="bullet">
          <div class="bullet-dot"></div>
          <div><strong>Timezone-safe.</strong> Store in UTC, display in your timezone.</div>
        </div>
        <div class="bullet">
          <div class="bullet-dot"></div>
          <div><strong>All-day + recurring events.</strong> No more “wait, am I booked?”</div>
        </div>
        <div class="bullet">
          <div class="bullet-dot"></div>
          <div><strong>Exports that clients understand.</strong> Copy, CSV, TXT.</div>
        </div>
      </div>

      <?php if ($user): ?>
        <div class="muted" style="margin-top: 18px;">
          Signed in as <strong><?= h($user['email']) ?></strong>.
          <a class="link" href="<?= h(BASE_URL) ?>/public_availability.php">Get your share link</a>.
        </div>
      <?php endif; ?>
    </section>

    <aside class="hero-panel">
      <div class="panel card">
        <div class="panel-header">
          <div class="panel-title">What you can do in Ops</div>
          <div class="panel-subtitle">v1 focused. Fast. Reliable.</div>
        </div>

        <div class="panel-grid">
          <div class="metric">
            <div class="metric-label">Check Availability</div>
            <div class="metric-value">1 click</div>
            <div class="metric-tag">client-ready list</div>
          </div>
          <div class="metric">
            <div class="metric-label">Calendar imports</div>
            <div class="metric-value">ICS</div>
            <div class="metric-tag">manual sync</div>
          </div>
          <div class="metric">
            <div class="metric-label">Manual holds</div>
            <div class="metric-value">Busy</div>
            <div class="metric-tag">block dates</div>
          </div>
          <div class="metric">
            <div class="metric-label">Share link</div>
            <div class="metric-value">Public</div>
            <div class="metric-tag">read-only</div>
          </div>
        </div>

        <div class="panel-footer">
          <div class="panel-footer-left">
            <div class="pill">Ready Set Shows</div>
            <div class="pill ghost">Ops</div>
          </div>
          <div class="panel-footer-right">
            <a class="btn btn-ghost" href="<?= h(BASE_URL) ?>/check_availability.php">Try it</a>
          </div>
        </div>
      </div>
    </aside>
  </main>

  <footer class="footer">
    Ready Set Shows™ is the umbrella. Ops™ is your back office.
    Built for artists who like their systems as tight as their band.
  </footer>
</div>
</body>
</html>
