<?php
$HIDE_MENU = true;
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
<div class="card">
  <div class="card-body">
    <?php if ($err): ?><div class="alert"><?= h($err) ?></div><?php endif; ?>
    
    <div class="card form-card">
      <div class="card-header" style="display:flex; align-items:flex-start; justify-content:space-between; gap:1rem;">
        <div>
          <div class="card-title">Create account</div>
          <div class="card-subtitle">Start by adding calendars and generating your first availability list.</div>
        </div>
      </div>

      <?php if ($err): ?>
        <div class="alert error" style="margin-top: 1rem;"><?= h($err) ?></div>
      <?php endif; ?>

      <form method="post" class="auth-form" style="margin-top: 1rem;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>

        <div class="form-field">
          <label class="form-label" for="email">Email</label>
          <input class="form-input" id="email" name="email" type="email" required autocomplete="email"
                 placeholder="you@domain.com" value="<?= h($_POST['email'] ?? '') ?>"/>
        </div>

        <div class="form-field">
          <label class="form-label" for="password">Password</label>
          <input class="form-input" id="password" name="password" type="password" required autocomplete="new-password"
                 placeholder="Create a strong password"/>
        </div>

        <div class="auth-actions">
          <button class="btn btn-lg btn-primary" type="submit">Create account</button>
          <a class="btn btn-lg btn-secondary" href="<?= h(BASE_URL) ?>/login.php">I already have an account</a>
        </div>

        <div class="auth-switch">
          By creating an account, you agree this is a v1 tool and you’re the boss of your data.
        </div>
      </form>
    </div>
    
  </div>
</div>
<?php page_footer(); ?>
