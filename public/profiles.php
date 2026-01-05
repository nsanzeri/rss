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
		
		
		// ===========================
		// Media: Photos (1-to-many)
		// ===========================
		if ($action === 'upload_photo') {
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			if ($profile_id <= 0) throw new Exception("Missing profile id.");
			
			// Ensure profile belongs to user
			$stmt = $pdo->prepare("SELECT id FROM profiles WHERE id=? AND user_id=? AND deleted_at IS NULL");
			$stmt->execute([$profile_id, $u['id']]);
			if (!$stmt->fetchColumn()) throw new Exception("Profile not found.");
			
			if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
				throw new Exception("Please choose an image to upload.");
			}
			
			$f = $_FILES['photo'];
			if ($f['size'] > 5 * 1024 * 1024) throw new Exception("Image too large (max 5MB).");
			
			$allowed = [
					'image/jpeg' => 'jpg',
					'image/png'  => 'png',
					'image/webp' => 'webp',
			];
			
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mime = finfo_file($finfo, $f['tmp_name']);
			finfo_close($finfo);
			
			if (!isset($allowed[$mime])) throw new Exception("Only JPG, PNG, or WEBP images are allowed.");
			
			$ext = $allowed[$mime];
			
			$relDir = "uploads/profiles/" . $profile_id;
			$absDir = __DIR__ . "/" . $relDir;
			if (!is_dir($absDir)) mkdir($absDir, 0755, true);
			
			// Filename
			$token = bin2hex(random_bytes(8));
			$filename = "p_" . date('Ymd_His') . "_" . $token . "." . $ext;
			
			$absPath = $absDir . "/" . $filename;
			$relPath = $relDir . "/" . $filename;
			
			if (!move_uploaded_file($f['tmp_name'], $absPath)) throw new Exception("Upload failed. Please try again.");
			@chmod($absPath, 0644);
			
			// If no primary exists, make this primary
			$stmt = $pdo->prepare("SELECT id FROM profile_photos WHERE profile_id=? AND is_primary=1 LIMIT 1");
			$stmt->execute([$profile_id]);
			$hasPrimary = (bool)$stmt->fetchColumn();
			
			$stmt = $pdo->prepare("
    INSERT INTO profile_photos (profile_id, file_path, is_primary, created_at)
    VALUES (:pid, :path, :prim, NOW())
  ");
			$stmt->execute([
					':pid' => $profile_id,
					':path' => $relPath,
					':prim' => $hasPrimary ? 0 : 1,
			]);
			
			$flash = "Photo uploaded.";
			redirect("/profiles.php?edit=" . $profile_id);
		}
		
		if ($action === 'make_primary_photo') {
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			$photo_id   = (int)($_POST['photo_id'] ?? 0);
			if ($profile_id <= 0 || $photo_id <= 0) throw new Exception("Missing photo information.");
			
			// Ensure profile belongs to user and photo belongs to profile
			$stmt = $pdo->prepare("
    SELECT pp.id
    FROM profile_photos pp
    JOIN profiles p ON p.id = pp.profile_id
    WHERE pp.id=? AND pp.profile_id=? AND p.user_id=? AND p.deleted_at IS NULL
  ");
			$stmt->execute([$photo_id, $profile_id, $u['id']]);
			if (!$stmt->fetchColumn()) throw new Exception("Photo not found.");
			
			$pdo->beginTransaction();
			$pdo->prepare("UPDATE profile_photos SET is_primary=0 WHERE profile_id=?")->execute([$profile_id]);
			$pdo->prepare("UPDATE profile_photos SET is_primary=1 WHERE id=?")->execute([$photo_id]);
			$pdo->commit();
			
			$flash = "Primary photo updated.";
			redirect("/profiles.php?edit=" . $profile_id);
		}
		
		if ($action === 'delete_photo') {
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			$photo_id   = (int)($_POST['photo_id'] ?? 0);
			if ($profile_id <= 0 || $photo_id <= 0) throw new Exception("Missing photo information.");
			
			$stmt = $pdo->prepare("
    SELECT pp.file_path, pp.is_primary
    FROM profile_photos pp
    JOIN profiles p ON p.id = pp.profile_id
    WHERE pp.id=? AND pp.profile_id=? AND p.user_id=? AND p.deleted_at IS NULL
  ");
			$stmt->execute([$photo_id, $profile_id, $u['id']]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$row) throw new Exception("Photo not found.");
			
			$pdo->beginTransaction();
			$pdo->prepare("DELETE FROM profile_photos WHERE id=?")->execute([$photo_id]);
			
			// If we deleted the primary, promote the newest remaining photo to primary
			if ((int)$row['is_primary'] === 1) {
				$stmt = $pdo->prepare("SELECT id FROM profile_photos WHERE profile_id=? ORDER BY created_at DESC LIMIT 1");
				$stmt->execute([$profile_id]);
				$newId = (int)($stmt->fetchColumn() ?: 0);
				if ($newId > 0) {
					$pdo->prepare("UPDATE profile_photos SET is_primary=1 WHERE id=?")->execute([$newId]);
				}
			}
			$pdo->commit();
			
			// Best-effort delete file
			$abs = __DIR__ . "/" . ltrim((string)$row['file_path'], "/");
			if (is_file($abs)) @unlink($abs);
			
			$flash = "Photo deleted.";
			redirect("/profiles.php?edit=" . $profile_id);
		}
		
		// ===========================
		// Media: YouTube (1-to-many)
		// ===========================
		function extract_youtube_id(string $url): ?string {
			$url = trim($url);
			if ($url === '') return null;
			
			// Accept raw IDs (11 chars)
			if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $url)) return $url;
			
			// youtu.be/<id>
			if (preg_match('#youtu\.be/([a-zA-Z0-9_-]{11})#', $url, $m)) return $m[1];
			
			// youtube.com/watch?v=<id>
			if (preg_match('#v=([a-zA-Z0-9_-]{11})#', $url, $m)) return $m[1];
			
			// youtube.com/shorts/<id>
			if (preg_match('#/shorts/([a-zA-Z0-9_-]{11})#', $url, $m)) return $m[1];
			
			// youtube.com/embed/<id>
			if (preg_match('#/embed/([a-zA-Z0-9_-]{11})#', $url, $m)) return $m[1];
			
			return null;
		}
		
		if ($action === 'add_youtube') {
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			if ($profile_id <= 0) throw new Exception("Missing profile id.");
			
			$stmt = $pdo->prepare("SELECT id FROM profiles WHERE id=? AND user_id=? AND deleted_at IS NULL");
			$stmt->execute([$profile_id, $u['id']]);
			if (!$stmt->fetchColumn()) throw new Exception("Profile not found.");
			
			$title = post_str('yt_title');
			$url   = post_str('yt_url');
			$ytid  = extract_youtube_id($url);
			if (!$ytid) throw new Exception("That doesn't look like a valid YouTube link.");
			
			$is_featured = !empty($_POST['yt_featured']) ? 1 : 0;
			
			if ($is_featured) {
				$pdo->prepare("UPDATE profile_youtube_links SET is_featured=0 WHERE profile_id=?")->execute([$profile_id]);
			}
			
			$stmt = $pdo->prepare("
    INSERT INTO profile_youtube_links (profile_id, youtube_id, title, url, is_featured, created_at)
    VALUES (:pid, :ytid, :title, :url, :feat, NOW())
  ");
			$stmt->execute([
					':pid' => $profile_id,
					':ytid' => $ytid,
					':title' => $title ?: null,
					':url' => $url,
					':feat' => $is_featured,
			]);
			
			$flash = "Video added.";
			redirect("/profiles.php?edit=" . $profile_id);
		}
		
		if ($action === 'toggle_feature_youtube') {
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			$yt_id      = (int)($_POST['yt_id'] ?? 0);
			if ($profile_id <= 0 || $yt_id <= 0) throw new Exception("Missing video information.");
			
			$stmt = $pdo->prepare("
    SELECT pyl.id
    FROM profile_youtube_links pyl
    JOIN profiles p ON p.id = pyl.profile_id
    WHERE pyl.id=? AND pyl.profile_id=? AND p.user_id=? AND p.deleted_at IS NULL
  ");
			$stmt->execute([$yt_id, $profile_id, $u['id']]);
			if (!$stmt->fetchColumn()) throw new Exception("Video not found.");
			
			$pdo->beginTransaction();
			$pdo->prepare("UPDATE profile_youtube_links SET is_featured=0 WHERE profile_id=?")->execute([$profile_id]);
			$pdo->prepare("UPDATE profile_youtube_links SET is_featured=1 WHERE id=?")->execute([$yt_id]);
			$pdo->commit();
			
			$flash = "Featured video updated.";
			redirect("/profiles.php?edit=" . $profile_id);
		}
		
		if ($action === 'delete_youtube') {
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			$yt_id      = (int)($_POST['yt_id'] ?? 0);
			if ($profile_id <= 0 || $yt_id <= 0) throw new Exception("Missing video information.");
			
			$stmt = $pdo->prepare("
    DELETE pyl
    FROM profile_youtube_links pyl
    JOIN profiles p ON p.id = pyl.profile_id
    WHERE pyl.id=? AND pyl.profile_id=? AND p.user_id=? AND p.deleted_at IS NULL
  ");
			$stmt->execute([$yt_id, $profile_id, $u['id']]);
			
			$flash = "Video removed.";
			redirect("/profiles.php?edit=" . $profile_id);
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

<?php if ($isEdit): ?>
  <?php
    // Load media
    $photos = [];
    $videos = [];
    try {
      $stmt = $pdo->prepare("SELECT id, file_path, is_primary, created_at FROM profile_photos WHERE profile_id=? ORDER BY is_primary DESC, created_at DESC");
      $stmt->execute([(int)$p['id']]);
      $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

      $stmt = $pdo->prepare("SELECT id, youtube_id, title, url, is_featured, created_at FROM profile_youtube_links WHERE profile_id=? ORDER BY is_featured DESC, created_at DESC");
      $stmt->execute([(int)$p['id']]);
      $videos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
      // Tables might not exist yet; keep quiet on UI
    }
  ?>

  <div class="hr" style="margin:16px 0;"></div>

  <div style="display:grid;grid-template-columns:1fr;gap:14px;">
    <!-- Photos -->
    <div class="card" style="border:1px solid rgba(255,255,255,.08);border-radius:14px;">
      <div class="card-body">
        <h3 style="margin:0 0 10px;">Photos</h3>

        <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="upload_photo"/>
          <input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>"/>

          <div class="field" style="min-width:260px;">
            <label>Upload photo</label>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required/>
          </div>

          <button class="dash-btn primary" type="submit">Upload</button>
        </form>

        <?php if (!empty($photos)): ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-top:12px;">
            <?php foreach ($photos as $ph): ?>
              <?php $src = h(BASE_URL) . "/" . ltrim((string)$ph['file_path'], "/"); ?>
              <div style="border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:8px;">
                <img src="<?= h($src) ?>" alt="photo" style="width:100%;height:110px;object-fit:cover;border-radius:10px;display:block;"/>

                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap;">
                  <?php if ((int)$ph['is_primary'] === 1): ?>
                    <span class="pill" style="background:rgba(139,92,246,.22);border-color:rgba(139,92,246,.35);">Primary</span>
                  <?php else: ?>
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
                      <input type="hidden" name="action" value="make_primary_photo"/>
                      <input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>"/>
                      <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>"/>
                      <button class="pill" type="submit">Make Primary</button>
                    </form>
                  <?php endif; ?>

                  <form method="post" style="margin:0;" onsubmit="return confirm('Delete this photo?');">
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
          <p class="muted" style="margin-top:10px;">No photos yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- YouTube -->
    <div class="card" style="border:1px solid rgba(255,255,255,.08);border-radius:14px;">
      <div class="card-body">
        <h3 style="margin:0 0 10px;">YouTube Links</h3>

        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="add_youtube"/>
          <input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>"/>

          <div class="field" style="min-width:240px;">
            <label>Title</label>
            <input type="text" name="yt_title" placeholder="Optional"/>
          </div>

          <div class="field" style="min-width:340px;flex:1;">
            <label>YouTube URL</label>
            <input type="text" name="yt_url" placeholder="https://youtube.com/watch?v=..." required/>
          </div>

          <label style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid rgba(255,255,255,.10);border-radius:12px;">
            <input type="checkbox" name="yt_featured" value="1"/>
            <span>Featured</span>
          </label>

          <button class="dash-btn primary" type="submit">Add</button>
        </form>

        <?php if (!empty($videos)): ?>
          <div style="display:grid;grid-template-columns:1fr;gap:10px;margin-top:12px;">
            <?php foreach ($videos as $v): ?>
              <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px;">
                <div>
                  <div style="font-weight:600;"><?= h($v['title'] ?: 'YouTube Video') ?></div>
                  <div class="muted" style="font-size:12px;"><?= h($v['url']) ?></div>
                </div>

                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                  <?php if ((int)$v['is_featured'] === 1): ?>
                    <span class="pill" style="background:rgba(139,92,246,.22);border-color:rgba(139,92,246,.35);">Featured</span>
                  <?php else: ?>
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
                      <input type="hidden" name="action" value="toggle_feature_youtube"/>
                      <input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>"/>
                      <input type="hidden" name="yt_id" value="<?= (int)$v['id'] ?>"/>
                      <button class="pill" type="submit">Make Featured</button>
                    </form>
                  <?php endif; ?>

                  <form method="post" style="margin:0;" onsubmit="return confirm('Remove this video?');">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
                    <input type="hidden" name="action" value="delete_youtube"/>
                    <input type="hidden" name="profile_id" value="<?= (int)$p['id'] ?>"/>
                    <input type="hidden" name="yt_id" value="<?= (int)$v['id'] ?>"/>
                    <button class="pill" type="submit">Remove</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="muted" style="margin-top:10px;">No videos yet.</p>
        <?php endif; ?>

        <p class="muted" style="margin-top:10px;font-size:12px;">Tip: mark one video Featured to show it on the public profile page.</p>
      </div>
    </div>
  </div>
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
