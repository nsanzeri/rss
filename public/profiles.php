<?php
require_once __DIR__ . "/_layout.php";
$u = require_login();

$pdo = db();
$flash = '';
$error = '';

function post_str(string $k): string {
  return trim((string)($_POST[$k] ?? ''));
}

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_validate($_POST['csrf'] ?? '');

    if ($action === 'create_profile') {
      $name = post_str('name');
      if ($name === '') throw new Exception("Name is required.");

      $stmt = $pdo->prepare("
        INSERT INTO profiles
          (user_id, profile_type, name, city, state, zip, genres, bio, website, is_active)
        VALUES
          (:user_id, :profile_type, :name, :city, :state, :zip, :genres, :bio, :website, :is_active)
      ");
      $stmt->execute([
        ':user_id' => $u['id'],
        ':profile_type' => post_str('profile_type') ?: 'artist',
        ':name' => $name,
        ':city' => post_str('city') ?: null,
        ':state' => post_str('state') ?: null,
        ':zip' => post_str('zip') ?: null,
        ':genres' => post_str('genres') ?: null,
        ':bio' => post_str('bio') ?: null,
        ':website' => post_str('website') ?: null,
        ':is_active' => !empty($_POST['is_active']) ? 1 : 0,
      ]);

      $flash = "Profile created.";
      redirect("/profiles.php");
    }

    if ($action === 'update_profile') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new Exception("Missing profile id.");

      $stmt = $pdo->prepare("SELECT id FROM profiles WHERE id=? AND user_id=? AND deleted_at IS NULL LIMIT 1");
      $stmt->execute([$id, $u['id']]);
      if (!$stmt->fetch()) throw new Exception("Profile not found.");

      $name = post_str('name');
      if ($name === '') throw new Exception("Name is required.");

      $stmt = $pdo->prepare("
        UPDATE profiles SET
          profile_type=:profile_type,
          name=:name,
          city=:city,
          state=:state,
          zip=:zip,
          genres=:genres,
          bio=:bio,
          website=:website,
          is_active=:is_active
        WHERE id=:id AND user_id=:user_id AND deleted_at IS NULL
      ");
      $stmt->execute([
        ':profile_type' => post_str('profile_type') ?: 'artist',
        ':name' => $name,
        ':city' => post_str('city') ?: null,
        ':state' => post_str('state') ?: null,
        ':zip' => post_str('zip') ?: null,
        ':genres' => post_str('genres') ?: null,
        ':bio' => post_str('bio') ?: null,
        ':website' => post_str('website') ?: null,
        ':is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ':id' => $id,
        ':user_id' => $u['id'],
      ]);

      $flash = "Profile updated.";
      redirect("/profiles.php");
    }

    if ($action === 'delete_profile') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new Exception("Missing profile id.");
      $stmt = $pdo->prepare("UPDATE profiles SET deleted_at=NOW(), is_active=0 WHERE id=? AND user_id=? AND deleted_at IS NULL");
      $stmt->execute([$id, $u['id']]);
      $flash = "Profile deleted.";
      redirect("/profiles.php");
    }

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$rows = [];
try {
  $stmt = $pdo->prepare("SELECT * FROM profiles WHERE user_id=? AND deleted_at IS NULL ORDER BY created_at DESC");
  $stmt->execute([$u['id']]);
  $rows = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
  $rows = [];
  if (!$error) $error = "Profiles table not found yet. Run scripts/create_bookings_and_profiles.sql in your DB, then refresh.";
}

$editId = (int)($_GET['edit'] ?? 0);
$isNew = !empty($_GET['new']);

$editing = null;
if ($editId > 0) {
  foreach ($rows as $r) {
    if ((int)$r['id'] === $editId) { $editing = $r; break; }
  }
}

page_header('Profiles');
?>

<div class="container" style="max-width:1100px;margin:0 auto;padding:18px 14px;">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div>
      <h1 style="margin:0 0 6px;">Profiles</h1>
      <p class="muted" style="margin:0;">Create the public profiles you want fans and venues to discover.</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <a class="dash-btn primary" href="<?= h(BASE_URL) ?>/profiles.php?new=1">+ New profile</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert success" style="margin-top:12px;"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert" style="margin-top:12px;"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($isNew || $editing): ?>
    <?php
      $p = $editing ?: [
        'id' => 0,
        'profile_type' => 'artist',
        'name' => '',
        'city' => '',
        'state' => '',
        'zip' => '',
        'genres' => '',
        'bio' => '',
        'website' => '',
        'is_active' => 1,
      ];
      $isEdit = $editing ? true : false;
    ?>
    <div class="card" style="margin-top:14px;">
      <div class="card-body">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">
          <h2 style="margin:0;"><?= $isEdit ? "Edit profile" : "New profile" ?></h2>
          <a class="pill" href="<?= h(BASE_URL) ?>/profiles.php">Close</a>
        </div>

        <form method="post" class="form" style="margin-top:12px;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="<?= $isEdit ? 'update_profile' : 'create_profile' ?>"/>
          <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>"/>
          <?php endif; ?>

          <div class="form-grid">
            <div>
              <label>Type</label>
              <select name="profile_type">
                <?php
                  $types = ['artist'=>'Artist','band'=>'Band','venue'=>'Venue','client'=>'Client'];
                  foreach ($types as $k=>$label):
                    $sel = (($p['profile_type'] ?? '') === $k) ? 'selected' : '';
                ?>
                  <option value="<?= h($k) ?>" <?= $sel ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="span-2">
              <label>Name</label>
              <input type="text" name="name" required value="<?= h($p['name'] ?? '') ?>" placeholder="Nick Sanzeri / Hooked On Sonics / ..."/>
            </div>

            <div>
              <label>City</label>
              <input type="text" name="city" value="<?= h($p['city'] ?? '') ?>"/>
            </div>
            <div>
              <label>State</label>
              <input type="text" name="state" value="<?= h($p['state'] ?? '') ?>"/>
            </div>
            <div>
              <label>ZIP</label>
              <input type="text" name="zip" value="<?= h($p['zip'] ?? '') ?>"/>
            </div>

            <div class="span-2">
              <label>Genres</label>
              <input type="text" name="genres" value="<?= h($p['genres'] ?? '') ?>" placeholder="rock, funk, jazz..."/>
            </div>

            <div class="span-2">
              <label>Website</label>
              <input type="url" name="website" value="<?= h($p['website'] ?? '') ?>" placeholder="https://..."/>
            </div>

            <div class="span-2">
              <label>Bio</label>
              <textarea name="bio" rows="5" placeholder="Short bio shown on search + profile pages."><?= h($p['bio'] ?? '') ?></textarea>
            </div>

            <div class="span-2" style="display:flex;align-items:center;gap:10px;">
              <label style="display:flex;align-items:center;gap:10px;margin:0;">
                <input type="checkbox" name="is_active" value="1" <?= !empty($p['is_active']) ? 'checked' : '' ?>/>
                <span>Active (discoverable)</span>
              </label>
              <span class="muted" style="font-size:12px;">Inactive profiles won’t appear in search or public view.</span>
            </div>
          </div>

          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
            <button class="dash-btn primary" type="submit"><?= $isEdit ? "Save changes" : "Create profile" ?></button>
            <a class="dash-btn" href="<?= h(BASE_URL) ?>/profiles.php">Cancel</a>

            <?php if ($isEdit): ?>
              <a class="pill" href="<?= h(BASE_URL) ?>/profile.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noreferrer">View public</a>
<?php endif; ?>
          </div>
      </form>

      <?php if ($isEdit): ?>
        <form method="post" style="margin-top:10px;" onsubmit="return confirm('Delete this profile? This will remove it from search.');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="delete_profile"/>
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>"/>
          <button class="dash-btn danger" type="submit">Delete profile</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

  <div class="card" style="margin-top:14px;">
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">
        <h2 style="margin:0;">Your profiles</h2>
        <div class="muted" style="font-size:13px;">Showing <?= count($rows) ?> item(s)</div>
      </div>

      <?php if (!$rows): ?>
        <p class="muted" style="margin:12px 0 0;">No profiles yet. Click “New profile” to create one.</p>
      <?php else: ?>
        <div style="overflow:auto;margin-top:12px;">
          <table class="table" style="width:100%;min-width:860px;">
            <thead>
              <tr>
                <th style="text-align:left;">Name</th>
                <th style="text-align:left;">Type</th>
                <th style="text-align:left;">Location</th>
                <th style="text-align:left;">Active</th>
                <th style="text-align:left;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td style="font-weight:700;"><?= h($r['name']) ?></td>
                <td><?= h($r['profile_type'] ?? '') ?></td>
                <td><?= h(trim(($r['city'] ?? '') . ' ' . ($r['state'] ?? '') . ' ' . ($r['zip'] ?? ''))) ?></td>
                <td><?= !empty($r['is_active']) ? 'Yes' : 'No' ?></td>
                <td style="display:flex;gap:8px;flex-wrap:wrap;">
                  <a class="pill" href="<?= h(BASE_URL) ?>/profiles.php?edit=<?= (int)$r['id'] ?>">Edit</a>
                  <a class="pill" href="<?= h(BASE_URL) ?>/profile.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noreferrer">View</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <div class="muted" style="margin-top:12px;font-size:12px;">
        Setup: run <code>scripts/create_bookings_and_profiles.sql</code> if needed.
      </div>
    </div>
  </div>
</div>

<?php page_footer(); ?>
