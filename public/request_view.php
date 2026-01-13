<?php
require_once __DIR__ . "/_layout.php";
$u = require_login();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect("/my_requests.php");

$st = $pdo->prepare("SELECT * FROM booking_requests WHERE id=? AND requester_user_id=? LIMIT 1");
$st->execute([$id, (int)$u['id']]);
$req = $st->fetch();
if (!$req) { http_response_code(404); page_header("Not Found"); echo "<div class='content' style='padding:16px;'>Request not found.</div>"; page_footer(); exit; }

$inv = $pdo->prepare("
  SELECT i.*, p.name, p.city, p.state, p.profile_type
  FROM booking_invites i
  LEFT JOIN profiles p ON p.id = i.target_profile_id
  WHERE i.request_id=?
  ORDER BY i.priority ASC
");
$inv->execute([$id]);
$invites = $inv->fetchAll() ?: [];

page_header("Request #".$id);
?>

<div class="content" style="max-width: 1060px; margin: 0 auto; padding: 16px;">
  <div class="card">
    <div class="card-body" style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <div>
        <h1 style="margin:0;"><?= h($req['event_title'] ?: 'Booking Request') ?></h1>
        <div class="muted" style="margin-top:.25rem;">
          #<?= (int)$id ?> • Status: <?= h($req['status'] ?? 'open') ?>
        </div>
      </div>
      <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
        <a class="pill" href="<?= h(BASE_URL) ?>/my_requests.php">Back</a>
        <a class="pill" href="<?= h(BASE_URL) ?>/request.php">New Request</a>
      </div>
    </div>
  </div>

  <div style="height:12px;"></div>

  <div class="card">
    <div class="card-body">
      <div class="kvs" style="display:grid; grid-template-columns: 1fr 1fr; gap: .5rem 1rem;">
        <div><div class="muted">Date</div><div><strong><?= h($req['event_date'] ?? '') ?></strong></div></div>
        <div><div class="muted">Time</div><div><strong><?= h(trim(($req['start_time'] ?? '').(($req['end_time'] ?? '') ? ' – '.$req['end_time'] : ''))) ?></strong></div></div>
        <div><div class="muted">Location</div><div><strong><?= h(trim(($req['venue_name'] ?? '').(($req['city'] ?? '') ? ', '.$req['city'] : '').(($req['state'] ?? '') ? ', '.$req['state'] : ''))) ?></strong></div></div>
        <div><div class="muted">Budget</div><div><strong><?= h(trim(($req['budget_min'] ?? '').(($req['budget_max'] ?? '') ? ' – '.$req['budget_max'] : ''))) ?></strong></div></div>
      </div>

      <?php if (!empty($req['notes'])): ?>
        <hr style="margin:1rem 0;">
        <div class="muted">Notes</div>
        <div><?= nl2br(h((string)$req['notes'])) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div style="height:12px;"></div>

  <div class="card">
    <div class="card-body">
      <h2 style="margin-top:0;">Invites</h2>
      <?php if (empty($invites)): ?>
        <div class="muted">No invites found.</div>
      <?php else: ?>
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px;">
          <?php foreach ($invites as $r): ?>
            <div class="card" style="margin:0;">
              <div class="card-body">
                <div style="display:flex; justify-content:space-between; gap:12px;">
                  <div>
                    <div style="font-weight:700;">#<?= (int)$r['priority'] ?> <?= h($r['name'] ?? ('Profile #'.(int)$r['target_profile_id'])) ?></div>
                    <div class="muted"><?= h(trim(($r['city'] ?? '').(($r['state'] ?? '') ? ', '.$r['state'] : ''))) ?></div>
                    <div class="muted">Status: <?= h($r['status'] ?? 'pending') ?></div>
                  </div>
                  <a class="pill small" href="<?= h(BASE_URL) ?>/profile.php?id=<?= (int)$r['target_profile_id'] ?>">View</a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php page_footer(); ?>
