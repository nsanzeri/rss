<?php
$HIDE_NAV = true;
require_once __DIR__ . "/_layout.php";

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $err = "Session expired. Please try again.";
    } else {
        [$ok, $msg] = auth_register_password($_POST['email'] ?? '', $_POST['password'] ?? '', $_POST['display_name'] ?? null);
        if ($ok) redirect("/dashboard.php");
        $err = $msg;
    }
}

page_header("Create account");
?>
<div class="auth-wrap">
  <div class="auth-card">
    <?php if ($err): ?><div class="alert"><?= h($err) ?></div><?php endif; ?>
    
    <h2 class="auth-title">Create account</h2>
  <div class="provider-stack" aria-label="Continue with">
      <a class="provider-btn" href="<?= h(BASE_URL) ?>/google_login.php">
        <span class="provider-ico" aria-hidden="true">G</span>
        <span>Continue with Google</span>
      </a>
      <!-- a class="provider-btn disabled" href="javascript:void(0)" aria-disabled="true" tabindex="-1">
        <span class="provider-ico" aria-hidden="true"></span>
        <span>Continue with Apple</span>
      </a>
      <a class="provider-btn disabled" href="javascript:void(0)" aria-disabled="true" tabindex="-1">
        <span class="provider-ico" aria-hidden="true">f</span>
        <span>Continue with Facebook</span>
      </a-->
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
                placeholder="Create a strong password"/>
      </div>

        <div class="auth-actions">
          <button class="btn btn-lg btn-primary" type="submit">Create account</button>
          <a class="btn btn-lg btn-secondary" href="<?= h(BASE_URL) ?>/login.php">I already have an account</a>
        </div>
    </form>
    
  </div>
</div>
<?php page_footer(); ?>
