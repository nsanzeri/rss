<?php
require_once __DIR__ . "/_layout.php";
require_login();

page_header("Dashboard");

$u = auth_user();

// Summary metrics
$pdo = db();
$stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM user_calendars WHERE user_id=?");
$stmt->execute([$u['id']]);
$calCount = (int)($stmt->fetch()['c'] ?? 0);

$stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM calendar_events e JOIN user_calendars c ON c.id=e.calendar_id WHERE c.user_id=? AND e.start_utc >= UTC_TIMESTAMP()");
$stmt->execute([$u['id']]);
$upcoming = (int)($stmt->fetch()['c'] ?? 0);

$stmt = $pdo->prepare("SELECT MAX(i.last_synced_at) AS last_sync FROM calendar_imports i JOIN user_calendars c ON c.id=i.calendar_id WHERE c.user_id=?");
$stmt->execute([$u['id']]);
$lastSync = $stmt->fetch()['last_sync'] ?? null;
$lastSyncText = $lastSync ? date('M j, Y g:ia', strtotime($lastSync)) . " UTC" : "Never";
?>
<div class="app-grid">
  <section>
    <div class="card">
      <div class="card-header" style="display:flex; align-items:flex-start; justify-content:space-between; gap:1rem;">
        <div>
          <div class="card-title">Welcome back</div>
          <div class="card-subtitle">Your back office for calendars + instant availability.</div>
        </div>
        <div class="pill ghost">Ops • v1</div>
      </div>

      <div class="panel-grid" style="margin-top: 1rem;">
        <div class="metric">
          <div class="metric-label">Calendars</div>
          <div class="metric-value"><?= (int)$calCount ?></div>
          <div class="metric-tag">connected sources</div>
        </div>
        <div class="metric">
          <div class="metric-label">Upcoming events</div>
          <div class="metric-value"><?= (int)$upcoming ?></div>
          <div class="metric-tag">next shows & holds</div>
        </div>
        <div class="metric">
          <div class="metric-label">Last sync</div>
          <div class="metric-value"><?= h($lastSyncText) ?></div>
          <div class="metric-tag">ICS manual imports</div>
        </div>
        <div class="metric">
          <div class="metric-label">Share link</div>
          <div class="metric-value">Public</div>
          <div class="metric-tag">read‑only availability</div>
        </div>
      </div>

      <div class="actions" style="margin-top: 1.2rem;">
        <a class="btn btn-primary" href="<?= h(BASE_URL) ?>/check_availability.php">Check Availability</a>
        <a class="btn btn-secondary" href="<?= h(BASE_URL) ?>/manage_calendars.php">Manage Calendars</a>
        <a class="btn btn-ghost" href="<?= h(BASE_URL) ?>/public_availability.php">Public Link</a>
      </div>
    </div>

    <div class="card" style="margin-top: 1.4rem;">
      <div class="card-header">
        <div class="card-title">Next steps</div>
        <div class="card-subtitle">Get value fast. Keep it simple.</div>
      </div>
      <ol class="tool-list" style="margin-top: 0.9rem;">
        <li class="tool-item">
          <span>Add your “Live shows” calendar (ICS URL)</span>
          <span class="badge">Step 1</span>
        </li>
        <li class="tool-item">
          <span>Add a “No gig / Holds” calendar (manual blocks)</span>
          <span class="badge">Step 2</span>
        </li>
        <li class="tool-item">
          <span>Generate availability and send the public link</span>
          <span class="badge">Step 3</span>
        </li>
      </ol>
    </div>
  </section>

  <aside>
    <div class="card suite-card">
      <div class="suite-title">Ready Set Shows Suite</div>
      <div class="suite-subtitle">Umbrella modules. Ops is live — the rest are staged.</div>

      <div class="suite-list">
        <div class="suite-item">
          <div class="suite-left">
            <div class="suite-name">Ops</div>
            <div class="suite-tag">Availability + calendars</div>
          </div>
          <div class="suite-badge on">Active</div>
        </div>

        <div class="suite-item">
          <div class="suite-left">
            <div class="suite-name">Shows</div>
            <div class="suite-tag">Pretty print + exports</div>
          </div>
          <div class="suite-badge soon">Soon</div>
        </div>

        <div class="suite-item">
          <div class="suite-left">
            <div class="suite-name">Finance</div>
            <div class="suite-tag">Payouts + 1099s</div>
          </div>
          <div class="suite-badge soon">Soon</div>
        </div>

        <div class="suite-item">
          <div class="suite-left">
            <div class="suite-name">Social</div>
            <div class="suite-tag">Posts + promo assets</div>
          </div>
          <div class="suite-badge soon">Soon</div>
        </div>

        <div class="suite-item">
          <div class="suite-left">
            <div class="suite-name">Connect</div>
            <div class="suite-tag">Clients + venues</div>
          </div>
          <div class="suite-badge soon">Soon</div>
        </div>
      </div>

      <div class="muted" style="margin-top: 0.9rem;">
        Want one of these next? Build it as an add‑on, not a rewrite.
      </div>
    </div>
  </aside>
</div>

<?php page_footer(); ?>
