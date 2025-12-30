<?php
// Simple read-only metrics viewer (per-user) so you can verify events are landing
// without living inside phpMyAdmin.

require_once __DIR__ . "/_layout.php";
// Metrics is admin-only (admin/super user)
$u = require_admin();

page_header("Metrics");

$pdo = db();

// Filters (keep it simple)
$event = trim((string)($_GET['event'] ?? ''));
$userIdFilter = trim((string)($_GET['user_id'] ?? ''));
$userId = ($userIdFilter === '') ? null : (int)$userIdFilter;
$days  = (int)($_GET['days'] ?? 7);
if ($days < 1) $days = 1;
if ($days > 90) $days = 90;

$limit = (int)($_GET['limit'] ?? 100);
if ($limit < 25) $limit = 25;
if ($limit > 500) $limit = 500;

// Pull distinct user_ids for this window (for filter dropdown)
$stmt = $pdo->prepare("SELECT user_id, COUNT(*) AS c
    FROM events
    WHERE created_at >= (NOW() - INTERVAL ? DAY)
    GROUP BY user_id
    ORDER BY c DESC");
$stmt->execute([$days]);
$userBuckets = $stmt->fetchAll();

// Resolve emails for user_id buckets (best-effort)
$userEmails = [];
if ($userBuckets) {
  $ids = [];
  foreach ($userBuckets as $b) {
    if ($b['user_id'] === null) continue;
    $ids[] = (int)$b['user_id'];
  }
  $ids = array_values(array_unique($ids));
  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id IN ($in)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $ur) {
      $userEmails[(int)$ur['id']] = (string)($ur['email'] ?? '');
    }
  }
}

// Pull distinct event names (recent window, optionally filtered by user)
$stmt = $pdo->prepare("SELECT event_name, COUNT(*) AS c
    FROM events
    WHERE created_at >= (NOW() - INTERVAL ? DAY)
      AND ( ? IS NULL OR user_id = ? )
    GROUP BY event_name
    ORDER BY c DESC, event_name ASC");
$stmt->execute([$days, $userId, $userId]);
$eventTypes = $stmt->fetchAll();

// Summary
$stmt = $pdo->prepare("SELECT
    SUM(created_at >= (NOW() - INTERVAL 1 DAY)) AS c_24h,
    SUM(created_at >= (NOW() - INTERVAL 7 DAY)) AS c_7d,
    SUM(created_at >= (NOW() - INTERVAL 30 DAY)) AS c_30d
  FROM events
  WHERE ( ? IS NULL OR user_id = ? )");
$stmt->execute([$userId, $userId]);
$sum = $stmt->fetch() ?: [];

$where = "created_at >= (NOW() - INTERVAL :days DAY)";
$params = [':days' => $days];

if ($userId !== null) {
  $where .= " AND user_id = :uid";
  $params[':uid'] = $userId;
}

if ($event !== '') {
  $where .= " AND event_name = :event";
  $params[':event'] = strtolower($event);
}

$sql = "SELECT id, user_id, event_name, path, meta_json, ip, user_agent, created_at
        FROM events
        WHERE $where
        ORDER BY created_at DESC
        LIMIT $limit";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function fmt_dt($s): string {
  if (!$s) return "";
  return date('M j, Y g:ia', strtotime($s));
}

?>

<div class="app-grid" style="grid-template-columns: 1fr;">
  <section>
    <div class="card">
      <div class="card-header" style="display:flex; align-items:flex-start; justify-content:space-between; gap:1rem;">
        <div>
          <div class="card-title">Event stream</div>
          <div class="card-subtitle">Recent tracked events (read-only). Use filters to slice by user and event type.</div>
        </div>
        <div class="pill ghost">Last <?= (int)$days ?>d</div>
      </div>

      <div class="panel-grid" style="margin-top: 1rem;">
        <div class="metric">
          <div class="metric-label">Events (24h)</div>
          <div class="metric-value"><?= (int)($sum['c_24h'] ?? 0) ?></div>
          <div class="metric-tag">recent activity</div>
        </div>
        <div class="metric">
          <div class="metric-label">Events (7d)</div>
          <div class="metric-value"><?= (int)($sum['c_7d'] ?? 0) ?></div>
          <div class="metric-tag">week total</div>
        </div>
        <div class="metric">
          <div class="metric-label">Events (30d)</div>
          <div class="metric-value"><?= (int)($sum['c_30d'] ?? 0) ?></div>
          <div class="metric-tag">month total</div>
        </div>
        <div class="metric">
          <div class="metric-label">Top event</div>
          <div class="metric-value"><?= h($eventTypes[0]['event_name'] ?? '—') ?></div>
          <div class="metric-tag"><?= (int)($eventTypes[0]['c'] ?? 0) ?> hits</div>
        </div>
      </div>

      <form method="get" class="form" style="margin-top: 1rem;">
        <div class="form-grid" style="grid-template-columns: 2fr 2fr 1fr 1fr auto; align-items:end;">
          <div>
            <label>Event type</label>
            <select name="event" class="input">
              <option value="">All events</option>
              <?php foreach ($eventTypes as $et):
                $name = (string)($et['event_name'] ?? '');
                $c = (int)($et['c'] ?? 0);
              ?>
                <option value="<?= h($name) ?>" <?= ($event === $name ? 'selected' : '') ?>><?= h($name) ?> (<?= $c ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>User</label>
            <select name="user_id" class="input">
              <option value="">All users</option>
              <?php foreach ($userBuckets as $b):
                $bid = $b['user_id'];
                $count = (int)($b['c'] ?? 0);
                $email = ($bid === null) ? '' : (string)($userEmails[(int)$bid] ?? '');
                $label = ($bid === null) ? 'NULL (Anonymous)' : ((int)$bid . ($email ? (' • ' . $email) : ''));
                $val = ($bid === null) ? '0' : (string)(int)$bid;
                // We treat NULL separately; if you want to filter NULL later we can add a checkbox.
                if ($bid === null) continue;
              ?>
                <option value="<?= h((string)(int)$bid) ?>" <?= ($userId !== null && (int)$userId === (int)$bid ? 'selected' : '') ?>><?= h($label) ?> (<?= $count ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Window (days)</label>
            <input class="input" type="number" min="1" max="90" name="days" value="<?= (int)$days ?>" />
          </div>
          <div>
            <label>Limit</label>
            <input class="input" type="number" min="25" max="500" name="limit" value="<?= (int)$limit ?>" />
          </div>
          <div class="actions" style="display:flex; gap:.5rem; justify-content:flex-end;">
            <button class="btn btn-primary" type="submit">Apply</button>
            <a class="btn btn-ghost" href="<?= h(BASE_URL) ?>/metrics.php">Reset</a>
          </div>
        </div>
      </form>
    </div>

    <div class="card" style="margin-top: 1rem; overflow:hidden;">
      <div class="card-header">
        <div class="card-title">Recent events</div>
        <div class="card-subtitle">Newest first. Meta is stored as JSON.</div>
      </div>

      <div style="overflow:auto;">
        <table class="table" style="min-width: 980px;">
          <thead>
            <tr>
              <th style="width:180px;">When</th>
              <th style="width:220px;">User</th>
              <th style="width:200px;">Event</th>
              <th>Path</th>
              <th style="width:360px;">Meta</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="5" class="muted">No events found for this filter/window.</td></tr>
            <?php else: foreach ($rows as $r):
              $meta = (string)($r['meta_json'] ?? '');
              if (strlen($meta) > 320) $meta = substr($meta, 0, 320) . "…";
              $rid = $r['user_id'];
              if ($rid === null) {
                $uLabel = 'NULL (Anonymous)';
              } else {
                $email = (string)($userEmails[(int)$rid] ?? '');
                $uLabel = (int)$rid . ($email ? (' • ' . $email) : '');
              }
            ?>
              <tr>
                <td><?= h(fmt_dt($r['created_at'] ?? '')) ?></td>
                <td class="muted"><?= h($uLabel) ?></td>
                <td><span class="badge"><?= h($r['event_name'] ?? '') ?></span></td>
                <td class="muted"><?= h($r['path'] ?? '') ?></td>
                <td><code style="white-space:nowrap; display:block; overflow:hidden; text-overflow:ellipsis;"><?= h($meta) ?></code></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="hint" style="margin-top: 1rem;">
      Tip: If you want to see anonymous public traffic later, we can add an "Owner views" mode that aggregates events
      written under your user_id (like public availability views) and optionally counts user_id IS NULL for page views.
    </div>
  </section>
</div>

<?php page_footer(); ?>
