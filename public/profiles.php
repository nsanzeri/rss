<?php
require_once __DIR__ . "/_layout.php";
require_login();

// This project currently has a single profile editor at /profile.php.
// The nav expects /profiles.php and /profiles.php?new=1, so this page is a
// lightweight wrapper/landing until the multi-profile system is built.

$isNew = !empty($_GET['new']);

page_header('Profiles');
?>

<div class="container" style="max-width:1100px;margin:0 auto;padding:18px 14px;">
  <h1 style="margin:0 0 6px;">Profiles</h1>
  <p class="muted" style="margin:0 0 14px;">Starter page so the nav has real targets. We'll expand this into multi-profile management (artist, band, venue, client) next.</p>

  <div class="card" style="padding:14px;border-radius:16px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.18);">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;">
      <div>
        <h3 style="margin:0 0 6px;">My Profile</h3>
        <div class="muted" style="font-size:0.95rem;">Edit the current profile page (existing implementation).</div>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:10px;">
        <a class="dash-btn primary" href="<?= h(BASE_URL) ?>/profile.php">Open Profile Editor</a>
        <a class="dash-btn" href="<?= h(BASE_URL) ?>/settings.php">Settings</a>
      </div>
    </div>

    <?php if ($isNew): ?>
      <hr style="border:none;border-top:1px solid rgba(255,255,255,.10);margin:14px 0;"/>
      <h3 style="margin:0 0 8px;">Create Profile</h3>
      <p class="muted" style="margin:0;">This is a placeholder for “create profile”. For now, use the Profile Editor and we’ll convert it into a proper multi-profile flow when you’re ready.</p>
    <?php endif; ?>
  </div>
</div>

<?php page_footer(); ?>
