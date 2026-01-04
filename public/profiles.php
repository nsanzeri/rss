<?php
require_once __DIR__ . "/_layout.php";
$u = require_login();

$pdo = db();
ensure_profile_tables($pdo);
$flash = '';
$error = '';

function post_str(string $k): string {
	return trim((string)($_POST[$k] ?? ''));
}


function profile_owned(PDO $pdo, int $profile_id, int $user_id): bool {
	$st = $pdo->prepare("SELECT 1 FROM profiles WHERE id=? AND user_id=? AND deleted_at IS NULL");
	$st->execute([$profile_id, $user_id]);
	return (bool)$st->fetchColumn();
}

function ensure_profile_tables(PDO $pdo): void {
	// Create media tables if missing (safe no-op if they already exist)
	$pdo->exec("
    CREATE TABLE IF NOT EXISTS profile_photos (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      profile_id BIGINT UNSIGNED NOT NULL,
      file_path VARCHAR(255) NOT NULL,
      is_primary TINYINT(1) NOT NULL DEFAULT 0,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (profile_id),
      CONSTRAINT fk_profile_photos_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");
	$pdo->exec("
    CREATE TABLE IF NOT EXISTS profile_youtube_links (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      profile_id BIGINT UNSIGNED NOT NULL,
      title VARCHAR(190) NULL,
      url VARCHAR(255) NOT NULL,
      youtube_id VARCHAR(32) NULL,
      is_featured TINYINT(1) NOT NULL DEFAULT 0,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (profile_id),
      INDEX (youtube_id),
      CONSTRAINT fk_profile_youtube_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");
}

function youtube_id_from_url(string $url): ?string {
	$url = trim($url);
	if ($url === '') return null;
	
	// youtu.be/<id>
	if (preg_match('~youtu\.be/([A-Za-z0-9_-]{6,})~', $url, $m)) return $m[1];
	
	// youtube.com/watch?v=<id>
	$parts = parse_url($url);
	if (!$parts) return null;
	$host = strtolower($parts['host'] ?? '');
	$path = $parts['path'] ?? '';
	$query = $parts['query'] ?? '';
	
	if (str_contains($host, 'youtube.com')) {
		parse_str($query, $qs);
		if (!empty($qs['v']) && preg_match('~^[A-Za-z0-9_-]{6,}$~', $qs['v'])) return $qs['v'];
		// /embed/<id>
		if (preg_match('~/embed/([A-Za-z0-9_-]{6,})~', $path, $m)) return $m[1];
		// /shorts/<id>
		if (preg_match('~/shorts/([A-Za-z0-9_-]{6,})~', $path, $m)) return $m[1];
	}
	return null;
}

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	try {
		csrf_validate($_POST['csrf'] ?? '');
		
		
		// --- Media: photos + YouTube links ---
		if ($action === 'upload_photo') {
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			if ($profile_id <= 0) throw new Exception("Missing profile id.");
			if (!profile_owned($pdo, $profile_id, (int)$u['id'])) throw new Exception("Profile not found.");
			
			if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
				throw new Exception("Please choose an image to upload.");
			}
			
			$f = $_FILES['photo'];
			if (($f['size'] ?? 0) > 5 * 1024 * 1024) throw new Exception("Image too large (max 5MB).");
			
			$allowed = [
					'image/jpeg' => 'jpg',
					'image/png'  => 'png',
					'image/webp' => 'webp',
			];
			
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mime  = finfo_file($finfo, $f['tmp_name']);
			finfo_close($finfo);
			if (!isset($allowed[$mime])) throw new Exception("Only JPG, PNG, or WEBP images are allowed.");
			
			$ext = $allowed[$mime];
			
			$relDir = "uploads/profiles/$profile_id";
			$absDir = __DIR__ . "/$relDir";
			if (!is_dir($absDir)) mkdir($absDir, 0755, true);
			
			$basename = "p_" . date('Ymd_His') . "_" . bin2hex(random_bytes(4)) . ".$ext";
			$relPath = "$relDir/$basename";
			$absPath = "$absDir/$basename";
			
			if (!move_uploaded_file($f['tmp_name'], $absPath)) throw new Exception("Upload failed.");
			
			// set sort order and primary
			$st = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM profile_photos WHERE profile_id=?");
			$st->execute([$profile_id]);
			$maxSort = (int)$st->fetchColumn();
			
			$st = $pdo->prepare("SELECT COUNT(*) FROM profile_photos WHERE profile_id=?");
			$st->execute([$profile_id]);
			$count = (int)$st->fetchColumn();
			$is_primary = ($count === 0) ? 1 : 0;
			
			$ins = $pdo->prepare("INSERT INTO profile_photos (profile_id, file_path, is_primary, sort_order) VALUES (?,?,?,?)");
			$ins->execute([$profile_id, $relPath, $is_primary, $maxSort + 10]);
			
			$flash = "Photo uploaded.";
			redirect("/profiles.php?edit=" . $profile_id);
		}
		
		if ($action === 'set_primary_photo') {
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			$photo_id = (int)($_POST['photo_id'] ?? 0);
			if ($profile_id <= 0 || $photo_id <= 0) throw new Exception("Missing photo info.");
			if (!profile_owned($pdo, $profile_id, (int)$u['id'])) throw new Exception("Profile not found.");
			
			$pdo->prepare("UPDATE profile_photos SET is_primary=0 WHERE profile_id=?")->execute([$profile_id]);
			$pdo->prepare("UPDATE profile_photos SET is_primary=1 WHERE id=? AND profile_id=?")->execute([$photo_id, $profile_id]);
			
			$flash = "Primary photo updated.";
			redirect("/profiles.php?edit=" . $profile_id);
		}
		
		if ($action === 'delete_photo') {
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			$photo_id = (int)($_POST['photo_id'] ?? 0);
			if ($profile_id <= 0 || $photo_id <= 0) throw new Exception("Missing photo info.");
			if (!profile_owned($pdo, $profile_id, (int)$u['id'])) throw new Exception("Profile not found.");
			
			$st = $pdo->prepare("SELECT file_path, is_primary FROM profile_photos WHERE id=? AND profile_id=?");
			$st->execute([$photo_id, $profile_id]);
			$row = $st->fetch();
			if ($row) {
				$pdo->prepare("DELETE FROM profile_photos WHERE id=? AND profile_id=?")->execute([$photo_id, $profile_id]);
				$abs = __DIR__ . "/" . ltrim((string)$row['file_path'], '/');
				if (is_file($abs)) @unlink($abs);
				
				if ((int)$row['is_primary'] === 1) {
					// promote first remaining
					$st2 = $pdo->prepare("SELECT id FROM profile_photos WHERE profile_id=? ORDER BY sort_order ASC, id ASC LIMIT 1");
					$st2->execute([$profile_id]);
					$newId = (int)($st2->fetchColumn() ?: 0);
					if ($newId > 0) $pdo->prepare("UPDATE profile_photos SET is_primary=1 WHERE id=?")->execute([$newId]);
				}
			}
			
			$flash = "Photo deleted.";
			redirect("/profiles.php?edit=" . $profile_id);
		}
		
		if ($action === 'add_youtube') {
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			if ($profile_id <= 0) throw new Exception("Missing profile id.");
			if (!profile_owned($pdo, $profile_id, (int)$u['id'])) throw new Exception("Profile not found.");
			
			$url = trim((string)($_POST['url'] ?? ''));
			if ($url === '') throw new Exception("YouTube URL is required.");
			$ytid = youtube_id_from_url($url);
			if (!$ytid) throw new Exception("That doesn’t look like a valid YouTube link.");
			
			$title = trim((string)($_POST['title'] ?? '')) ?: null;
			$is_featured = !empty($_POST['is_featured']) ? 1 : 0;
			
			$st = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM profile_youtube_links WHERE profile_id=?");
			$st->execute([$profile_id]);
			$maxSort = (int)$st->fetchColumn();
			
			$ins = $pdo->prepare("INSERT INTO profile_youtube_links (profile_id, title, url, youtube_id, is_featured, sort_order) VALUES (?,?,?,?,?,?)");
			$ins->execute([$profile_id, $title, $url, $ytid, $is_featured, $maxSort + 10]);
			
			$flash = "YouTube link added.";
			redirect("/profiles.php?edit=" . $profile_id);
		}
		
		if ($action === 'toggle_featured_youtube') {
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			$link_id = (int)($_POST['link_id'] ?? 0);
			if ($profile_id <= 0 || $link_id <= 0) throw new Exception("Missing YouTube info.");
			if (!profile_owned($pdo, $profile_id, (int)$u['id'])) throw new Exception("Profile not found.");
			
			$pdo->prepare("UPDATE profile_youtube_links SET is_featured = IF(is_featured=1,0,1) WHERE id=? AND profile_id=?")
			->execute([$link_id, $profile_id]);
			
			$flash = "Updated featured status.";
			redirect("/profiles.php?edit=" . $profile_id);
		}
		
		if ($action === 'delete_youtube') {
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			$link_id = (int)($_POST['link_id'] ?? 0);
			if ($profile_id <= 0 || $link_id <= 0) throw new Exception("Missing YouTube info.");
			if (!profile_owned($pdo, $profile_id, (int)$u['id'])) throw new Exception("Profile not found.");
			
			$pdo->prepare("DELETE FROM profile_youtube_links WHERE id=? AND profile_id=?")->execute([$link_id, $profile_id]);
			
			$flash = "YouTube link removed.";
			redirect("/profiles.php?edit=" . $profile_id);
		}
		
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

$photos = [];
$ytlinks = [];
if ($editing) {
	try {
		$st = $pdo->prepare("SELECT * FROM profile_photos WHERE profile_id=? ORDER BY is_primary DESC, sort_order ASC, id ASC");
		$st->execute([(int)$editing['id']]);
		$photos = $st->fetchAll() ?: [];
	} catch (Throwable $e) { $photos = []; }
	
	try {
		$st = $pdo->prepare("SELECT * FROM profile_youtube_links WHERE profile_id=? ORDER BY is_featured DESC, sort_order ASC, id ASC");
		$st->execute([(int)$editing['id']]);
		$ytlinks = $st->fetchAll() ?: [];
	} catch (Throwable $e) { $ytlinks = []; }
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
        <hr style="margin: 16px 0; border: none; border-top: 1px solid rgba(15,23,42,0.10);" />

        <h3 style="margin:0 0 10px 0;">Photos</h3>

        <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="upload_photo"/>
          <input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>"/>
          <div style="display:flex;flex-direction:column;gap:6px;min-width:260px;">
            <label style="font-size:12px;color:rgba(15,23,42,.7);">Add photo (JPG/PNG/WEBP, max 5MB)</label>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
          </div>
          <button class="dash-btn primary" type="submit">Upload</button>
        </form>

        <?php if (!empty($photos)): ?>
          <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
            <?php foreach ($photos as $ph): ?>
              <div style="width:120px;">
                <a href="<?= h(BASE_URL) ?>/<?= h($ph['file_path']) ?>" target="_blank" rel="noreferrer">
                  <img src="<?= h(BASE_URL) ?>/<?= h($ph['file_path']) ?>"
                       alt="photo"
                       style="width:120px;height:120px;object-fit:cover;border-radius:12px;border:1px solid rgba(15,23,42,0.12);" />
                </a>

                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">
                  <?php if ((int)$ph['is_primary'] === 1): ?>
                    <span class="pill" style="padding:3px 8px;">Primary</span>
                  <?php else: ?>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
                      <input type="hidden" name="action" value="set_primary_photo"/>
                      <input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>"/>
                      <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>"/>
                      <button class="pill" type="submit">Make primary</button>
                    </form>
                  <?php endif; ?>

                  <form method="post" style="display:inline;" onsubmit="return confirm('Delete this photo?');">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
                    <input type="hidden" name="action" value="delete_photo"/>
                    <input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>"/>
                    <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>"/>
                    <button class="pill" type="submit">Delete</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="muted" style="margin-top:10px;">No photos yet.</div>
        <?php endif; ?>

        <hr style="margin: 16px 0; border: none; border-top: 1px solid rgba(15,23,42,0.10);" />

        <h3 style="margin:0 0 10px 0;">YouTube links</h3>

        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="add_youtube"/>
          <input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>"/>

          <div style="display:flex;flex-direction:column;gap:6px;min-width:220px;">
            <label style="font-size:12px;color:rgba(15,23,42,.7);">Title (optional)</label>
            <input type="text" name="title" placeholder="Live at Moretti’s">
          </div>

          <div style="display:flex;flex-direction:column;gap:6px;min-width:320px;flex:1;">
            <label style="font-size:12px;color:rgba(15,23,42,.7);">YouTube URL</label>
            <input type="url" name="url" placeholder="https://www.youtube.com/watch?v=..." required>
          </div>

          <label style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
            <input type="checkbox" name="is_featured" value="1">
            <span style="font-size:13px;">Featured (show on public profile)</span>
          </label>

          <button class="dash-btn primary" type="submit">Add</button>
        </form>

        <?php if (!empty($ytlinks)): ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:12px;margin-top:12px;">
            <?php foreach ($ytlinks as $yt): ?>
              <div style="border:1px solid rgba(15,23,42,0.10);border-radius:14px;padding:12px;">
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:start;">
                  <div>
                    <div style="font-weight:700;"><?= h($yt['title'] ?: 'YouTube') ?></div>
                    <div class="muted" style="font-size:12px; word-break:break-all;"><?= h($yt['url']) ?></div>
                  </div>
                  <?php if ((int)$yt['is_featured'] === 1): ?>
                    <span class="pill" style="padding:3px 8px;">Featured</span>
                  <?php endif; ?>
                </div>

                <?php if (!empty($yt['youtube_id'])): ?>
                  <div style="margin-top:10px;aspect-ratio:16/9;">
                    <iframe width="100%" height="100%"
                      src="https://www.youtube.com/embed/<?= h($yt['youtube_id']) ?>"
                      title="YouTube video player"
                      frameborder="0"
                      allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                      allowfullscreen></iframe>
                  </div>
                <?php endif; ?>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
                    <input type="hidden" name="action" value="toggle_featured_youtube"/>
                    <input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>"/>
                    <input type="hidden" name="link_id" value="<?= (int)$yt['id'] ?>"/>
                    <button class="pill" type="submit"><?= (int)$yt['is_featured'] === 1 ? 'Unfeature' : 'Feature' ?></button>
                  </form>

                  <form method="post" style="display:inline;" onsubmit="return confirm('Delete this link?');">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
                    <input type="hidden" name="action" value="delete_youtube"/>
                    <input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>"/>
                    <input type="hidden" name="link_id" value="<?= (int)$yt['id'] ?>"/>
                    <button class="pill" type="submit">Delete</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="muted" style="margin-top:10px;">No YouTube links yet.</div>
        <?php endif; ?>
      <?php endif; ?>


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
