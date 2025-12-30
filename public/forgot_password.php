<?php
$HIDE_NAV = true;
require_once __DIR__ . '/_layout.php';

$err = null;
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $err = 'Session expired. Please try again.';
    } else {
        $email = trim(strtolower((string)($_POST['email'] ?? '')));

        // Always respond success to avoid account enumeration.
        $sent = true;

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
            $stmt->execute([$email]);
            $u = $stmt->fetch();

            if ($u) {
                // BASE_URL already points at /public
                password_reset_create_and_email((int)$u['id'], $email, BASE_URL, 60);
            }
        }
    }
}

page_header('Forgot password');
?>

<div class="card">
  <div class="card-body">

    <div class="card form-card">
      <div class="card-header">
        <div class="card-title">Forgot your password?</div>
        <div class="card-subtitle">We’ll email you a reset link.</div>
      </div>

      <?php if ($err): ?>
        <div class="alert error" style="margin-top: 1rem;"><?= h($err) ?></div>
      <?php endif; ?>

      <?php if ($sent): ?>
        <div class="alert success" style="margin-top: 1rem;">
          If an account exists for that email, a reset link has been sent.
        </div>
        <div style="margin-top: 1rem;">
          <a class="btn btn-secondary" href="<?= h(BASE_URL) ?>/login.php">Back to login</a>
        </div>
      <?php else: ?>
        <form method="post" class="auth-form" style="margin-top: 1rem;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>

          <div class="form-field">
            <label class="form-label" for="email">Email</label>
            <input class="form-input" id="email" name="email" type="email" required autocomplete="email"
                   placeholder="you@domain.com" value="<?= h($_POST['email'] ?? '') ?>"/>
          </div>

          <div class="auth-actions">
            <button class="btn btn-lg btn-primary" type="submit">Send reset link</button>
            <a class="btn btn-lg btn-secondary" href="<?= h(BASE_URL) ?>/login.php">Cancel</a>
          </div>
        </form>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php page_footer(); ?>
