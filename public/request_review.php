<?php
require_once __DIR__ . "/../core/bootstrap.php";

$pdo = db();

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$draft = $_SESSION['booking_request_draft'] ?? null;
$short = $_SESSION['booking_shortlist'] ?? ['artist'=>[], 'venue'=>[]];
if (!$draft) {
  header("Location: " . BASE_URL . "/request.php");
  exit;
}

$targets = $short[$draft['target_type'] ?? 'artist'] ?? [];
$targets = array_values(array_unique(array_map('intval', $targets)));

$error = '';
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_validate($_POST['csrf'] ?? '');

    if (empty($targets)) {
      throw new Exception("Please add at least one " . ($draft['target_type'] === 'venue' ? 'venue' : 'artist') . " to your request.");
    }

    // Update auto-fallback preference
    $draft['auto_fallback'] = !empty($_POST['auto_fallback']) ? 1 : 0;
    $_SESSION['booking_request_draft'] = $draft;

    // Insert booking_request
    $stmt = $pdo->prepare(
      "INSERT INTO booking_requests
        (requester_user_id, requester_profile_id, requester_type,
         contact_name, contact_email, contact_phone,
         event_title, event_date, start_time, end_time,
         venue_name, venue_address, city, state, zip,
         budget_min, budget_max, notes, auto_fallback, status)
       VALUES
        (:requester_user_id, :requester_profile_id, :requester_type,
         :contact_name, :contact_email, :contact_phone,
         :event_title, :event_date, :start_time, :end_time,
         :venue_name, :venue_address, :city, :state, :zip,
         :budget_min, :budget_max, :notes, :auto_fallback, 'open')"
    );

    $stmt->execute([
      ':requester_user_id' => $draft['requester_user_id'],
      ':requester_profile_id' => $draft['requester_profile_id'],
      ':requester_type' => $draft['requester_type'],
      ':contact_name' => $draft['contact_name'],
      ':contact_email' => $draft['contact_email'],
      ':contact_phone' => $draft['contact_phone'],
      ':event_title' => $draft['event_title'],
      ':event_date' => $draft['event_date'],
      ':start_time' => $draft['start_time'],
      ':end_time' => $draft['end_time'],
      ':venue_name' => $draft['venue_name'],
      ':venue_address' => $draft['venue_address'],
      ':city' => $draft['city'],
      ':state' => $draft['state'],
      ':zip' => $draft['zip'],
      ':budget_min' => $draft['budget_min'],
      ':budget_max' => $draft['budget_max'],
      ':notes' => $draft['notes'],
      ':auto_fallback' => (int)$draft['auto_fallback'],
    ]);

    $request_id = (int)$pdo->lastInsertId();

    // Insert invites
    $target_type = ($draft['target_type'] === 'venue') ? 'venue' : 'artist';
    $ins = $pdo->prepare(
      "INSERT INTO booking_invites (request_id, target_profile_id, target_type, priority, status, message)
       VALUES (:rid, :pid, :tt, :pri, 'pending', :msg)"
    );
    $pri = 1;
    foreach ($targets as $pid) {
      $ins->execute([
        ':rid' => $request_id,
        ':pid' => $pid,
        ':tt' => $target_type,
        ':pri' => $pri++,
        ':msg' => $draft['notes'],
      ]);
    }

    // Clear draft + shortlist
    unset($_SESSION['booking_request_draft'], $_SESSION['booking_shortlist']);

    header("Location: " . BASE_URL . "/request_sent.php?id=" . $request_id);
    exit;

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

// Load target profile cards
$cards = [];
if (!empty($targets)) {
  $in = implode(',', array_fill(0, count($targets), '?'));
  $stmt = $pdo->prepare("SELECT id, name, profile_type, city, state, zip FROM profiles WHERE id IN ($in) AND is_active=1 AND deleted_at IS NULL");
  $stmt->execute($targets);
  $tmp = $stmt->fetchAll() ?: [];
  // keep original order
  $map = [];
  foreach ($tmp as $r) $map[(int)$r['id']] = $r;
  foreach ($targets as $pid) if (isset($map[$pid])) $cards[] = $map[$pid];
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Review Request • <?= h2(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= h2(BASE_URL) ?>/assets/css/app.css"/>
</head>
<body>
<div class="public-shell">
  <div class="public-top">
    <a class="public-brand" href="<?= h2(BASE_URL) ?>/index.php?stay=1">Ready Set Shows</a>
    <div class="public-actions">
      <a class="btn btn-ghost" href="<?= h2(BASE_URL) ?>/request_browse.php?type=<?= h2(($draft['target_type']==='venue')?'venue':'artist') ?>">Back to Browse</a>
      <a class="btn btn-ghost" href="<?= h2(BASE_URL) ?>/search.php">Full Search</a>
    </div>
  </div>

  <main class="public-content">
    <div class="card">
      <div class="card-header">
        <h1 style="margin:0;">Review & Send</h1>
        <p class="muted" style="margin:0.25rem 0 0;">Your request will be sent to the profiles below.</p>
      </div>
      <div class="card-body">

        <?php if ($error): ?>
          <div class="alert alert-error"><?= h2($error) ?></div>
        <?php endif; ?>

        <div class="grid" style="grid-template-columns: 1fr; gap: 1rem;">
          <div class="card" style="margin:0;">
            <div class="card-body">
              <div class="kvs" style="display:grid; grid-template-columns: 1fr 1fr; gap: .5rem 1rem;">
                <div><div class="muted">Date</div><div><strong><?= h2($draft['event_date'] ?: 'TBD') ?></strong></div></div>
                <div><div class="muted">Time</div><div><strong><?= h2(($draft['start_time'] ?: 'TBD') . ($draft['end_time'] ? ' – '.$draft['end_time'] : '')) ?></strong></div></div>
                <div><div class="muted">Location</div><div><strong><?php $loc = trim(($draft['venue_name'] ?: '') . ($draft['city'] ? ', '.$draft['city'] : '') . ($draft['state'] ? ', '.$draft['state'] : '')); echo h2($loc !== '' ? $loc : 'TBD'); ?></strong></div></div>
                <div><div class="muted">Budget</div><div><strong><?= h2(($draft['budget_min']!==null || $draft['budget_max']!==null) ? ('$'.($draft['budget_min']??'0').'–$'.($draft['budget_max']??'')) : 'TBD') ?></strong></div></div>
              </div>
              <?php if (!empty($draft['notes'])): ?>
                <hr style="margin:1rem 0;"/>
                <div class="muted">Notes</div>
                <div><?= nl2br(h2($draft['notes'])) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div>
            <h3 style="margin:.25rem 0 .5rem;">Selected <?= $draft['target_type']==='venue' ? 'Venues' : 'Artists' ?> (<?= (int)count($cards) ?>)</h3>
            <?php if (empty($cards)): ?>
              <div class="muted">No targets selected yet.</div>
            <?php else: ?>
              <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px;">
                <?php foreach ($cards as $p): ?>
                  <div class="card" style="margin:0;">
                    <div class="card-body">
                      <div style="display:flex; gap:.75rem; align-items:flex-start; justify-content:space-between;">
                        <div>
                          <div style="font-weight:700;"><?= h2($p['name'] ?? '') ?></div>
                          <div class="muted"><?= h2(trim(($p['city'] ?? '').($p['state'] ? ', '.$p['state'] : ''))) ?></div>
                        </div>
                        <a class="btn btn-ghost" href="<?= h2(BASE_URL) ?>/profile.php?id=<?= (int)$p['id'] ?>">View</a>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <form method="post" style="margin-top:1rem;">
          <input type="hidden" name="csrf" value="<?= h2(csrf_token()) ?>"/>
          <label class="check" style="display:flex; gap:.5rem; align-items:center; margin:.75rem 0 1rem;">
            <input type="checkbox" name="auto_fallback" value="1" <?= !empty($draft['auto_fallback']) ? 'checked' : '' ?> />
            <span><strong>Auto-send to next choice</strong> if the first one is unavailable.</span>
          </label>
          <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
            <button class="btn" type="submit">Send Request</button>
            <a class="btn btn-ghost" href="<?= h2(BASE_URL) ?>/request_browse.php?type=<?= h2(($draft['target_type']==='venue')?'venue':'artist') ?>">Add / Remove Targets</a>
          </div>
          <p class="muted" style="margin-top:.75rem;">First pass: this creates the request + invites in the database. We'll add inbox + accept/decline + notifications next.</p>
        </form>

      </div>
    </div>
  </main>
</div>
</body>
</html>
