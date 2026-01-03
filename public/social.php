<?php
require_once __DIR__ . "/_layout.php";
require_login();

page_header('Social Help');
?>

<div class="container" style="max-width:1100px;margin:0 auto;padding:18px 14px;">
  <h1 style="margin:0 0 6px;">Social Help</h1>
  <p class="muted" style="margin:0 0 14px;">Starter page for caption templates and quick copy blocks. We'll wire this into your real promo workflow later.</p>

  <div class="card" style="padding:14px;border-radius:16px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.18);">
    <h3 style="margin:0 0 10px;">Quick templates (editable)</h3>
    <div style="display:grid;gap:10px;">
      <textarea style="width:100%;min-height:96px;border-radius:12px;border:1px solid rgba(255,255,255,.16);background:rgba(0,0,0,.18);color:inherit;padding:10px;">Tonight: [Venue] • [Time]\nCome hang, bring your friends, and request your favorites.\n\n#ReadySetShows #LiveMusic</textarea>
      <textarea style="width:100%;min-height:96px;border-radius:12px;border:1px solid rgba(255,255,255,.16);background:rgba(0,0,0,.18);color:inherit;padding:10px;">Booking season is open. Need music for your party or event?\nDrop a date + venue and I’ll confirm availability.\n\n#ChicagoMusic #PrivateEvents</textarea>
    </div>
    <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px;">
      <a class="dash-btn" href="<?= h(BASE_URL) ?>/share.php">Share Link Tool</a>
      <a class="dash-btn" href="<?= h(BASE_URL) ?>/public_availability.php">Public Availability</a>
    </div>
  </div>
</div>

<?php page_footer(); ?>
