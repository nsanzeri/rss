<?php
require_once __DIR__ . "/../core/bootstrap.php";
require_once __DIR__ . "/_layout.php"; // for styles + footer/header helpers

$pdo = db();

$publicKey = $_GET['u'] ?? '';
$publicKey = preg_replace('/[^a-f0-9]/i', '', $publicKey);

$stmt = $pdo->prepare("SELECT id, display_name, email, timezone FROM users WHERE public_key=? LIMIT 1");
$stmt->execute([$publicKey]);
$user = $stmt->fetch();
if (!$user) {
    http_response_code(404);
    page_header("Not found");
    echo '<div class="card"><div class="card-body"><p class="muted">Invalid link.</p></div></div>';
    page_footer();
    exit;
}

$tz = new DateTimeZone($user['timezone'] ?? 'UTC');

$start = $_GET['start'] ?? date('Y-m-d');
$end   = $_GET['end'] ?? (new DateTimeImmutable('now', $tz))->modify('+30 day')->format('Y-m-d');
$days = $_GET['dow'] ?? ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
if (!is_array($days)) $days = [$days];

$startDt = new DateTimeImmutable($start . " 00:00:00", $tz);
$endDt   = new DateTimeImmutable($end . " 00:00:00", $tz);
if ($endDt < $startDt) { $tmp=$startDt; $startDt=$endDt; $endDt=$tmp; }

$calStmt = $pdo->prepare("SELECT id FROM user_calendars WHERE user_id=?");
$calStmt->execute([$user['id']]);
$calIds = array_map(fn($r)=>(int)$r['id'], $calStmt->fetchAll());
if (!$calIds) $calIds = [0];

$startUtc = $startDt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$endUtcExcl = $endDt->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$in = implode(',', array_fill(0, count($calIds), '?'));
$stmt = $pdo->prepare("SELECT start_utc, end_utc FROM calendar_events
    WHERE calendar_id IN ($in) AND status='busy' AND start_utc < ? AND end_utc > ?");
$stmt->execute(array_merge($calIds, [$endUtcExcl, $startUtc]));
$busy = $stmt->fetchAll();

$busyDates = [];
foreach ($busy as $b) {
    $s = new DateTimeImmutable($b['start_utc'], new DateTimeZone('UTC'));
    $e = new DateTimeImmutable($b['end_utc'], new DateTimeZone('UTC'));
    $sL = $s->setTimezone($tz);
    $eL = $e->setTimezone($tz);

    $cur = $sL->setTime(0,0,0);
    $endMark = $eL->setTime(0,0,0);
    while ($cur <= $endMark) {
        $busyDates[$cur->format('Y-m-d')] = true;
        $cur = $cur->modify('+1 day');
    }
}

$avail = [];
$cur = $startDt;
while ($cur <= $endDt) {
    $dow = $cur->format('D');
    if (in_array($dow, $days, true)) {
        $key = $cur->format('Y-m-d');
        if (empty($busyDates[$key])) $avail[] = $cur;
    }
    $cur = $cur->modify('+1 day');
}

$titleName = $user['display_name'] ?: "Availability";
page_header($titleName);
?>
<div class="card">
  <div class="card-body">
    <p class="muted">Availability for <?= h($user['display_name'] ?: $user['email']) ?></p>
    <p class="muted">Range: <?= h($startDt->format('M j, Y')) ?> → <?= h($endDt->format('M j, Y')) ?> (<?= h($tz->getName()) ?>)</p>
    <?php if (!$avail): ?>
      <p class="muted">No available dates in this range.</p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($avail as $d): ?>
          <li><?= h($d->format('D M j, Y')) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php page_footer(); ?>
