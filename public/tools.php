<?php
require_once __DIR__ . "/_layout.php";
require_login();

page_header('Tools');
?>

<div class="container" style="max-width:1100px;margin:0 auto;padding:18px 14px;">
  <h1 style="margin:0 0 6px;">Tools</h1>
  <p class="muted" style="margin:0 0 14px;">Starter hub page. These links exist so the nav has real destinations. We can replace each card with real utilities as you build them.</p>

  <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));">

    <div class="card" style="padding:14px;border-radius:16px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.18);">
      <h3 style="margin:0 0 8px;">Bands in Town</h3>
      <p class="muted" style="margin:0 0 12px;">Search/lookup utility (currently points to your existing search page).</p>
      <a class="dash-btn" href="<?= h(BASE_URL) ?>/search.php">Open</a>
    </div>

    <div class="card" style="padding:14px;border-radius:16px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.18);">
      <h3 style="margin:0 0 8px;">Check Availability</h3>
      <p class="muted" style="margin:0 0 12px;">Your availability checker (already implemented).</p>
      <a class="dash-btn" href="<?= h(BASE_URL) ?>/check_availability.php">Open</a>
    </div>

    <div class="card" style="padding:14px;border-radius:16px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.18);">
      <h3 style="margin:0 0 8px;">Pretty Print</h3>
      <p class="muted" style="margin:0 0 12px;">Placeholder for formatting helpers (prints debug-friendly output).</p>
      <a class="dash-btn" href="<?= h(BASE_URL) ?>/print.php">Open</a>
    </div>

    <div class="card" style="padding:14px;border-radius:16px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.18);">
      <h3 style="margin:0 0 8px;">Social Help</h3>
      <p class="muted" style="margin:0 0 12px;">Placeholder for social templates + tools.</p>
      <a class="dash-btn" href="<?= h(BASE_URL) ?>/social.php">Open</a>
    </div>

  </div>
</div>

<?php page_footer(); ?>
