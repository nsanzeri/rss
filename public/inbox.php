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
		default            => [ucfirst(str_replace('_', ' ', $key)), 'pill'],
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

function money_fmt($v): string {
	if ($v === null || $v === '') return '';
	if (!is_numeric($v)) return (string)$v;
	return '$' . number_format((float)$v, 2);
}

// Fetch items (same logic as original)
$items = [];
try {
	// Incoming invites
	$whereInvite = "p.user_id = :uid AND p.deleted_at IS NULL";
	if ($tab === 'open') $whereInvite .= " AND i.status='pending'";
	if ($tab === 'quoted') $whereInvite .= " AND i.status='accepted'";
	if ($tab === 'declined') $whereInvite .= " AND i.status='declined'";
	if ($tab === 'booked') $whereInvite .= " AND 1=0";
	if ($q !== '') {
		$whereInvite .= " AND (r.event_title LIKE :q OR r.contact_name LIKE :q OR r.city LIKE :q OR r.state LIKE :q OR r.venue_name LIKE :q)";
	}
	
	$sqlInvite = "
    SELECT
      'invite' AS item_type,
      i.id AS item_id,
      i.status AS raw_status,
      i.sent_at AS created_at,
      r.event_date, r.event_title, r.city, r.state,
      r.contact_name, r.contact_email, r.budget_min, r.budget_max, r.notes,
      r.venue_name, r.venue_address, r.start_time, r.end_time,
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
				'raw_status' => $r['raw_status'],
				'created_at' => $r['created_at'],
				'event_date' => $r['event_date'],
				'title' => $r['event_title'] ?: 'Booking request',
				'subtitle' => trim(($r['city'] ?: '') . ((string)($r['state'] ?? '') ? ', ' . $r['state'] : '')),
				'contact_name' => $r['contact_name'] ?: '—',
				'contact_email' => $r['contact_email'] ?: '',
				'role' => $r['profile_name'] ? ('Profile: ' . $r['profile_name']) : '',
				'budget_min' => $r['budget_min'],
				'budget_max' => $r['budget_max'],
				'notes' => $r['notes'] ?? '',
				'venue_name' => $r['venue_name'] ?? '',
				'venue_address' => $r['venue_address'] ?? '',
				'start_time' => $r['start_time'] ?? '',
				'end_time' => $r['end_time'] ?? '',
		];
	}
	
	// Manual bookings (unchanged)
	$whereBook = "b.user_id = :uid AND b.deleted_at IS NULL";
	if ($tab === 'open') $whereBook .= " AND b.status IN ('inquiry','pending')";
	if ($tab === 'quoted') $whereBook .= " AND 1=0";
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
	
	// Sort by event date, then newest first
	usort($items, function($a, $b) {
		$ad = $a['event_date'] ?? '2099-12-31';
		$bd = $b['event_date'] ?? '2099-12-31';
		if ($ad === $bd) {
			return strcmp((string)$b['created_at'], (string)$a['created_at']);
		}
		return strcmp($ad, $bd);
	});
		
} catch (Throwable $e) {
	$items = [];
	$error = $e->getMessage();
}

// Load saved message templates for the modal (same logic as invite.php)
$savedQuote = [];
$savedDecline = [];
try {
	$tpl = $pdo->prepare("
    SELECT id, title, body, template_type
    FROM message_templates
    WHERE is_active=1 AND user_id = :uid
    ORDER BY title ASC
  ");
	$tpl->execute([':uid'=>$u['id']]);
	$rows = $tpl->fetchAll() ?: [];
	foreach ($rows as $r) {
		if (($r['template_type'] ?? '') === 'quote') $savedQuote[] = $r;
		if (($r['template_type'] ?? '') === 'decline') $savedDecline[] = $r;
	}
} catch (Throwable $e) {
	// ignore
}


// Handle modal quote/decline postback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'respond_invite') {
	try {
		csrf_validate($_POST['csrf'] ?? '');
		
		$invite_id = (int)($_POST['invite_id'] ?? 0);
		if ($invite_id <= 0) throw new Exception('Missing invite id.');
		
		// Ensure invite belongs to one of my profiles
		$stmt = $pdo->prepare("
      SELECT i.id, i.request_id, i.target_profile_id, i.status
      FROM booking_invites i
      JOIN profiles p ON p.id = i.target_profile_id
      WHERE i.id = :id AND p.user_id = :uid AND p.deleted_at IS NULL
      LIMIT 1
    ");
		$stmt->execute([':id' => $invite_id, ':uid' => $user['id']]);
		$inv = $stmt->fetch();
		if (!$inv) throw new Exception("Invite not found or access denied.");
		if ($inv['status'] !== 'pending') throw new Exception("This invite is already {$inv['status']}.");
		
		$tab = $_POST['tab'] ?? 'quote';
		
		if ($tab === 'quote') {
			$amount = trim((string)($_POST['quote_amount'] ?? ''));
			$msg    = trim((string)($_POST['quote_message'] ?? ''));
			if ($amount === '' || !is_numeric($amount)) throw new Exception('Please enter a rate.');
			if ($msg === '') throw new Exception('Please add a message.');
			
			// If your booking_invites table DOESN'T have quote_amount/quote_message, store msg in `message` instead.
			$pdo->prepare("
        UPDATE booking_invites
        SET status='accepted', responded_at=NOW(),
            quote_amount=:amt, quote_message=:msg,
            decline_reason=NULL, decline_message=NULL
        WHERE id=:id
      ")->execute([':amt' => (float)$amount, ':msg' => $msg, ':id' => $invite_id]);
			
			$_SESSION['flash_success'] = "Quote sent.";
		} else {
			$reason = trim((string)($_POST['decline_reason'] ?? ''));
			$msg    = trim((string)($_POST['decline_message'] ?? ''));
			if ($reason === '') throw new Exception('Please select a decline reason.');
			
			$pdo->prepare("
        UPDATE booking_invites
        SET status='declined', responded_at=NOW(),
            decline_reason=:r, decline_message=:m,
            quote_amount=NULL, quote_message=NULL
        WHERE id=:id
      ")->execute([':r' => $reason, ':m' => $msg, ':id' => $invite_id]);
			
			$_SESSION['flash_success'] = "Decline sent.";
		}
		
	} catch (Throwable $e) {
		$_SESSION['flash_error'] = $e->getMessage();
	}
	
	header("Location: inbox.php");
	exit;
}

page_header('Inbox');
?>

<div class="container" style="max-width:1100px;margin:0 auto;padding:18px 14px;" x-data="inbox()">
<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert alert-success"><?= h($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert alert-error"><?= h($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>
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
          $amount = ($it['amount'] ?? null) !== null ? ('$' . number_format((float)$it['amount'], 2)) : '';
          $isPendingInvite = ($it['type'] ?? '') === 'invite' && ($it['raw_status'] ?? '') === 'pending';
        ?>
          <div style="display:flex;gap:14px;align-items:center;padding:14px 16px;border-top:1px solid rgba(255,255,255,.06);">
            <div style="width:42px;height:42px;border-radius:999px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;font-weight:800;">
              <?= h(initials((string)$it['contact_name'])) ?>
            </div>

            <div style="flex:1;min-width:0;">
              <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap;">
                <div style="font-weight:800;"><?= h($it['contact_name']) ?></div>
                <?php if (!empty($it['role'])): ?><div class="muted" style="font-size:12px;"><?= h($it['role']) ?></div><?php endif; ?>
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
              <?php if ($isPendingInvite): 
//               echo "<pre>params = " . print_r($it, true) . "</pre>";
//               echo "<pre>params = " . print_r(json_encode($it, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), true) . "</pre>";
              ?>
                <button class="<?= h($cls) ?>" @click.stop='openInvite(<?= json_encode($it, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><?= h($label) ?></button>
              <?php elseif (!empty($it['href'])): ?>
                <a class="<?= h($cls) ?>" href="<?= h($it['href']) ?>">
                  <?= $label === 'Booked' && $amount ? h($label) . ' • ' . h($amount) : h($label) ?>
                </a>
              <?php else: ?>
                <span class="<?= h($cls) ?>"><?= h($label) ?></span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Response Modal (mirrors invite.php) -->
  <template x-if="modalOpen">
    <div class="rs-modal-backdrop" x-cloak @click="modalOpen=false" @keydown.escape.window="modalOpen=false">
      <div class="rs-modal wide" role="dialog" @click.stop>
		<div class="rs-modal__head" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;">
		  <div>
		    <div class="muted" style="font-weight:700;">Respond to Request</div>
		    <div style="font-size:1.25rem;font-weight:900;line-height:1.2;" x-text="invite.title || 'Request'"></div>
		    <div class="muted" style="margin-top:.15rem;" x-text="invite.role || ''"></div>
		  </div>
		
		  <div style="display:flex;align-items:center;gap:.75rem;">
		    <div class="muted" style="white-space:nowrap;">
		      <strong x-text="invite.contacted ?? '—'"></strong> contacted
		      &nbsp;&nbsp;
		      <strong x-text="invite.quotes_sent ?? '—'"></strong> quotes sent
		    </div>
		    <button class="btn small" type="button" @click="modalOpen=false">✕</button>
		  </div>
		</div>
 


        <div class="rs-modal__body">
          <div class="grid" style="grid-template-columns:1fr 1fr;gap:1rem;">
            <!-- Request details -->
            <div class="card" style="margin:0;">
              <div class="card-body">
                <h4 style="margin-top:0;">Request details</h4>
				<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem 1rem;">
				  <div>
				    <div class="muted">Client</div>
				    <strong x-text="invite.contact_name || '—'"></strong>
				    <div class="muted" x-show="invite.contact_email" x-text="invite.contact_email"></div>
				    <div class="muted" x-show="invite.contact_phone" x-text="invite.contact_phone"></div>
				  </div>
				
				  <div>
				    <div class="muted">Location</div>
				    <strong x-text="invite.subtitle || '—'"></strong>
				    <div class="muted" x-show="invite.venue_name" x-text="invite.venue_name"></div>
				    <div class="muted" x-show="invite.venue_address" x-text="invite.venue_address"></div>
				  </div>
				
				  <div>
				    <div class="muted">Date</div>
				    <strong x-text="invite.event_date ? new Date(invite.event_date).toLocaleDateString() : 'TBD'"></strong>
				  </div>
				
				  <div>
				    <div class="muted">Time</div>
				    <strong x-text="invite.start_time ? invite.start_time + (invite.end_time ? ' – ' + invite.end_time : '') : 'TBD'"></strong>
				  </div>
				
				  <div>
				    <div class="muted">Budget</div>
				    <strong x-text="budgetText"></strong>
				  </div>
				
				  <div>
				    <div class="muted">Status</div>
				    <strong x-text="invite.raw_status ? invite.raw_status : '—'"></strong>
				  </div>
				
				  <div style="grid-column:1/-1;" x-show="invite.notes">
				    <hr style="margin:.75rem 0;"/>
				    <div class="muted">Event details</div>
				    <div x-text="invite.notes"></div>
				  </div>
				</div>
              </div>
            </div>

            <!-- Response form -->
            <div class="card" style="margin:0;">
              <div class="card-body">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;">
                  <h4 style="margin:0;">Your response</h4>
                  <div class="seg">
                    <button type="button" @click="tab='quote'" :aria-pressed="tab==='quote'">Quote</button>
                    <button type="button" @click="tab='decline'" :aria-pressed="tab==='decline'">Decline</button>
                  </div>
                </div>

                <div x-show="message" class="alert" :class="{'alert-success':messageSuccess,'alert-error':!messageSuccess}" style="margin-top:.75rem;" x-text="message"></div>

                <form method="post" action="inbox.php" style="margin-top:.75rem;">
                  <input type="hidden" name="csrf" :value="csrf">
                  <input type="hidden" name="action" value="respond_invite">
				  <input type="hidden" name="invite_id" :value="invite.id">
				  <input type="hidden" name="tab" :value="tab">
                  

                  <!-- Quote tab -->
                  <div x-show="tab==='quote'">
                    <?php if (!empty($savedQuote)): ?>
                      <label>Saved responses</label>
                      <select @change="quoteMessage = $event.target.selectedOptions[0].dataset.body || ''">
                        <option value="">— Insert a saved response —</option>
                        <?php foreach ($savedQuote as $t): ?>
                          <option data-body="<?= h($t['body']) ?>"><?= h($t['title']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <div style="height:.5rem;"></div>
                    <?php endif; ?>

                    <label>Your rate</label>
                 	<input
					  type="number"
					  name="quote_amount"
					  x-model="quoteAmount"
					  step="0.01" min="0" placeholder="e.g., 500"
					  :required="tab==='quote'"
					  :disabled="tab!=='quote'"
					/>

                    <label style="margin-top:.5rem;">Message to client</label>
                    <textarea
					  name="quote_message"
					  x-model="quoteMessage"
					  rows="6"
					  :required="tab==='quote'"
					  :disabled="tab!=='quote'"
					></textarea>

                    <div style="margin-top:.75rem;text-align:right;">
                      <button class="btn btn-primary" type="submit" :disabled="loading">Send quote</button>
                    </div>
                  </div>

                  <!-- Decline tab -->
                  <div x-show="tab==='decline'">
                    <label>Decline reason</label>
                   <select name="decline_reason" x-model="declineReason" :required="tab==='decline'" :disabled="tab!=='decline'">
                      <option value="">— Select a reason —</option>
                      <option>Unavailable on this date</option>
                      <option>Outside my typical travel radius</option>
                      <option>Budget is too low</option>
                      <option>Not the right fit</option>
                      <option>Other</option>
                    </select>

                    <?php if (!empty($savedDecline)): ?>
                      <div style="height:.5rem;"></div>
                      <label>Saved responses</label>
                      <select @change="declineMessage = $event.target.selectedOptions[0].dataset.body || ''">
                        <option value="">— Insert a saved response —</option>
                        <?php foreach ($savedDecline as $t): ?>
                          <option data-body="<?= h($t['body']) ?>"><?= h($t['title']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php endif; ?>

                    <label style="margin-top:.5rem;">Optional message</label>
                    <textarea name="decline_message" x-model="declineMessage" rows="5" :disabled="tab!=='decline'"></textarea>

                    <div style="margin-top:.75rem;text-align:right;">
                      <button class="btn btn-ghost" type="submit" :disabled="loading">Send decline</button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </template>
</div>

<script>
function inbox() {
  return {
    modalOpen: false,
    tab: 'quote',
    loading: false,
    message: '',
    messageSuccess: false,
    invite: {},
    quoteAmount: '',
    quoteMessage: '',
    declineReason: '',
    declineMessage: '',

    get csrf() {
      return document.querySelector('meta[name="csrf"]')?.getAttribute('content') || '';
    },

    get budgetText() {
      const i = this.invite;
      let txt = 'TBD';
      if (i.budget_min || i.budget_max) {
        const min = i.budget_min ? '$' + Number(i.budget_min).toFixed(2) : '';
        const max = i.budget_max ? '$' + Number(i.budget_max).toFixed(2) : '';
        if (min && max) txt = min + ' – ' + max;
        else if (max) txt = 'Up to ' + max;
        else if (min) txt = min;
      }
      return txt;
    },

    openInvite(data) {
      this.invite = data;
      this.modalOpen = true;
      this.tab = 'quote';
      this.quoteAmount = '';
      this.quoteMessage = '';
      this.declineReason = '';
      this.declineMessage = '';
      this.message = '';
    },

    async sendResponse() {
      this.loading = true;
      this.message = '';

      const formData = new FormData();
      formData.append('csrf', this.csrf);
      formData.append('invite_id', this.invite.id);

      if (this.tab === 'quote') {
        formData.append('action', 'send_quote');
        formData.append('quote_amount', this.quoteAmount);
        formData.append('quote_message', this.quoteMessage);
      } else {
        formData.append('action', 'send_decline');
        formData.append('decline_reason', this.declineReason);
        formData.append('decline_message', this.declineMessage);
      }

      try {
        const res = await fetch('<?= h(BASE_URL) ?>/api/respond_invite.php', { // you may need to create this endpoint or reuse logic
          method: 'POST',
          body: formData
        });
        const json = await res.json();
        if (json.success) {
          this.message = this.tab === 'quote' ? 'Quote sent.' : 'Decline sent.';
          this.messageSuccess = true;
          // Update the row in the list
          const rowButton = document.querySelector(`button[@click*="${this.invite.id}"]`);
          if (rowButton) {
            rowButton.textContent = this.tab === 'quote' ? 'Quote sent' : 'Declined lead';
            rowButton.className = this.tab === 'quote' ? 'pill success' : 'pill';
          }
          setTimeout(() => { this.modalOpen = false; }, 1500);
        } else {
          this.message = json.error || 'Something went wrong.';
          this.messageSuccess = false;
        }
      } catch (e) {
        this.message = 'Network error.';
        this.messageSuccess = false;
      } finally {
        this.loading = false;
      }
    }
  }
}
</script>

<style>
.rs-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.6); display:flex; align-items:center; justify-content:center; z-index:1000; }
.rs-modal { max-width:960px; width:95%; max-height:90vh; overflow:auto; background:var(--bg); border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.4); }
.rs-modal.wide { max-width:1100px; }
.rs-modal__head { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid rgba(255,255,255,.08); }
.rs-modal__body { padding:20px; }
.seg { display:flex; gap:.5rem; padding:.35rem; border-radius:999px; border:1px solid rgba(255,255,255,.12); background:rgba(0,0,0,.18); }
.seg button { border:0; background:transparent; color:inherit; padding:.45rem .75rem; border-radius:999px; cursor:pointer; font-weight:700; }
.seg button[aria-pressed="true"] { background:rgba(255,255,255,.12); }
</style>

<?php page_footer(); ?>