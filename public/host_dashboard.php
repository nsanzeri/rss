<?php
require_once __DIR__ . "/_layout.php";
$u = require_login();
$pdo = db();

page_header("Host Dashboard");

$rows = [];
try {
	$st = $pdo->prepare("
    SELECT r.id, r.event_date, r.event_title, r.venue_name, r.city, r.state, r.status, r.created_at
    FROM booking_requests r
    WHERE r.requester_user_id = ?
    ORDER BY r.created_at DESC
    LIMIT 10
  ");
	$st->execute([(int)$u['id']]);
	$rows = $st->fetchAll() ?: [];
} catch (Throwable $e) {
	echo '<div class="alert alert--error">Error loading requests: ' . h($e->getMessage()) . '</div>';
}
?>

<div class="content" style="max-width: 1060px; margin: 0 auto; padding: 16px;">
  <div class="card">
    <div class="card-body" style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <div>
        <h1 style="margin:0;">Host Dashboard</h1>
        <div class="muted" style="margin-top:.25rem;">Search artists/venues and send booking requests.</div>
      </div>
      <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
        <a class="pill" href="<?= h(BASE_URL) ?>/search.php">Discover</a>
        <a class="pill" href="<?= h(BASE_URL) ?>/request.php">New Request</a>
        <a class="pill" href="<?= h(BASE_URL) ?>/my_requests.php">My Requests</a>
      </div>
    </div>
  </div>

  <div style="height:12px;"></div>

  <div class="card">
    <div class="card-body">
      <h2 style="margin-top:0;">Recent Requests</h2>

      <?php if (empty($rows)): ?>
        <div class="muted">No requests yet. Create one to start the flow.</div>
      <?php else: ?>
        <div class="grid" style="grid-template-columns: 1fr; gap: 10px;">
          <?php foreach ($rows as $r): ?>
            <div class="card" style="margin:0;">
              <div class="card-body" style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                <div>
                  <div style="font-weight:700;">
                    <?= h($r['event_title'] ?: 'Booking Request') ?>
                    <span class="muted" style="font-weight:400;">#<?= (int)$r['id'] ?></span>
                  </div>
                  <div class="muted" style="margin-top:.15rem;">
                    <?= h($r['event_date']) ?>
                    <span class="dot">•</span>
                    <?= h(trim(($r['venue_name'] ?? '') . ' ' . ($r['city'] ?? '') . ' ' . ($r['state'] ?? ''))) ?>
                    <span class="dot">•</span>
                    Status: <?= h($r['status'] ?? 'open') ?>
                  </div>
                </div>
                <div style="display:flex; gap:.5rem;">
                  <a class="pill small" href="<?= h(BASE_URL) ?>/request_view.php?id=<?= (int)$r['id'] ?>">View</a>
                  <a class="pill small" href="<?= h(BASE_URL) ?>/request_sent.php?id=<?= (int)$r['id'] ?>">Invites</a>
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
