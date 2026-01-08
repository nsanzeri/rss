<?php
require_once __DIR__ . "/_layout.php";
$u = require_login();

$pdo = db();

// Inbox view: unified list of incoming booking invites + manual bookings.
$tab = $_GET['tab'] ?? 'all';
$allowed = ['all','open','quoted','declined','booked'];
if (!in_array($tab, $allowed, true)) $tab = 'all';

$q = trim((string)($_GET['q'] ?? ''));
$qLike = '%' . $q . '%';

function status_badge(string $key): array {
	// [label, cssClass]
	return match ($key) {
		'invite_pending'   => ['Pending', 'pill warn'],
		'invite_accepted'  => ['Quote sent', 'pill success'],
		'invite_declined'  => ['Declined lead', 'pill'],
		'invite_expired'   => ['No longer available', 'pill'],
		'invite_cancelled' => ['No longer available', 'pill'],
		'booking_inquiry'  => ['Inquiry', 'pill warn'],
		'booking_pending'  => ['Pending', 'pill warn'],
		'booking_confirmed'=> ['Booked', 'pill success'],
		'booking_canceled' => ['Canceled', 'pill'],
		default            => [ucfirst($key), 'pill'],
	};
}

function initials(string $name): string {
	$name = trim($name);
	if ($name === '') return '?';
	$parts = preg_split('/\s+/', $name);
	$a = strtoupper(substr($parts[0] ?? '', 0, 1));
	$b = strtoupper(substr($parts[1] ?? '', 0, 1));
	return $b ? ($a.$b) : $a;
}

// Fetch
$items = [];
try {
	// Incoming invites (the “leads”)
	$whereInvite = "p.user_id = :uid AND p.deleted_at IS NULL";
	if ($tab === 'open') $whereInvite .= " AND i.status='pending'";
	if ($tab === 'quoted') $whereInvite .= " AND i.status='accepted'";
	if ($tab === 'declined') $whereInvite .= " AND i.status='declined'";
	if ($tab === 'booked') $whereInvite .= " AND 1=0"; // invites are not bookings
	if ($q !== '') {
		$whereInvite .= " AND (r.event_title LIKE :q OR r.contact_name LIKE :q OR r.city LIKE :q OR r.state LIKE :q OR r.venue_name LIKE :q)";
	}
	
	$sqlInvite = "
    SELECT
      'invite' AS item_type,
      i.id AS item_id,
      i.status AS raw_status,
      i.sent_at AS created_at,
      r.event_date,
      r.event_title,
      r.city,
      r.state,
      r.contact_name,
      r.contact_email,
      r.budget_max,
      p.name AS profile_name
    FROM booking_invites i
    JOIN booking_requests r ON r.id = i.request_id
    JOIN profiles p ON p.id = i.target_profile_id
    WHERE $whereInvite
  ";
	
	$stmt = $pdo->prepare($sqlInvite);
	$params = [':uid' => $u['id']];
	if ($q !== '') $params[':q'] = $qLike;
	$stmt->execute($params);
	$invites = $stmt->fetchAll() ?: [];
	
	foreach ($invites as $r) {
		$k = match ($r['raw_status']) {
			'pending'   => 'invite_pending',
			'accepted'  => 'invite_accepted',
			'declined'  => 'invite_declined',
			'expired'   => 'invite_expired',
			'cancelled' => 'invite_cancelled',
			default     => 'invite_' . (string)$r['raw_status'],
		};
		$items[] = [
				'type' => 'invite',
				'id' => (int)$r['item_id'],
				'status_key' => $k,
				'created_at' => $r['created_at'],
				'event_date' => $r['event_date'],
				'title' => $r['event_title'] ?: 'Booking request',
				'subtitle' => trim(($r['city'] ?: '') . ((string)($r['state'] ?? '') ? ', ' . $r['state'] : '')),
				'contact_name' => $r['contact_name'] ?: '—',
				'contact_email' => $r['contact_email'] ?: '',
				'role' => $r['profile_name'] ? ('Profile: ' . $r['profile_name']) : '',
				'amount' => $r['budget_max'],
				'href' => BASE_URL . '/invite.php?invite_id=' . (int)$r['item_id'],
		];
	}
	
	// Manual bookings (confirmed/pending/inquiry)
	$whereBook = "b.user_id = :uid AND b.deleted_at IS NULL";
	if ($tab === 'open') $whereBook .= " AND b.status IN ('inquiry','pending')";
	if ($tab === 'quoted') $whereBook .= " AND 1=0"; // quotes are invites
	if ($tab === 'declined') $whereBook .= " AND 1=0";
	if ($tab === 'booked') $whereBook .= " AND b.status='confirmed'";
	if ($q !== '') {
		$whereBook .= " AND (b.event_title LIKE :q OR b.contact_name LIKE :q OR b.city LIKE :q OR b.state LIKE :q OR b.venue_name LIKE :q)";
	}
	
	$sqlBook = "
    SELECT
      'booking' AS item_type,
      b.id AS item_id,
      b.status AS raw_status,
      b.created_at,
      b.event_date,
      b.event_title,
      b.city,
      b.state,
      b.contact_name,
      b.contact_email,
      b.fee,
      p.name AS profile_name
    FROM bookings b
    LEFT JOIN profiles p ON p.id = b.profile_id
    WHERE $whereBook
  ";
	$stmt2 = $pdo->prepare($sqlBook);
	$params2 = [':uid' => $u['id']];
	if ($q !== '') $params2[':q'] = $qLike;
	$stmt2->execute($params2);
	$books = $stmt2->fetchAll() ?: [];
	foreach ($books as $r) {
		$k = match ($r['raw_status']) {
			'inquiry' => 'booking_inquiry',
			'pending' => 'booking_pending',
			'confirmed' => 'booking_confirmed',
			'canceled' => 'booking_canceled',
			default => 'booking_' . (string)$r['raw_status'],
		};
		$items[] = [
				'type' => 'booking',
				'id' => (int)$r['item_id'],
				'status_key' => $k,
				'created_at' => $r['created_at'],
				'event_date' => $r['event_date'],
				'title' => $r['event_title'] ?: 'Booking',
				'subtitle' => trim(($r['city'] ?: '') . ((string)($r['state'] ?? '') ? ', ' . $r['state'] : '')),
				'contact_name' => $r['contact_name'] ?: '—',
				'contact_email' => $r['contact_email'] ?: '',
				'role' => $r['profile_name'] ? ('Profile: ' . $r['profile_name']) : '',
				'amount' => $r['fee'],
				'href' => BASE_URL . '/bookings.php?tab=confirmed&edit=' . (int)$r['item_id'],
		];
	}
	
	// Order chronologically by event date, then created_at (newest first)
	usort($items, function($a, $b) {
		$ad = $a['event_date'] ?: '2099-12-31';
		$bd = $b['event_date'] ?: '2099-12-31';
		if ($ad === $bd) {
			return strcmp((string)$b['created_at'], (string)$a['created_at']);
		}
		return strcmp($ad, $bd);
	});
		
} catch (Throwable $e) {
	$items = [];
	$error = $e->getMessage();
}

page_header('Inbox');
?>

<div class="container" style="max-width:1100px;margin:0 auto;padding:18px 14px;">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div>
      <h1 style="margin:0 0 6px;">Inbox</h1>
      <p class="muted" style="margin:0;">Requests, quotes, and bookings in one place.</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <a class="dash-btn primary" href="<?= h(BASE_URL) ?>/bookings.php?new=1">+ New booking</a>
    </div>
  </div>

  <?php if (!empty($error ?? '')): ?>
    <div class="alert" style="margin-top:12px;"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="card" style="margin-top:14px;">
    <div class="card-body" style="padding:14px;">
      <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="tab" value="<?= h($tab) ?>"/>
        <div style="flex:1;min-width:240px;position:relative;">
          <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search inbox" style="padding-left:40px;"/>
          <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:.6;">🔍</span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <?php
            $tabs = [
              'all' => 'All',
              'open' => 'Open',
              'quoted' => 'Quotes sent',
              'declined' => 'Declined',
              'booked' => 'Booked',
            ];
            foreach ($tabs as $k => $label):
              $active = ($tab === $k) ? 'pill active' : 'pill';
              $href = h(BASE_URL) . '/inbox.php?tab=' . h($k) . ($q !== '' ? '&q=' . urlencode($q) : '');
          ?>
            <a class="<?= $active ?>" href="<?= $href ?>"><?= h($label) ?></a>
          <?php endforeach; ?>
        </div>
      </form>
    </div>

    <div style="border-top:1px solid rgba(255,255,255,.08);"></div>

    <div class="card-body" style="padding:0;">
      <?php if (empty($items)): ?>
        <div style="padding:18px;" class="muted">No inbox items.</div>
      <?php else: ?>
        <?php foreach ($items as $it):
          [$label, $cls] = status_badge((string)$it['status_key']);
          $dateLine = $it['event_date'] ? date('n/j/y', strtotime($it['event_date'])) : '';
          $meta = trim($it['subtitle'] . ($dateLine ? ' • ' . $dateLine : ''));
          $amount = ($it['amount'] !== null && $it['amount'] !== '') ? ('$' . number_format((float)$it['amount'], 2)) : '';
        ?>
          <div style="display:flex;gap:14px;align-items:center;padding:14px 16px;border-top:1px solid rgba(255,255,255,.06);">
            <div style="width:42px;height:42px;border-radius:999px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;font-weight:800;">
              <?= h(initials((string)$it['contact_name'])) ?>
            </div>

            <div style="flex:1;min-width:0;">
              <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap;">
                <div style="font-weight:800;"><?= h($it['contact_name']) ?></div>
                <?php if ($it['role']): ?><div class="muted" style="font-size:12px;"><?= h($it['role']) ?></div><?php endif; ?>
              </div>
              <div style="display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;">
                <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;">
                  <?= h($it['title']) ?>
                </div>
                <div class="muted" style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                  <?= h($meta) ?>
                </div>
              </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;">
              <?php if ($label === 'Booked' && $amount): ?>
                <span class="pill success"><?= h($label) ?> • <?= h($amount) ?></span>
              <?php else: ?>
                <a class="<?= h($cls) ?>" href="<?= h($it['href']) ?>"><?= h($label) ?></a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
