<?php
$HIDE_NAV = true;
require_once __DIR__ . "/_layout.php";

$err = null;
$msg = null;

if (!empty($_GET['deleted']) && $_GET['deleted'] === '1') {
    $msg = "Account deleted.";
}

if (!empty($_GET['reset']) && $_GET['reset'] === '1') {
    $msg = "Password updated. You can log in now.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $err = "Session expired. Please try again.";
    } else {
        [$ok, $msg] = auth_login_password($_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($ok) redirect("/dashboard.php");
        $err = $msg;
    }
}

page_header("Log in");
?>

<div class="auth-wrap">
  <div class="auth-card">
    <?php if ($msg): ?><div class="alert success" style="margin-bottom:12px;"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert error" style="margin-bottom:12px;"><?= h($err) ?></div><?php endif; ?>

    <h2 class="auth-title">Log in to Ready Set Shows</h2>

    <div class="provider-stack" aria-label="Continue with">
      <a class="provider-btn" href="<?= h(BASE_URL) ?>/google_login.php">
        <span class="provider-ico" aria-hidden="true">G</span>
        <span>Continue with Google</span>
      </a>
      <a class="provider-btn disabled" href="javascript:void(0)" aria-disabled="true" tabindex="-1">
        <span class="provider-ico" aria-hidden="true"></span>
        <span>Continue with Apple</span>
      </a>
      <a class="provider-btn disabled" href="javascript:void(0)" aria-disabled="true" tabindex="-1">
        <span class="provider-ico" aria-hidden="true">f</span>
        <span>Continue with Facebook</span>
      </a>
    </div>

    <div class="auth-divider"><span>or</span></div>

    <form method="post" class="auth-form">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>

      <div class="form-field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" required autocomplete="email"
               placeholder="Email" value="<?= h($_POST['email'] ?? '') ?>"/>
      </div>

      <div class="form-field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password"
               placeholder="Password"/>
      </div>

      <button class="btn primary btn-lg auth-submit" type="submit">Log In</button>

      <div class="auth-links">
        <a href="<?= h(BASE_URL) ?>/forgot_password.php">Forgot Password</a>
        <span class="muted">·</span>
        <a href="<?= h(BASE_URL) ?>/register.php">Create an account</a>
      </div>
    </form>
  </div>
</div>
<?php page_footer(); ?>
