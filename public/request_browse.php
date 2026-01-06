<?php
require_once __DIR__ . "/../core/bootstrap.php";

$pdo = db();

$type = $_GET['type'] ?? 'artist';
$type = ($type === 'venue') ? 'venue' : 'artist';

// Ensure draft exists (if session expired, allow browsing but prompt user to re-enter details)
$draft = $_SESSION['booking_request_draft'] ?? null;
$draft_missing = false;
if (!$draft) {
  $draft_missing = true;
  $_SESSION['booking_request_draft'] = [
    'target_type' => $type,
    'event_date' => '',
    'start_time' => '',
    'end_time' => '',
    'venue_name' => '',
    'venue_address' => '',
    'city' => '',
    'state' => '',
    'zip' => '',
    'budget_min' => '',
    'budget_max' => '',
    'notes' => '',
    'contact_name' => '',
    'contact_email' => '',
    'contact_phone' => '',
    'auto_fallback' => 0,
    'requester_profile_id' => 0,
    'requester_type' => 'guest',
  ];
  $draft = $_SESSION['booking_request_draft'];
}

if (!isset($_SESSION['booking_shortlist'])) {
  $_SESSION['booking_shortlist'] = ['artist'=>[], 'venue'=>[]];
}

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_validate($_POST['csrf'] ?? '');
  $pid = (int)($_POST['profile_id'] ?? 0);
  if ($pid > 0) {
    $list =& $_SESSION['booking_shortlist'][$type];
    if ($action === 'add') {
      if (!in_array($pid, $list, true)) $list[] = $pid;
    } elseif ($action === 'remove') {
      $list = array_values(array_filter($list, fn($x) => (int)$x !== $pid));
    }
  }
  // stay on page
  header("Location: " . BASE_URL . "/request_browse.php?type=" . urlencode($type));
  exit;
}

$shortlist_ids = $_SESSION['booking_shortlist'][$type] ?? [];

// Public profiles only (has a public calendar)
$profile_type = ($type === 'venue') ? 'venue' : 'band';

$sql = "
  SELECT p.id, p.name, p.profile_type,
         COALESCE(p.city, z.city) AS city,
         COALESCE(p.state, z.state) AS state,
         p.zip
  FROM profiles p
  LEFT JOIN zipcodes z ON z.zip = p.zip
  WHERE p.is_active = 1
    AND p.profile_type = :ptype
    AND EXISTS (
      SELECT 1
      FROM user_calendars uc
      WHERE uc.user_id = p.user_id
        AND uc.is_default = 1
        AND uc.deleted_at IS NULL
    )
  ORDER BY RAND()
  LIMIT 40
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':ptype'=>$profile_type]);
$rows = $stmt->fetchAll() ?: [];

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Browse <?= $type==='venue'?'Venues':'Artists' ?> • <?= h2(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= h2(BASE_URL) ?>/assets/css/app.css"/>
</head>
<body>
  <div class="app-shell" style="max-width: 1040px; margin: 0 auto; padding: 1.25rem;">
    <div class="card" style="margin-bottom: 1rem;">
      <div class="card-body">
        <?php if ($draft_missing): ?>
          <div class="alert" style="margin-bottom: 10px;">
            Your request details aren’t in the session (maybe you refreshed or your session expired). You can still build a shortlist, but you’ll need to re-enter request details before sending.
            <div style="margin-top:8px;">
              <a class="btn btn-primary" href="<?= h2(BASE_URL) ?>/request.php">Go to request form</a>
            </div>
          </div>
        <?php endif; ?>
        <div style="display:flex; gap:.75rem; align-items:center; justify-content:space-between; flex-wrap:wrap;">
          <div>
            <h1 style="margin:0;">Browse <?= $type==='venue'?'Venues':'Artists' ?></h1>
            <div class="muted" style="margin-top:.25rem;">Add a shortlist, then send your request.</div>
          </div>
          <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
            <a class="btn" href="<?= h2(BASE_URL) ?>/request_browse.php?type=artist">Artists</a>
            <a class="btn" href="<?= h2(BASE_URL) ?>/request_browse.php?type=venue">Venues</a>
            <a class="btn btn-primary" href="<?= h2(BASE_URL) ?>/request_review.php">Review & Send (<?= (int)count($shortlist_ids) ?>)</a>
          </div>
        </div>
      </div>
    </div>

    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: .75rem;">
      <?php foreach ($rows as $r):
        $pid = (int)$r['id'];
        $isAdded = in_array($pid, $shortlist_ids, true);
      ?>
        <div class="card">
          <div class="card-body">
            <div style="display:flex; justify-content:space-between; gap:.75rem;">
              <div>
                <div style="font-weight:700; font-size:1.05rem;">
                  <a href="<?= h2(BASE_URL) ?>/profile.php?id=<?= (int)$pid ?>" style="text-decoration:none; color:inherit;">
                    <?= h2($r['name'] ?? '') ?>
                  </a>
                </div>
                <div class="muted" style="margin-top:.15rem;">
                  <?= h2(trim(($r['city'] ?? '').( ($r['city']??'') && ($r['state']??'') ? ', ' : '').($r['state'] ?? ''))) ?>
                </div>
              </div>
              <form method="post" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= h2(csrf_token()) ?>"/>
                <input type="hidden" name="profile_id" value="<?= (int)$pid ?>"/>
                <?php if ($isAdded): ?>
                  <input type="hidden" name="action" value="remove"/>
                  <button class="btn" type="submit">Remove</button>
                <?php else: ?>
                  <input type="hidden" name="action" value="add"/>
                  <button class="btn btn-primary" type="submit">Add</button>
                <?php endif; ?>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top: 1rem; display:flex; justify-content:flex-end;">
      <a class="btn btn-primary" href="<?= h2(BASE_URL) ?>/request_review.php">Review & Send</a>
    </div>
  </div>
</body>
</html>
