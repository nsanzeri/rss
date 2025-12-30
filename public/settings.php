<?php
require_once __DIR__ . "/_layout.php";

$u = require_login();
$pdo = db();

$flash = null;
$err = null;

if (!empty($_GET['email']) && $_GET['email'] === '1') {
    $flash = "Email updated successfully.";
}

if (!empty($_GET['deleted']) && $_GET['deleted'] === '1') {
    $flash = "Account deleted.";
}

function tz_options(string $current): string {
    $zones = DateTimeZone::listIdentifiers();
    $out = '';
    foreach ($zones as $z) {
        $sel = ($z === $current) ? ' selected' : '';
        $out .= '<option value="' . h($z) . '"' . $sel . '>' . h($z) . '</option>';
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $err = "Session expired. Please try again.";
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'update_profile') {
            $display = trim((string)($_POST['display_name'] ?? ''));
            $tz      = trim((string)($_POST['timezone'] ?? ''));

            if ($tz === '' || !in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                $err = "Please choose a valid timezone.";
            } else {
                $stmt = $pdo->prepare("UPDATE users SET display_name=?, timezone=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL");
                $stmt->execute([$display !== '' ? $display : null, $tz, (int)$u['id']]);
                $flash = "Settings saved.";
                // refresh $u for this request
                $u['display_name'] = $display;
                $u['timezone'] = $tz;
            }

        } elseif ($action === 'request_email_change') {
            $newEmail = trim(strtolower((string)($_POST['new_email'] ?? '')));

            if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $err = "Enter a valid email address.";
            } elseif ($newEmail === strtolower((string)$u['email'])) {
                $err = "That's already your email.";
            } else {
                // Always show success to avoid account enumeration / probing.
                email_change_create_and_email((int)$u['id'], $newEmail, BASE_URL, 60);
                $flash = "Check your inbox at " . h($newEmail) . " to confirm the change.";
            }

        } elseif ($action === 'change_password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new1    = (string)($_POST['new_password'] ?? '');
            $new2    = (string)($_POST['confirm_password'] ?? '');

            if ($new1 !== $new2) {
                $err = "New passwords do not match.";
            } elseif (strlen($new1) < 10) {
                $err = "New password must be at least 10 characters.";
            } else {
                // Fetch hash
                $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=? AND deleted_at IS NULL LIMIT 1");
                $stmt->execute([(int)$u['id']]);
                $row = $stmt->fetch();

                if (!$row || empty($row['password_hash']) || !password_verify($current, (string)$row['password_hash'])) {
                    $err = "Current password is incorrect.";
                } else {
                    $hash = password_hash($new1, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=? AND deleted_at IS NULL")
                        ->execute([$hash, (int)$u['id']]);
                    $flash = "Password updated.";
                }
            }

        } elseif ($action === 'delete_account') {
            $pw = (string)($_POST['password'] ?? '');
            $confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));

            if ($confirm !== 'DELETE') {
                $err = "Type DELETE to confirm.";
            } else {
                $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=? AND deleted_at IS NULL LIMIT 1");
                $stmt->execute([(int)$u['id']]);
                $row = $stmt->fetch();

                if (!$row || empty($row['password_hash']) || !password_verify($pw, (string)$row['password_hash'])) {
                    $err = "Password is incorrect.";
                } else {
                    $pdo->prepare("UPDATE users SET deleted_at=NOW(), updated_at=NOW() WHERE id=?")->execute([(int)$u['id']]);
                    auth_logout();
                    redirect("/login.php?deleted=1");
                }
            }
        }
    }
}

$pageTz = $u['timezone'] ?? 'America/Chicago';
$currentLocal = (new DateTimeImmutable('now', new DateTimeZone($pageTz)))->format('D M j, Y g:i A');

page_header('Settings');
?>

<div class="grid settings-wrap" style="gap:1.25rem;">
  <?php if ($flash): ?>
    <div class="alert success"><?= $flash ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert error"><?= h($err) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Profile</div>
        <div class="card-subtitle">Customize your profile, timezone, and preferences.</div>
      </div>
    </div>
    <form method="post" class="form" style="margin-top:1rem;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
      <input type="hidden" name="action" value="update_profile"/>

      <div class="form-field">
        <label class="form-label">Display Name</label>
        <input class="form-input" type="text" name="display_name" value="<?= h((string)($u['display_name'] ?? '')) ?>" maxlength="120"/>
      </div>

      <div class="form-field" style="margin-top:1rem;">
        <label class="form-label">Current Email</label>
        <input class="form-input" type="email" value="<?= h((string)$u['email']) ?>" disabled/>
      </div>

      <div class="form-field" style="margin-top:1rem;">
        <label class="form-label">Timezone</label>
        <select class="form-input" name="timezone">
          <?= tz_options((string)$pageTz) ?>
        </select>
        <div class="help" style="margin-top:.4rem;opacity:.8;">Current time in <?= h($pageTz) ?>: <strong><?= h($currentLocal) ?></strong></div>
      </div>

      <div style="margin-top:1rem;">
        <button class="btn" type="submit">Save Settings</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Change Email</div>
        <div class="card-subtitle">We'll email you a verification link to confirm.</div>
      </div>
    </div>
    <form method="post" class="form" style="margin-top:1rem;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
      <input type="hidden" name="action" value="request_email_change"/>

      <div class="form-field">
        <label class="form-label">New Email Address</label>
        <input class="form-input" type="email" name="new_email" placeholder="you@domain.com" required/>
      </div>

      <div style="margin-top:1rem;">
        <button class="btn" type="submit">Send Verification Link</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Change Password</div>
        <div class="card-subtitle">Update your password securely.</div>
      </div>
    </div>
    <form method="post" class="form" style="margin-top:1rem;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
      <input type="hidden" name="action" value="change_password"/>

      <div class="form-field">
        <label class="form-label">Current Password</label>
        <input class="form-input" type="password" name="current_password" required autocomplete="current-password"/>
      </div>

      <div class="form-field" style="margin-top:1rem;">
        <label class="form-label">New Password</label>
        <input class="form-input" type="password" name="new_password" required autocomplete="new-password"/>
      </div>

      <div class="form-field" style="margin-top:1rem;">
        <label class="form-label">Confirm New Password</label>
        <input class="form-input" type="password" name="confirm_password" required autocomplete="new-password"/>
      </div>

      <div style="margin-top:1rem;">
        <button class="btn" type="submit">Update Password</button>
      </div>
    </form>
  </div>

  <div class="card" style="border:1px solid rgba(255,0,0,.35);">
    <div class="card-header">
      <div>
        <div class="card-title" style="color:#b91c1c;">Danger Zone</div>
        <div class="card-subtitle">Deleting your account will permanently remove:</div>
      </div>
    </div>

    <div style="margin-top:.75rem;opacity:.9;">
      <ul style="margin:0 0 0 1.2rem;">
        <li>Your user profile</li>
        <li>All calendars you added</li>
        <li>All imported events</li>
        <li>Your login credentials</li>
      </ul>
    </div>

    <form method="post" class="form" style="margin-top:1rem;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
      <input type="hidden" name="action" value="delete_account"/>

      <div class="form-field">
        <label class="form-label">Password</label>
        <input class="form-input" type="password" name="password" required autocomplete="current-password"/>
      </div>

      <div class="form-field" style="margin-top:1rem;">
        <label class="form-label">Type DELETE to confirm</label>
        <input class="form-input" type="text" name="confirm" required/>
      </div>

      <div style="margin-top:1rem;">
        <button class="btn danger" type="submit">Delete My Account</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Preferences</div>
        <div class="card-subtitle">Coming soon.</div>
      </div>
    </div>
    <div style="margin-top:.75rem;">
      <label style="display:flex;gap:.5rem;align-items:center;opacity:.8;">
        <input type="checkbox" disabled/>
        Dark Mode (coming soon)
      </label>
      <label style="display:flex;gap:.5rem;align-items:center;margin-top:.5rem;opacity:.8;">
        <input type="checkbox" disabled/>
        Compact Layout (coming soon)
      </label>
    </div>
  </div>
</div>

<?php page_footer(); ?>
