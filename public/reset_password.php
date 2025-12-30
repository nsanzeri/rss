<?php
$HIDE_NAV = true;
require_once __DIR__ . '/_layout.php';

$token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
$err = null;
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $err = 'Session expired. Please try again.';
    } else {
        $pw  = (string)($_POST['password'] ?? '');
        $pw2 = (string)($_POST['password_confirm'] ?? '');

        if ($pw !== $pw2) {
            $err = 'Passwords do not match.';
        } else {
            [$ok, $msg] = password_reset_consume($token, $pw);
            if ($ok) {
                // Force logged-out state, then redirect to login with a success message
                auth_logout();
                redirect('/login.php?reset=1');
            }
            $err = $msg;
        }
    }
}

// For GET requests, validate token to display a friendly error if it's invalid.
$valid = password_reset_find_valid($token);

page_header('Reset password');
?>

<div class="card">
  <div class="card-body">

    <div class="card form-card">
      <div class="card-header">
        <div class="card-title">Reset password</div>
        <div class="card-subtitle">Choose a new password for your account.</div>
      </div>

      <?php if ($err): ?>
        <div class="alert error" style="margin-top: 1rem;"><?= h($err) ?></div>
      <?php endif; ?>

      <?php if (!$valid): ?>
        <div class="alert" style="margin-top: 1rem;">
          This reset link is invalid or has expired.
        </div>
        <div style="margin-top: 1rem;">
          <a class="btn btn-secondary" href="<?= h(BASE_URL) ?>/forgot_password.php">Request a new link</a>
          <a class="btn btn-secondary" href="<?= h(BASE_URL) ?>/login.php" style="margin-left:.5rem;">Back to login</a>
        </div>
      <?php else: ?>
        <form method="post" class="auth-form" style="margin-top: 1rem;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
          <input type="hidden" name="token" value="<?= h($token) ?>"/>

          <div class="form-field">
            <label class="form-label" for="password">New password</label>
            <input class="form-input" id="password" name="password" type="password" required autocomplete="new-password"
                   placeholder="••••••••"/>
          </div>

          <div class="form-field">
            <label class="form-label" for="password_confirm">Confirm new password</label>
            <input class="form-input" id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password"
                   placeholder="••••••••"/>
          </div>

          <div class="auth-actions">
            <button class="btn btn-lg btn-primary" type="submit">Update password</button>
            <a class="btn btn-lg btn-secondary" href="<?= h(BASE_URL) ?>/login.php">Cancel</a>
          </div>
        </form>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php page_footer(); ?>
