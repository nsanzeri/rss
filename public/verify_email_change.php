<?php
$HIDE_NAV = true; // minimal auth-style page (keeps it clean)
require_once __DIR__ . "/_layout.php";

$token = (string)($_GET['token'] ?? '');
[$ok, $err] = email_change_consume($token);

if ($ok) {
    // If logged in, refresh session user data by clearing cache
    // (auth_user uses static cache per request; next request will re-fetch)
    // Redirect to settings with flash
    redirect("/settings.php?email=1");
}

page_header('Email change');
?>

<div class="card auth-card" style="max-width:520px;margin:0 auto;">
  <div class="card-header">
    <div>
      <div class="card-title">Email change</div>
      <div class="card-subtitle"><?= h($err ?: 'Unable to confirm email change.') ?></div>
    </div>
  </div>
  <div style="margin-top:1rem;">
    <a class="btn" href="<?= h(BASE_URL) ?>/login.php">Go to login</a>
  </div>
</div>

<?php page_footer(); ?>
