<?php
require_once __DIR__ . "/../core/bootstrap.php";

$pdo = db();

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  header("Location: " . BASE_URL . "/request.php");
  exit;
}

$stmt = $pdo->prepare("SELECT * FROM booking_requests WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$req = $stmt->fetch();

if (!$req) {
  http_response_code(404);
  echo "Request not found.";
  exit;
}

// Load invited targets
$inv = $pdo->prepare(
  "SELECT i.target_profile_id, i.target_type, i.priority, i.status, p.name, p.city, p.state
   FROM booking_invites i
   LEFT JOIN profiles p ON p.id=i.target_profile_id
   WHERE i.request_id=?
   ORDER BY i.priority ASC"
);
$inv->execute([$id]);
$invites = $inv->fetchAll() ?: [];

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Request Sent • <?= h2(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= h2(BASE_URL) ?>/assets/css/app.css"/>
</head>
<body>
<div class="public-shell">
  <div class="public-top">
    <a class="public-brand" href="<?= h2(BASE_URL) ?>/index.php?stay=1">Ready Set Shows</a>
    <div class="public-actions">
      <a class="btn btn-ghost" href="<?= h2(BASE_URL) ?>/search.php">Full Search</a>
      <a class="btn btn-ghost" href="<?= h2(BASE_URL) ?>/request.php">New Request</a>
    </div>
  </div>

  <main class="public-content">
    <div class="card">
      <div class="card-header">
        <h1 style="margin:0;">Request Sent</h1>
        <p class="muted" style="margin:.25rem 0 0;">Your booking request has been created (ID #<?= (int)$id ?>).</p>
      </div>
      <div class="card-body">

        <div class="grid" style="grid-template-columns: 1fr; gap: 1rem;">
          <div class="card" style="margin:0;">
            <div class="card-body">
              <div class="kvs" style="display:grid; grid-template-columns: 1fr 1fr; gap: .5rem 1rem;">
                <div><div class="muted">Date</div><div><strong><?= h2((string)($req['event_date'] ?? '')) ?></strong></div></div>
                <div><div class="muted">Time</div><div><strong><?= h2(trim(((string)($req['start_time'] ?? '')).(((string)($req['end_time'] ?? '')) ? ' – '.(string)$req['end_time'] : ''))) ?></strong></div></div>
                <div><div class="muted">Location</div><div><strong><?= h2(trim(((string)($req['venue_name'] ?? '')).(((string)($req['city'] ?? '')) ? ', '.(string)$req['city'] : '').(((string)($req['state'] ?? '')) ? ', '.(string)$req['state'] : ''))) ?></strong></div></div>
                <div><div class="muted">Status</div><div><strong><?= h2((string)($req['status'] ?? 'open')) ?></strong></div></div>
              </div>
              <?php if (!empty($req['notes'])): ?>
                <hr style="margin:1rem 0;"/>
                <div class="muted">Notes</div>
                <div><?= nl2br(h2((string)$req['notes'])) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div>
            <h3 style="margin:.25rem 0 .5rem;">Invited <?= ((string)($req['target_type'] ?? 'artist') === 'venue') ? 'Venues' : 'Artists' ?></h3>
            <?php if (empty($invites)): ?>
              <div class="muted">No invites found for this request.</div>
            <?php else: ?>
              <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px;">
                <?php foreach ($invites as $r): ?>
                  <div class="card" style="margin:0;">
                    <div class="card-body">
                      <div style="display:flex; gap:.75rem; align-items:flex-start; justify-content:space-between;">
                        <div>
                          <div style="font-weight:700;">#<?= (int)$r['priority'] ?> <?= h2((string)($r['name'] ?? ('Profile #'.(int)$r['target_profile_id']))) ?></div>
                          <div class="muted"><?= h2(trim(((string)($r['city'] ?? '')).(((string)($r['state'] ?? '')) ? ', '.(string)$r['state'] : ''))) ?></div>
                          <div class="muted">Status: <?= h2((string)($r['status'] ?? 'pending')) ?></div>
                        </div>
                        <a class="btn btn-ghost" href="<?= h2(BASE_URL) ?>/profile.php?id=<?= (int)$r['target_profile_id'] ?>">View</a>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div style="margin-top:1rem; display:flex; gap:.5rem; flex-wrap:wrap;">
          <a class="btn" href="<?= h2(BASE_URL) ?>/request.php">Create another request</a>
          <a class="btn btn-ghost" href="<?= h2(BASE_URL) ?>/index.php?stay=1">Back to Home</a>
        </div>

        <p class="muted" style="margin-top:.75rem;">Next step: we'll add an inbox so invited artists/venues can accept/decline, and then we can automate the fallback behavior.</p>

      </div>
    </div>
  </main>
</div>
</body>
</html>
