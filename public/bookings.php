<?php
require_once __DIR__ . "/_layout.php";
$u = require_login();

$tab = $_GET['tab'] ?? 'pipeline';
$allowed = ['pipeline','requests','inquiries','pending','confirmed'];
if (!in_array($tab, $allowed, true)) $tab = 'pipeline';

$pdo = db();
$flash = '';
$error = '';

function booking_status_label(string $s): string {
	return match ($s) {
		'inquiry' => 'Inquiry',
		'pending' => 'Pending',
		'confirmed' => 'Confirmed',
		'canceled' => 'Canceled',
		'accepted' => 'Accepted',
		'declined' => 'Declined',
		'expired' => 'Expired',
		'cancelled' => 'Cancelled',
		default => ucfirst($s),
	};
}

function post_str(string $k): string {
	return trim((string)($_POST[$k] ?? ''));
}

function normalize_status_for_tab(string $tab): string {
	return match ($tab) {
		'inquiries' => 'inquiry',
		'pending' => 'pending',
		'confirmed' => 'confirmed',
		default => 'inquiry',
	};
}

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	try {
		csrf_validate($_POST['csrf'] ?? '');
		
		
		// Respond to incoming booking invite (recipient-side)
		if ($action === 'respond_invite') {
			$invite_id = (int)($_POST['invite_id'] ?? 0);
			$decision = $_POST['decision'] ?? '';
			if ($invite_id <= 0) throw new Exception("Missing invite id.");
			if (!in_array($decision, ['accepted','declined'], true)) throw new Exception("Invalid decision.");
			
			$pdo->beginTransaction();
			try {
				// ownership check: invite must target one of my profiles
				$stmt = $pdo->prepare("
					SELECT i.*, r.event_title, r.event_date, r.start_time, r.end_time,
					       r.venue_name, r.venue_address, r.city, r.state, r.zip,
					       r.contact_name, r.contact_email, r.contact_phone, r.notes,
					       r.budget_max
					FROM booking_invites i
					JOIN booking_requests r ON r.id = i.request_id
					JOIN profiles p ON p.id = i.target_profile_id
					WHERE i.id = ? AND p.user_id = ? AND p.deleted_at IS NULL
					LIMIT 1
				");
				$stmt->execute([$invite_id, $u['id']]);
				$inv = $stmt->fetch();
				if (!$inv) throw new Exception("Invite not found.");
				
				// update invite status
				$upd = $pdo->prepare("UPDATE booking_invites SET status=?, responded_at=NOW() WHERE id=? LIMIT 1");
				$upd->execute([$decision, $invite_id]);
				
				// If accepted, create a booking (idempotent-ish)
				if ($decision === 'accepted') {
					// avoid duplicates: same user/profile/date/title/contact
					$chk = $pdo->prepare("
						SELECT id FROM bookings
						WHERE user_id=? AND profile_id <=> ? AND deleted_at IS NULL
						  AND COALESCE(event_date,'0000-00-00') <=> COALESCE(?, '0000-00-00')
						  AND event_title = ?
						  AND COALESCE(contact_email,'') = COALESCE(?, '')
						LIMIT 1
					");
					$chk->execute([
							$u['id'],
							$inv['target_profile_id'],
							$inv['event_date'] ?? null,
							$inv['event_title'] ?? 'Booking Request',
							$inv['contact_email'] ?? null,
					]);
					$existingId = (int)($chk->fetchColumn() ?: 0);
					
					if ($existingId <= 0) {
						$ins = $pdo->prepare("
							INSERT INTO bookings
							(user_id, profile_id, status, event_title, event_date, start_time, end_time,
							 venue_name, venue_address, city, state, zip,
							 contact_name, contact_email, contact_phone, fee, notes)
							VALUES
							(:uid, :pid, 'pending', :title, :edate, :st, :et,
							 :vname, :vaddr, :city, :state, :zip,
							 :cname, :cemail, :cphone, :fee, :notes)
						");
						$notes = trim(
								"From booking request #" . (int)$inv['request_id'] . "\n" .
								((string)($inv['notes'] ?? ''))
								);
						$ins->execute([
								':uid' => $u['id'],
								':pid' => $inv['target_profile_id'],
								':title' => $inv['event_title'] ?? 'Booking Request',
								':edate' => $inv['event_date'] ?? null,
								':st' => $inv['start_time'] ?? null,
								':et' => $inv['end_time'] ?? null,
								':vname' => $inv['venue_name'] ?? null,
								':vaddr' => $inv['venue_address'] ?? null,
								':city' => $inv['city'] ?? null,
								':state' => $inv['state'] ?? null,
								':zip' => $inv['zip'] ?? null,
								':cname' => $inv['contact_name'] ?? null,
								':cemail' => $inv['contact_email'] ?? null,
								':cphone' => $inv['contact_phone'] ?? null,
								':fee' => $inv['budget_max'] ?? null,
								':notes' => $notes ?: null,
						]);
					}
				}
				
				$pdo->commit();
				$flash = ($decision === 'accepted') ? 'Invite accepted.' : 'Invite declined.';
			} catch (Throwable $e) {
				$pdo->rollBack();
				throw $e;
			}
		}
		
		if ($action === 'create_booking') {
			$status = post_str('status') ?: normalize_status_for_tab($tab);
			$event_title = post_str('event_title');
			if ($event_title === '') throw new Exception("Event title is required.");
			
			$event_date = post_str('event_date') ?: null;
			$start_time = post_str('start_time') ?: null;
			$end_time   = post_str('end_time') ?: null;
			
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			if ($profile_id <= 0) $profile_id = null;
			
			$stmt = $pdo->prepare("
        INSERT INTO bookings
          (user_id, profile_id, status, event_title, event_date, start_time, end_time,
           venue_name, venue_address, city, state, zip,
           contact_name, contact_email, contact_phone,
           fee, deposit, notes)
        VALUES
          (:user_id, :profile_id, :status, :event_title, :event_date, :start_time, :end_time,
           :venue_name, :venue_address, :city, :state, :zip,
           :contact_name, :contact_email, :contact_phone,
           :fee, :deposit, :notes)
      ");
			$stmt->execute([
					':user_id' => $u['id'],
					':profile_id' => $profile_id,
					':status' => $status,
					':event_title' => $event_title,
					':event_date' => $event_date,
					':start_time' => $start_time,
					':end_time' => $end_time,
					':venue_name' => post_str('venue_name') ?: null,
					':venue_address' => post_str('venue_address') ?: null,
					':city' => post_str('city') ?: null,
					':state' => post_str('state') ?: null,
					':zip' => post_str('zip') ?: null,
					':contact_name' => post_str('contact_name') ?: null,
					':contact_email' => post_str('contact_email') ?: null,
					':contact_phone' => post_str('contact_phone') ?: null,
					':fee' => (post_str('fee') !== '' ? (float)post_str('fee') : null),
					':deposit' => (post_str('deposit') !== '' ? (float)post_str('deposit') : null),
					':notes' => post_str('notes') ?: null,
			]);
			
			$flash = "Booking created.";
		}
		
		if ($action === 'update_booking') {
			$id = (int)($_POST['id'] ?? 0);
			if ($id <= 0) throw new Exception("Missing booking id.");
			
			// ownership check
			$stmt = $pdo->prepare("SELECT id FROM bookings WHERE id=? AND user_id=? AND deleted_at IS NULL LIMIT 1");
			$stmt->execute([$id, $u['id']]);
			if (!$stmt->fetch()) throw new Exception("Booking not found.");
			
			$status = post_str('status') ?: 'inquiry';
			$event_title = post_str('event_title');
			if ($event_title === '') throw new Exception("Event title is required.");
			
			$event_date = post_str('event_date') ?: null;
			$start_time = post_str('start_time') ?: null;
			$end_time   = post_str('end_time') ?: null;
			
			$profile_id = (int)($_POST['profile_id'] ?? 0);
			if ($profile_id <= 0) $profile_id = null;
			
			$stmt = $pdo->prepare("
        UPDATE bookings SET
          profile_id=:profile_id,
          status=:status,
          event_title=:event_title,
          event_date=:event_date,
          start_time=:start_time,
          end_time=:end_time,
          venue_name=:venue_name,
          venue_address=:venue_address,
          city=:city,
          state=:state,
          zip=:zip,
          contact_name=:contact_name,
          contact_email=:contact_email,
          contact_phone=:contact_phone,
          fee=:fee,
          deposit=:deposit,
          notes=:notes
        WHERE id=:id AND user_id=:user_id AND deleted_at IS NULL
      ");
			$stmt->execute([
					':profile_id' => $profile_id,
					':status' => $status,
					':event_title' => $event_title,
					':event_date' => $event_date,
					':start_time' => $start_time,
					':end_time' => $end_time,
					':venue_name' => post_str('venue_name') ?: null,
					':venue_address' => post_str('venue_address') ?: null,
					':city' => post_str('city') ?: null,
					':state' => post_str('state') ?: null,
					':zip' => post_str('zip') ?: null,
					':contact_name' => post_str('contact_name') ?: null,
					':contact_email' => post_str('contact_email') ?: null,
					':contact_phone' => post_str('contact_phone') ?: null,
					':fee' => (post_str('fee') !== '' ? (float)post_str('fee') : null),
					':deposit' => (post_str('deposit') !== '' ? (float)post_str('deposit') : null),
					':notes' => post_str('notes') ?: null,
					':id' => $id,
					':user_id' => $u['id'],
			]);
			
			$flash = "Booking updated.";
		}
		
		if ($action === 'delete_booking') {
			$id = (int)($_POST['id'] ?? 0);
			if ($id <= 0) throw new Exception("Missing booking id.");
			$stmt = $pdo->prepare("UPDATE bookings SET deleted_at=NOW() WHERE id=? AND user_id=? AND deleted_at IS NULL");
			$stmt->execute([$id, $u['id']]);
			$flash = "Booking deleted.";
		}
		
	} catch (Throwable $e) {
		$error = $e->getMessage();
	}
}

// profiles for dropdown
$profiles = [];
try {
	$stmt = $pdo->prepare("SELECT id, name FROM profiles WHERE user_id=? AND deleted_at IS NULL ORDER BY created_at DESC");
	$stmt->execute([$u['id']]);
	$profiles = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
	// profiles table might not exist yet; keep dropdown empty
	$profiles = [];
}

// which bookings to fetch
$where = "b.user_id = :uid AND b.deleted_at IS NULL";
$params = [':uid' => $u['id']];

if ($tab === 'inquiries') {
	$where .= " AND b.status = 'inquiry'";
} elseif ($tab === 'pending') {
	$where .= " AND b.status = 'pending'";
} elseif ($tab === 'confirmed') {
	$where .= " AND b.status = 'confirmed'";
}

$rows = [];
try {
	if ($tab === 'requests') {
		// Incoming booking requests (invites targeting any of my profiles)
		$stmt = $pdo->prepare("
      SELECT
        i.id AS invite_id,
        i.request_id,
        i.status,
        i.sent_at AS created_at,
        p.name AS profile_name,
        r.event_title,
        r.event_date,
        r.start_time,
        r.end_time,
        r.venue_name,
        r.venue_address,
        r.city,
        r.state,
        r.zip,
        r.contact_name,
        r.contact_email,
        r.contact_phone,
        r.budget_max AS fee,
        CONCAT_WS('\n', r.notes, i.message) AS notes
      FROM booking_invites i
      JOIN booking_requests r ON r.id = i.request_id
      JOIN profiles p ON p.id = i.target_profile_id
      WHERE p.user_id = :uid
        AND p.deleted_at IS NULL
        AND r.status = 'open'
      ORDER BY
        CASE i.status
          WHEN 'pending' THEN 1
          WHEN 'accepted' THEN 2
          WHEN 'declined' THEN 3
          WHEN 'expired' THEN 4
          WHEN 'cancelled' THEN 5
          ELSE 9
        END,
        COALESCE(r.event_date, '2099-12-31') ASC,
        i.sent_at DESC
    ");
		echo '<pre>';
		$stmt->debugDumpParams();
		echo '</pre>';
		$stmt->execute([':uid' => $u['id']]);
		$rows = $stmt->fetchAll() ?: [];
	} else {
		$stmt = $pdo->prepare("
      SELECT b.*, p.name AS profile_name
      FROM bookings b
      LEFT JOIN profiles p ON p.id = b.profile_id
      WHERE $where
      ORDER BY
        CASE b.status
          WHEN 'inquiry' THEN 1
          WHEN 'pending' THEN 2
          WHEN 'confirmed' THEN 3
          WHEN 'canceled' THEN 4
          ELSE 9
        END,
        COALESCE(b.event_date, '2099-12-31') ASC,
        b.created_at DESC
    ");
		echo '<pre>';
		$stmt->debugDumpParams();
		echo '</pre>';
		$stmt->execute($params);
		$rows = $stmt->fetchAll() ?: [];
	}
} catch (Throwable $e) {
	$rows = [];
	if (!$error) {
		$error = $e->getMessage();
		//    $error = "Bookings table not found yet. Run scripts/create_bookings_and_profiles.sql in your DB, then refresh.";
	}
}

$editId = ($tab === 'requests') ? 0 : (int)($_GET['edit'] ?? 0);
$editing = null;
if ($editId > 0) {
	foreach ($rows as $r) {
		if ((int)$r['id'] === $editId) { $editing = $r; break; }
	}
}

$showNew = ($tab === 'requests') ? false : !empty($_GET['new']);

page_header('Bookings');
?>

<div class="container" style="max-width:1100px;margin:0 auto;padding:18px 14px;">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div>
      <h1 style="margin:0 0 6px;">Bookings</h1>
      <p class="muted" style="margin:0;">Track inquiries → pending → confirmed, with dates, contacts, and notes.</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <?php if ($tab !== 'requests'): ?>
      <a class="dash-btn primary" href="<?= h(BASE_URL) ?>/bookings.php?tab=<?= h($tab) ?>&new=1">+ New booking</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert success" style="margin-top:12px;"><?= h($flash) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert" style="margin-top:12px;"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($showNew || $editing): ?>
    <?php
      $b = $editing ?: [
        'id' => 0,
        'profile_id' => '',
        'status' => normalize_status_for_tab($tab),
        'event_title' => '',
        'event_date' => '',
        'start_time' => '',
        'end_time' => '',
        'venue_name' => '',
        'venue_address' => '',
        'city' => '',
        'state' => '',
        'zip' => '',
        'contact_name' => '',
        'contact_email' => '',
        'contact_phone' => '',
        'fee' => '',
        'deposit' => '',
        'notes' => '',
      ];
      $isEdit = $editing ? true : false;
    ?>
    <div class="card" style="margin-top:14px;">
      <div class="card-body">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">
          <h2 style="margin:0;"><?= $isEdit ? "Edit booking" : "New booking" ?></h2>
          <a class="pill" href="<?= h(BASE_URL) ?>/bookings.php?tab=<?= h($tab) ?>">Close</a>
        </div>

        <form method="post" class="form" style="margin-top:12px;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="<?= $isEdit ? 'update_booking' : 'create_booking' ?>"/>
          <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>"/>
          <?php endif; ?>

          <div class="form-grid">
            <div class="span-2">
              <label>Event title</label>
              <input type="text" name="event_title" required value="<?= h($b['event_title'] ?? '') ?>" placeholder="Nick @ Venue / Private Party / Wedding..."/>
            </div>

            <div>
              <label>Status</label>
              <select name="status">
                <?php
                  $opts = ['inquiry','pending','confirmed','canceled'];
                  foreach ($opts as $s):
                    $sel = (($b['status'] ?? '') === $s) ? 'selected' : '';
                ?>
                  <option value="<?= h($s) ?>" <?= $sel ?>><?= h(booking_status_label($s)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label>Profile</label>
              <select name="profile_id">
                <option value="">(none)</option>
                <?php foreach ($profiles as $p): ?>
                  <option value="<?= (int)$p['id'] ?>" <?= ((string)($b['profile_id'] ?? '') === (string)$p['id']) ? 'selected' : '' ?>>
                    <?= h($p['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="muted" style="margin-top:6px;font-size:12px;">Tip: create profiles first if you want to associate bookings.</div>
            </div>

            <div>
              <label>Date</label>
              <input type="date" name="event_date" value="<?= h($b['event_date'] ?? '') ?>"/>
            </div>

            <div>
              <label>Start</label>
              <input type="time" name="start_time" value="<?= h($b['start_time'] ?? '') ?>"/>
            </div>

            <div>
              <label>End</label>
              <input type="time" name="end_time" value="<?= h($b['end_time'] ?? '') ?>"/>
            </div>

            <div class="span-2">
              <label>Venue name</label>
              <input type="text" name="venue_name" value="<?= h($b['venue_name'] ?? '') ?>" placeholder="Moretti's / Private Residence / ..."/>
            </div>

            <div class="span-2">
              <label>Venue address</label>
              <input type="text" name="venue_address" value="<?= h($b['venue_address'] ?? '') ?>" placeholder="Street, city, etc. (optional)"/>
            </div>

            <div>
              <label>City</label>
              <input type="text" name="city" value="<?= h($b['city'] ?? '') ?>"/>
            </div>
            <div>
              <label>State</label>
              <input type="text" name="state" value="<?= h($b['state'] ?? '') ?>"/>
            </div>
            <div>
              <label>ZIP</label>
              <input type="text" name="zip" value="<?= h($b['zip'] ?? '') ?>"/>
            </div>

            <div>
              <label>Contact name</label>
              <input type="text" name="contact_name" value="<?= h($b['contact_name'] ?? '') ?>"/>
            </div>
            <div>
              <label>Contact email</label>
              <input type="email" name="contact_email" value="<?= h($b['contact_email'] ?? '') ?>"/>
            </div>
            <div>
              <label>Contact phone</label>
              <input type="text" name="contact_phone" value="<?= h($b['contact_phone'] ?? '') ?>"/>
            </div>

            <div>
              <label>Fee</label>
              <input type="number" step="0.01" name="fee" value="<?= h((string)($b['fee'] ?? '')) ?>" placeholder="0.00"/>
            </div>
            <div>
              <label>Deposit</label>
              <input type="number" step="0.01" name="deposit" value="<?= h((string)($b['deposit'] ?? '')) ?>" placeholder="0.00"/>
            </div>

            <div class="span-2">
              <label>Notes</label>
              <textarea name="notes" rows="4" placeholder="Notes, stage plot, load-in, follow-ups..."><?= h($b['notes'] ?? '') ?></textarea>
            </div>
          </div>

          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
            <button class="dash-btn primary" type="submit"><?= $isEdit ? "Save changes" : "Create booking" ?></button>
            <a class="dash-btn" href="<?= h(BASE_URL) ?>/bookings.php?tab=<?= h($tab) ?>">Cancel</a>

            <?php if ($isEdit): ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Delete this booking?');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
                <input type="hidden" name="action" value="delete_booking"/>
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>"/>
                <button class="dash-btn danger" type="submit">Delete</button>
              </form>
            <?php endif; ?>
          </div>
      </form>

      <?php if ($isEdit): ?>
        <form method="post" style="margin-top:10px;" onsubmit="return confirm('Delete this booking?');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="delete_booking"/>
          <input type="hidden" name="id" value="<?= (int)$b['id'] ?>"/>
          <button class="dash-btn danger" type="submit">Delete booking</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

  <div class="card" style="margin-top:14px;">
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">
        <h2 style="margin:0;"><?= h(ucfirst($tab)) ?></h2>
        <div class="muted" style="font-size:13px;">Showing <?= count($rows) ?> item(s)</div>
      </div>

      <?php if (!$rows): ?>
        <p class="muted" style="margin:12px 0 0;">No bookings yet for this view.</p>
      <?php else: ?>
        <div style="overflow:auto;margin-top:12px;">
          <table class="table" style="width:100%;min-width:860px;">
            <thead>
              <tr>
                <th style="text-align:left;">Status</th>
                <th style="text-align:left;">Event</th>
                <th style="text-align:left;">Date</th>
                <th style="text-align:left;">Venue</th>
                <th style="text-align:left;">Contact</th>
                <th style="text-align:right;">Fee</th>
                <th style="text-align:left;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= h(booking_status_label((string)$r['status'])) ?></td>
                <td>
                  <div style="font-weight:700;"><?= h($r['event_title']) ?></div>
                  <?php if (!empty($r['profile_name'])): ?>
                    <div class="muted" style="font-size:12px;">Profile: <?= h($r['profile_name']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?= h($r['event_date'] ?: '—') ?>
                  <?php if (!empty($r['start_time']) || !empty($r['end_time'])): ?>
                    <div class="muted" style="font-size:12px;"><?= h(($r['start_time'] ?: '') . ($r['end_time'] ? '–'.$r['end_time'] : '')) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?= h($r['venue_name'] ?: '—') ?>
                  <?php if (!empty($r['city']) || !empty($r['state'])): ?>
                    <div class="muted" style="font-size:12px;"><?= h(trim(($r['city'] ?? '').' '.($r['state'] ?? ''))) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?= h($r['contact_name'] ?: '—') ?>
                  <?php if (!empty($r['contact_email'])): ?>
                    <div class="muted" style="font-size:12px;"><?= h($r['contact_email']) ?></div>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;">
                  <?= ($r['fee'] !== null && $r['fee'] !== '') ? '$' . number_format((float)$r['fee'], 2) : '—' ?>
                </td>
                <td>
                  <a class="pill" href="<?= h(BASE_URL) ?>/bookings.php?tab=<?= h($tab) ?>&edit=<?= (int)$r['id'] ?>">Edit</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <div class="muted" style="margin-top:12px;font-size:12px;">
        <?php if ($tab !== 'requests'): ?>
        Setup: if you haven’t yet, run <code>scripts/create_bookings_and_profiles.sql</code> in your DB.
        <?php else: ?>
        These requests come from <code>booking_requests</code> and <code>booking_invites</code>.
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php page_footer(); ?>