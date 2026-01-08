<?php
require_once __DIR__ . "/_layout.php";

$pdo  = db();
$user = auth_user();

function money_fmt($v): string {
	if ($v === null || $v === '') return '';
	if (!is_numeric($v)) return (string)$v;
	return '$' . number_format((float)$v, 2);
}

if (!$user) {
	header('Location: ' . BASE_URL . '/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
	exit;
}

$invite_id = (int)($_GET['invite_id'] ?? 0);
if ($invite_id <= 0) { http_response_code(400); exit("Missing invite_id."); }

// Invite + Request + Target Profile
$stmt = $pdo->prepare("
  SELECT
    i.*,
    r.event_title, r.event_date, r.start_time, r.end_time,
    r.venue_name, r.venue_address, r.city, r.state, r.zip,
    r.budget_min, r.budget_max, r.notes,
    r.contact_name, r.contact_email, r.contact_phone,
    p.name AS target_name
  FROM booking_invites i
  JOIN booking_requests r ON r.id = i.request_id
  LEFT JOIN profiles p ON p.id = i.target_profile_id
  WHERE i.id = :iid
  LIMIT 1
");
$stmt->execute([':iid' => $invite_id]);
$inv = $stmt->fetch();
if (!$inv) { http_response_code(404); exit("Invite not found."); }

// Authz: invite must belong to one of my profiles
$mine = $pdo->prepare("SELECT id FROM profiles WHERE user_id=? AND deleted_at IS NULL");
$mine->execute([$user['id']]);
$myProfileIds = array_map('intval', array_column($mine->fetchAll() ?: [], 'id'));
if (!in_array((int)$inv['target_profile_id'], $myProfileIds, true)) {
	http_response_code(403);
	exit("You don't have access to this invite.");
}

// Competition stats
$statsStmt = $pdo->prepare("
  SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status='accepted' THEN 1 ELSE 0 END) AS quoted,
    SUM(CASE WHEN status='declined' THEN 1 ELSE 0 END) AS declined,
    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending
  FROM booking_invites
  WHERE request_id = ?
");
$statsStmt->execute([(int)$inv['request_id']]);
$stats = $statsStmt->fetch() ?: ['total'=>0,'quoted'=>0,'declined'=>0,'pending'=>0];

// Saved responses
$savedQuote = [];
$savedDecline = [];
try {
	$tpl = $pdo->prepare("
    SELECT id, title, body, template_type
    FROM message_templates
    WHERE is_active=1 AND user_id = :uid AND (profile_id IS NULL OR profile_id = :pid)
    ORDER BY profile_id DESC, title ASC
  ");
	$tpl->execute([':uid'=>$user['id'], ':pid'=>(int)$inv['target_profile_id']]);
	$rows = $tpl->fetchAll() ?: [];
	foreach ($rows as $r) {
		if (($r['template_type'] ?? '') === 'quote') $savedQuote[] = $r;
		if (($r['template_type'] ?? '') === 'decline') $savedDecline[] = $r;
	}
} catch (Throwable $e) {
	// ok if table doesn't exist yet
}

$error = '';
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	try {
		csrf_validate($_POST['csrf'] ?? '');
		
		if ((string)$inv['status'] !== 'pending') {
			throw new Exception("This invite is already {$inv['status']}.");
		}
		
		$action = (string)($_POST['action'] ?? '');
		
		if ($action === 'send_quote') {
			$amount = trim((string)($_POST['quote_amount'] ?? ''));
			$msg    = trim((string)($_POST['quote_message'] ?? ''));
			
			if ($amount === '' || !is_numeric($amount)) throw new Exception('Please enter a rate.');
			if ($msg === '') throw new Exception('Please add a message.');
			
			$up = $pdo->prepare("
        UPDATE booking_invites
        SET status='accepted', responded_at=NOW(),
            quote_amount=:amt, quote_message=:msg,
            decline_reason=NULL, decline_message=NULL
        WHERE id=:id
      ");
			$up->execute([':amt'=>(float)$amount, ':msg'=>$msg, ':id'=>$invite_id]);
			
			header('Location: ' . BASE_URL . '/invite.php?invite_id=' . $invite_id . '&sent=1');
			exit;
		}
		
		if ($action === 'send_decline') {
			$reason = trim((string)($_POST['decline_reason'] ?? ''));
			$msg    = trim((string)($_POST['decline_message'] ?? ''));
			
			if ($reason === '') throw new Exception('Please select a decline reason.');
			
			$up = $pdo->prepare("
        UPDATE booking_invites
        SET status='declined', responded_at=NOW(),
            decline_reason=:r, decline_message=:m,
            quote_amount=NULL, quote_message=NULL
        WHERE id=:id
      ");
			$up->execute([':r'=>$reason, ':m'=>$msg, ':id'=>$invite_id]);
			
			header('Location: ' . BASE_URL . '/invite.php?invite_id=' . $invite_id . '&declined=1');
			exit;
		}
		
		throw new Exception('Invalid action.');
		
	} catch (Throwable $e) {
		$error = $e->getMessage();
	}
}

if (isset($_GET['sent'])) $flash = 'Quote sent.';
if (isset($_GET['declined'])) $flash = 'Decline sent.';

$budgetTxt = 'TBD';
if ($inv['budget_min'] !== null || $inv['budget_max'] !== null) {
	$min = $inv['budget_min'] !== null ? money_fmt($inv['budget_min']) : '';
	$max = $inv['budget_max'] !== null ? money_fmt($inv['budget_max']) : '';
	if ($min && $max) $budgetTxt = $min . ' – ' . $max;
	elseif ($max) $budgetTxt = 'Up to ' . $max;
	elseif ($min) $budgetTxt = $min;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Respond • <?= h(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= h(BASE_URL) ?>/assets/css/app.css"/>
  <style>
    .seg{display:flex;gap:.5rem;padding:.35rem;border-radius:999px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.18);}
    .seg button{border:0;background:transparent;color:inherit;padding:.45rem .75rem;border-radius:999px;cursor:pointer;font-weight:700}
    .seg button[aria-pressed="true"]{background:rgba(255,255,255,.12)}
    .hidden{display:none!important}
    .badge{display:inline-flex;gap:.35rem;align-items:center;padding:.25rem .5rem;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);font-size:.85rem}
  </style>
</head>
<body>
<div class="public-shell">
  <div class="public-top">
    <a class="public-brand" href="<?= h(BASE_URL) ?>/dashboard.php">Ready Set Shows</a>
    <div class="public-actions"><a class="btn btn-ghost" href="<?= h(BASE_URL) ?>/bookings.php">Bookings</a></div>
  </div>

  <main class="public-content" style="max-width:980px;">
    <div class="card">
      <div class="card-header">
        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
          <div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
              <span class="badge"><strong><?= h(ucfirst((string)$inv['status'])) ?></strong></span>
              <span class="badge">Request #<?= (int)$inv['request_id'] ?></span>
              <span class="badge">Invite #<?= (int)$inv['id'] ?></span>
            </div>
            <h1 style="margin:.5rem 0 0;"><?= h((string)($inv['event_title'] ?: 'Booking request')) ?></h1>
            <div class="muted">Responding as <strong><?= h((string)($inv['target_name'] ?? 'Your profile')) ?></strong></div>
          </div>

          <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-start;">
            <span class="badge"><strong><?= (int)$stats['total'] ?></strong> contacted</span>
            <span class="badge"><strong><?= (int)$stats['quoted'] ?></strong> quotes sent</span>
          </div>
        </div>
      </div>

      <div class="card-body">
        <?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

        <div class="grid" style="grid-template-columns:1.1fr .9fr;gap:1rem;">
          <!-- Read-only -->
          <div class="card" style="margin:0;">
            <div class="card-body">
              <h3 style="margin-top:0;">Request details</h3>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem 1rem;">
                <div>
                  <div class="muted">Client</div>
                  <div><strong><?= h((string)($inv['contact_name'] ?: '—')) ?></strong></div>
                  <div class="muted"><?= h((string)($inv['contact_email'] ?: '')) ?></div>
                  <div class="muted"><?= h((string)($inv['contact_phone'] ?: '')) ?></div>
                </div>
                <div>
                  <div class="muted">Location</div>
                  <div><strong><?= h(trim((string)($inv['city'] ?? '') . ((string)($inv['state'] ?? '') ? ', ' . (string)$inv['state'] : ''))) ?></strong></div>
                  <div class="muted"><?= h((string)($inv['venue_name'] ?: '')) ?></div>
                  <div class="muted"><?= h((string)($inv['venue_address'] ?: '')) ?></div>
                </div>

                <div>
                  <div class="muted">Date</div>
                  <div><strong><?= h((string)($inv['event_date'] ?: 'TBD')) ?></strong></div>
                </div>
                <div>
                  <div class="muted">Time</div>
                  <div><strong><?= h(trim((string)($inv['start_time'] ?: 'TBD') . ((string)($inv['end_time'] ?? '') ? ' – ' . (string)$inv['end_time'] : ''))) ?></strong></div>
                </div>

                <div>
                  <div class="muted">Budget</div>
                  <div><strong><?= h($budgetTxt) ?></strong></div>
                </div>
                <div>
                  <div class="muted">Competition</div>
                  <div><strong><?= (int)$stats['pending'] ?></strong> pending, <strong><?= (int)$stats['declined'] ?></strong> declined</div>
                </div>

                <?php if (!empty($inv['notes'])): ?>
                  <div style="grid-column:1/-1;">
                    <hr style="margin:.75rem 0;"/>
                    <div class="muted">Event details</div>
                    <div><?= nl2br(h((string)$inv['notes'])) ?></div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Response -->
          <div class="card" style="margin:0;">
            <div class="card-body">
              <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
                <h3 style="margin:0;">Your response</h3>
                <div class="seg">
                  <button type="button" id="tabQuote" aria-pressed="true">Quote</button>
                  <button type="button" id="tabDecline" aria-pressed="false">Decline</button>
                </div>
              </div>

              <?php if ((string)$inv['status'] !== 'pending'): ?>
                <p class="muted" style="margin:.75rem 0 0;">Replies are locked.</p>
              <?php else: ?>

              <form method="post" id="quoteForm" style="margin-top:.75rem;">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
                <input type="hidden" name="action" value="send_quote"/>

                <?php if (!empty($savedQuote)): ?>
                  <label>Saved responses</label>
                  <select id="quoteTpl">
                    <option value="">— Insert a saved response —</option>
                    <?php foreach ($savedQuote as $t): ?>
                      <option data-body="<?= h((string)$t['body']) ?>"><?= h((string)$t['title']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div style="height:.5rem;"></div>
                <?php endif; ?>

                <label>Your rate</label>
                <input type="number" name="quote_amount" step="0.01" min="0" placeholder="e.g., 500" required/>

                <label style="margin-top:.5rem;">Message to client</label>
                <textarea name="quote_message" id="quoteMsg" rows="6" required></textarea>

                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem;">
                  <button class="btn" type="submit">Send quote</button>
                </div>
              </form>

              <form method="post" id="declineForm" class="hidden" style="margin-top:.75rem;">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
                <input type="hidden" name="action" value="send_decline"/>

                <label>Decline reason</label>
                <select name="decline_reason" required>
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
                  <select id="declineTpl">
                    <option value="">— Insert a saved response —</option>
                    <?php foreach ($savedDecline as $t): ?>
                      <option data-body="<?= h((string)$t['body']) ?>"><?= h((string)$t['title']) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>

                <label style="margin-top:.5rem;">Optional message</label>
                <textarea name="decline_message" id="declineMsg" rows="5"></textarea>

                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem;">
                  <button class="btn btn-ghost" type="submit">Send decline</button>
                </div>
              </form>

              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div>
  </main>
</div>

<script>
(function(){
  const tabQ = document.getElementById('tabQuote');
  const tabD = document.getElementById('tabDecline');
  const qForm = document.getElementById('quoteForm');
  const dForm = document.getElementById('declineForm');

  function showQuote(){
    tabQ.setAttribute('aria-pressed','true');
    tabD.setAttribute('aria-pressed','false');
    qForm.classList.remove('hidden');
    dForm.classList.add('hidden');
  }
  function showDecline(){
    tabQ.setAttribute('aria-pressed','false');
    tabD.setAttribute('aria-pressed','true');
    qForm.classList.add('hidden');
    dForm.classList.remove('hidden');
  }

  tabQ.addEventListener('click', showQuote);
  tabD.addEventListener('click', showDecline);

  const quoteTpl = document.getElementById('quoteTpl');
  const quoteMsg = document.getElementById('quoteMsg');
  if (quoteTpl && quoteMsg) quoteTpl.addEventListener('change', () => {
    const body = quoteTpl.options[quoteTpl.selectedIndex].dataset.body || '';
    if (body) quoteMsg.value = body;
  });

  const declineTpl = document.getElementById('declineTpl');
  const declineMsg = document.getElementById('declineMsg');
  if (declineTpl && declineMsg) declineTpl.addEventListener('change', () => {
    const body = declineTpl.options[declineTpl.selectedIndex].dataset.body || '';
    if (body) declineMsg.value = body;
  });
})();
</script>
</body>
</html>
