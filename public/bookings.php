<?php
require_once __DIR__ . "/_layout.php";
require_login();

$tab = $_GET['tab'] ?? 'pipeline';
$allowed = ['pipeline','inquiries','pending','confirmed'];
if (!in_array($tab, $allowed, true)) {
    $tab = 'pipeline';
}

page_header('Bookings');
?>

<div class="container" style="max-width:1100px;margin:0 auto;padding:18px 14px;">
  <h1 style="margin:0 0 6px;">Bookings</h1>
  <p class="muted" style="margin:0 0 14px;">This is a starter page so your nav has somewhere real to land. We'll wire this into your actual booking pipeline next.</p>

  <div class="card" style="padding:14px;border-radius:16px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.18);">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;">
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="dash-btn" href="<?= h(BASE_URL) ?>/bookings.php">Pipeline</a>
        <a class="dash-btn" href="<?= h(BASE_URL) ?>/bookings.php?tab=inquiries">Inquiries</a>
        <a class="dash-btn" href="<?= h(BASE_URL) ?>/bookings.php?tab=pending">Pending</a>
        <a class="dash-btn" href="<?= h(BASE_URL) ?>/bookings.php?tab=confirmed">Confirmed</a>
      </div>
      <a class="dash-btn primary" href="#" onclick="return false;" title="Coming soon">+ New booking</a>
    </div>

    <hr style="border:none;border-top:1px solid rgba(255,255,255,.10);margin:14px 0;"/>

    <?php if ($tab === 'pipeline'): ?>
      <h2 style="margin:0 0 8px;font-size:18px;">Pipeline</h2>
      <p class="muted" style="margin:0;">Coming soon: lead stages, quick notes, and links into your calendar availability checker.</p>
    <?php elseif ($tab === 'inquiries'): ?>
      <h2 style="margin:0 0 8px;font-size:18px;">Inquiries</h2>
      <p class="muted" style="margin:0;">Coming soon: a simple inbox for messages, form submissions, and “hold” requests.</p>
    <?php elseif ($tab === 'pending'): ?>
      <h2 style="margin:0 0 8px;font-size:18px;">Pending</h2>
      <p class="muted" style="margin:0;">Coming soon: tentative dates, deposit status, and confirmation checklists.</p>
    <?php else: ?>
      <h2 style="margin:0 0 8px;font-size:18px;">Confirmed</h2>
      <p class="muted" style="margin:0;">Coming soon: confirmed shows with export/share actions.</p>
    <?php endif; ?>

    <div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:10px;">
      <a class="dash-btn" href="<?= h(BASE_URL) ?>/check_availability.php">Check Availability</a>
      <a class="dash-btn" href="<?= h(BASE_URL) ?>/public_availability.php">Public Availability Link</a>
      <a class="dash-btn" href="<?= h(BASE_URL) ?>/share.php">Share</a>
    </div>
  </div>
</div>

<?php page_footer(); ?>
