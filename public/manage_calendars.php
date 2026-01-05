<?php
require_once __DIR__ . "/_layout.php";
require_once __DIR__ . "/../core/ics.php";

$u = require_login();
$pdo = db();

$flash = null;
$err   = null;

// allow API redirect to show a quick message
if (!empty($_GET['flash'])) {
  $flash = (string)$_GET['flash'];
}

function set_default_calendar(PDO $pdo, int $userId, int $calId): void {
  $pdo->beginTransaction();
  $stmt = $pdo->prepare("UPDATE user_calendars SET is_default=0 WHERE user_id=?");
  $stmt->execute([$userId]);
  $stmt = $pdo->prepare("UPDATE user_calendars SET is_default=1 WHERE user_id=? AND id=?");
  $stmt->execute([$userId, $calId]);
  $pdo->commit();
}

$action = $_POST['action'] ?? ($_GET['action'] ?? null);

// ------------------------------------------------------------------
// POST actions
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate($_POST['csrf'] ?? null)) {
    $err = "Session expired. Please refresh and try again.";
  } else if ($action === 'add_calendar') {
    $name = trim($_POST['calendar_name'] ?? '');
    $color = trim($_POST['calendar_color'] ?? '#3b82f6');
    $desc = trim($_POST['description'] ?? '');
    $linkExternal = !empty($_POST['link_external']);
    $sourceType = $linkExternal ? 'ics' : 'manual';
    $sourceUrl = trim($_POST['source_url'] ?? '');
    if (!$linkExternal) { $sourceUrl = ''; }
    $isDefault = !empty($_POST['is_default']) ? 1 : 0;

    if ($name === '') {
      $err = "Calendar name is required.";
    } else {
      if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#3b82f6';
      // Source is now implicit:
      // - Manual calendars have no URL
      // - Linked calendars have a URL (Google/iCal/Outlook/etc.)
      if ($sourceType === 'ics' && $sourceUrl === '') {
        $err = "Calendar URL is required when linking an external calendar.";
      }
    }

    if (!$err) {
      $stmt = $pdo->prepare("INSERT INTO user_calendars (user_id, calendar_name, calendar_color, description, source_type, source_url, is_default, created_at)
                             VALUES (?,?,?,?,?,?,?,NOW())");
      $stmt->execute([$u['id'], $name, $color, ($desc ?: null), $sourceType, ($sourceUrl ?: null), $isDefault]);

      $calId = (int)$pdo->lastInsertId();
      if ($sourceType === 'ics' && $sourceUrl) {
        $stmt = $pdo->prepare("INSERT INTO calendar_imports (calendar_id, source_url)
                               VALUES (?,?)
                               ON DUPLICATE KEY UPDATE source_url=VALUES(source_url)");
        $stmt->execute([$calId, $sourceUrl]);
      }
      if ($isDefault) {
        set_default_calendar($pdo, (int)$u['id'], $calId);
      }
      $flash = "Calendar added.";
    }

  } else if ($action === 'update_calendar') {
    $calId = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['calendar_name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $color = trim((string)($_POST['calendar_color'] ?? '#3b82f6'));
    $sourceUrl = trim((string)($_POST['source_url'] ?? ''));

    // Prevent changing a calendar from manual ↔ linked via this form.
    // Source type is determined by the existing record.
    $sourceType = 'manual'; // will be overwritten after we load existing calendar

    if ($calId <= 0) {
      $err = "Missing calendar.";
    } else if ($name === '') {
      $err = "Calendar name is required.";
    } else {
      if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#3b82f6';
      // sourceType/sourceUrl validation happens after loading existing record
    }

    if (!$err) {
      $stmt = $pdo->prepare("SELECT id, source_type FROM user_calendars WHERE id=? AND user_id=? LIMIT 1");
      $stmt->execute([$calId, $u['id']]);
      $existing = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$existing) {
        $err = "Calendar not found.";
      } else {
        $sourceType = ($existing['source_type'] === 'ics') ? 'ics' : 'manual';
        if ($sourceType === 'manual') {
          // Manual calendars do not accept URLs
          $sourceUrl = '';
        } else {
          // Linked calendars require a URL
          if ($sourceUrl === '') $err = "Calendar URL is required for linked calendars.";
        }
      }
    }

    if (!$err) {
      $stmt = $pdo->prepare("UPDATE user_calendars
                             SET calendar_name=?, description=?, calendar_color=?, source_type=?, source_url=?, updated_at=NOW()
                             WHERE id=? AND user_id=?");
      $stmt->execute([$name, ($desc ?: null), $color, $sourceType, ($sourceUrl ?: null), $calId, $u['id']]);

      // Keep calendar_imports in sync for linked calendars
      if ($sourceType === 'ics') {
        $stmt = $pdo->prepare("INSERT INTO calendar_imports (calendar_id, source_url)
                               VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE source_url=VALUES(source_url)");
        $stmt->execute([$calId, ($sourceUrl ?: null)]);
      }


      $flash = "Calendar updated.";
    }

  } else if ($action === 'add_block') {
    $calendarId = (int)($_POST['calendar_id'] ?? 0);
    $date = $_POST['date'] ?? '';
    $type = $_POST['type'] ?? 'busy';
    $allDay = !empty($_POST['all_day']) ? 1 : 0;
    $startTime = $_POST['start_time'] ?? '00:00';
    $endTime = $_POST['end_time'] ?? '23:59';
    $title = trim($_POST['title'] ?? '');

    $stmt = $pdo->prepare("SELECT id FROM user_calendars WHERE id=? AND user_id=? LIMIT 1");
    $stmt->execute([$calendarId, $u['id']]);
    if (!$stmt->fetch()) {
      $err = "Invalid calendar.";
    } else {
      $tz = new DateTimeZone($u['timezone'] ?? 'UTC');
      try {
        if ($allDay) {
          $startLocal = new DateTimeImmutable($date . " 00:00:00", $tz);
          $endLocal   = $startLocal->modify("+1 day");
        } else {
          $startLocal = new DateTimeImmutable($date . " " . $startTime . ":00", $tz);
          $endLocal   = new DateTimeImmutable($date . " " . $endTime . ":00", $tz);
          if ($endLocal <= $startLocal) $endLocal = $startLocal->modify("+1 hour");
        }

        $startUtc = $startLocal->setTimezone(new DateTimeZone("UTC"));
        $endUtc   = $endLocal->setTimezone(new DateTimeZone("UTC"));

        $stmt = $pdo->prepare("INSERT INTO calendar_events (calendar_id, title, status, is_all_day, start_utc, end_utc, source, created_at)
                               VALUES (?,?,?,?,?,?, 'manual', NOW())");
        $stmt->execute([$calendarId, ($title ?: 'Manual block'), $type, $allDay, $startUtc->format('Y-m-d H:i:s'), $endUtc->format('Y-m-d H:i:s')]);
        $flash = "Saved. Tip: you can edit or delete blocks from the Dashboard calendar widget.";
      } catch (Throwable $e) {
        $err = "Could not save block: " . $e->getMessage();
      }
    }
  }
}

// ------------------------------------------------------------------
// GET actions
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action) {
  $token = $_GET['csrf'] ?? null;
  if (!csrf_validate($token)) {
    $err = "Session expired. Please refresh and try again.";
  } else if ($action === 'set_default') {
    $calId = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id FROM user_calendars WHERE id=? AND user_id=? LIMIT 1");
    $stmt->execute([$calId, $u['id']]);
    if ($stmt->fetch()) {
      set_default_calendar($pdo, (int)$u['id'], $calId);
      $flash = "Default calendar updated.";
    }
  } else if ($action === 'delete') {
    $calId = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("DELETE FROM user_calendars WHERE id=? AND user_id=?");
    $stmt->execute([$calId, $u['id']]);
    $flash = "Calendar deleted.";
  }
}

// Load calendars for UI
$stmt = $pdo->prepare("SELECT c.*, COALESCE(NULLIF(ci.source_url,''), NULLIF(c.source_url,'')) AS source_url,
         ci.last_synced_at, ci.last_error
  FROM user_calendars c
  LEFT JOIN calendar_imports ci ON ci.calendar_id=c.id
  WHERE c.user_id=?
  ORDER BY c.is_default DESC, c.calendar_name ASC");
$stmt->execute([$u['id']]);
$cals = $stmt->fetchAll(PDO::FETCH_ASSOC);

page_header("Manage Calendars");
?>

<!-- Alpine is loaded globally in _layout.php -->

<style>
/* ------------------------------------------------------------------
   Manage Calendars page-specific layout fixes:
   - Stop full-width stretching
   - Mixed-case labels (override global uppercase)
   - Reasonable input widths
   - Inline action buttons (no stacking)
   - Cleaner Manual Block grid
------------------------------------------------------------------ */
.manage-wrap{
  max-width: 1100px;
  margin: 0 auto;
  padding: 0 14px;
}

.manage-grid{
  /* Stack the 3 cards vertically, like the reference screenshot */
  display: flex;
  flex-direction: column;
  gap: 18px;
}


.manage-card h2{ margin: 0 0 6px; }
.manage-help{ margin: 0 0 14px; }

.manage-form{
  display: grid;
  gap: 12px;
}

/* Add Calendar grid (match screenshot positioning) */
.addcal-grid{
  display:grid;
  grid-template-columns: 92px 1.1fr 1.3fr 220px;
  gap: 12px;
  align-items:end;
}
.addcal-grid .span-2{ grid-column: span 2; }
.addcal-grid .span-3{ grid-column: span 3; }
@media (max-width: 980px){
  .addcal-grid{ grid-template-columns: 92px 1fr; }
  .addcal-grid .span-2,
  .addcal-grid .span-3{ grid-column: 1 / -1; }
}

.addcal-actions{
  display:flex;
  gap: 10px;
  align-items:center;
  justify-content:flex-end;
  flex-wrap:wrap;
}

.btn-icon{
  width: 40px;
  height: 36px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding: 0;
}

.sr-only{
  position:absolute;
  width:1px;
  height:1px;
  padding:0;
  margin:-1px;
  overflow:hidden;
  clip:rect(0,0,0,0);
  white-space:nowrap;
  border:0;
}

.inline-check{
  display:flex;
  gap: 10px;
  align-items:center;
  justify-content:flex-start;
  padding: 10px 12px;
  border: 1px solid rgba(255,255,255,.10);
  border-radius: 10px;
  background: rgba(8,10,18,.35);
}

.manage-form .row{
  display: grid;
  grid-template-columns: 120px 1fr;
  gap: 12px;
  align-items: center;
}

.manage-form .row.row-2{
  grid-template-columns: 120px 1fr 120px 1fr;
}

@media (max-width: 720px){
  .manage-form .row.row-2{ grid-template-columns: 120px 1fr; }
}

.manage-form label{
  display: block;
  font-size: 0.85rem;
  color: var(--color-text-muted);
  text-transform: none !important;
  letter-spacing: 0.02em !important;
  margin-bottom: 6px;
}

/* Checkboxes: keep label text immediately to the right */
.manage-form label.checkbox,
.manage-form .checkbox{
  display: inline-flex;
  align-items: center;
  gap: 10px;
  margin: 0;
  text-transform: none !important;
  letter-spacing: 0.02em !important;
}
.manage-form label.checkbox input[type="checkbox"],
.manage-form .checkbox input[type="checkbox"]{
  margin: 0;
}

.manage-form .field > label{
  margin-bottom: 6px;
}

.manage-form input,
.manage-form select{
  width: 100%;
  border-radius: 8px !important; /* match check availability */
}

.manage-form input[type="color"]{
  height: 35px;
  padding: 0;
}

.manage-actions{
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  align-items: center;
  flex-wrap: wrap;
  margin-top: 4px;
}

.manage-actions .checkbox{
  margin-right: auto;
}

/* Calendar list */
.manage-table-head{
  text-transform: none !important;
  letter-spacing: 0.02em !important;
  font-size: 0.82rem !important;
}

.manage-table{
  margin-top: 14px;
}

.manage-row{
  grid-template-columns: 1.45fr 0.75fr 0.65fr 0.7fr 1.15fr !important;
}

@media (max-width: 980px){
  .manage-row{ grid-template-columns: 1fr !important; }
  .manage-table-head{ display:none !important; }
}

/* Calendar cell */
.mc-cell{
  display:flex;
  gap: 10px;
  align-items:flex-start;
}

.mc-dot{
  width: 10px; height: 10px;
  border-radius: 999px;
  margin-top: 12px;
  box-shadow: 0 0 0 2px rgba(255,255,255,0.08);
}

.mc-stack{
  display:grid;
  gap: 8px;
  min-width: 0;
}

.mc-top{
  display:grid;
  grid-template-columns: 1fr 44px;
  gap: 10px;
  align-items:center;
}

.mc-top input[type="color"]{
  width: 44px;
  height: 44px;
  border-radius: 12px !important;
  padding: 0;
}

.mc-meta{
  font-size: 0.82rem;
}

/* Actions: inline, never stacked */
.mc-actions{
  display:flex;
  gap: 10px;
  justify-content:flex-end;
  align-items:center;
  flex-wrap: nowrap;
}

@media (max-width: 980px){
  .mc-actions{ justify-content:flex-start; }
}

.mc-actions .btn.small{
  white-space: nowrap;
}

/* Manual block grid */
.manual-grid{
  display:grid;
  grid-template-columns: 1.1fr 0.8fr 0.9fr 0.8fr;
  gap: 12px;
  align-items:end;
}

.manual-grid .span-2{ grid-column: span 2; }
.manual-grid .span-4{ grid-column: 1 / -1; }

@media (max-width: 980px){
  .manual-grid{ grid-template-columns: 1fr 1fr; }
  .manual-grid .span-2{ grid-column: 1 / -1; }
}

@media (max-width: 560px){
  .manual-grid{ grid-template-columns: 1fr; }
}

/* Badges */
.rs-badge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: .02em;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(8,10,18,.55);
  color: rgba(255,255,255,.85);
}
.rs-badge--muted{
  border-color: rgba(255,255,255,.12);
  background: rgba(8,10,18,.45);
  color: rgba(255,255,255,.70);
}
.rs-badge--info{
  border-color: rgba(120,190,255,.35);
  background: rgba(40,120,255,.14);
}
.rs-badge--gold{
  border-color: rgba(212,175,55,.55);
  box-shadow: 0 0 0 3px rgba(212,175,55,.10);
}

/* x-cloak helper (prevents flash of hidden content) */
[x-cloak]{ display:none !important; }

</style>

<?php if ($flash): ?><div class="alert success"><?= h($flash) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert"><?= h($err) ?></div><?php endif; ?>

<div class="manage-wrap" x-data="icsImport()">

  <div class="manage-grid">
    <!-- Add Calendar -->
    <div class="card manage-card">
      <div class="card-body">
        <div class="card-title">Add New Calendar</div>
        <p class="muted manage-help">Connect personal, band, and venue calendars. Choose a source type and optionally mark a default.</p>

        <form method="post" class="manage-form" x-data="{ linked:false }">
          <input type="hidden" name="action" value="add_calendar"/>
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>

          <div class="addcal-grid">
            <div class="field">
              <label for="new_color">Color</label>
              <input id="new_color" type="color" name="calendar_color" value="#3b82f6"/>
            </div>

            <div class="field">
              <label for="new_name">Name</label>
              <input id="new_name" class="in" type="text" name="calendar_name" placeholder="Calendar name" required/>
            </div>

            <div class="field">
              <label for="new_desc">Description</label>
              <input id="new_desc" class="in" type="text" name="description" placeholder="e.g. Personal gigs, Duo calendar, Bookings only…"/>
            </div>

            <div class="field">
              <label>&nbsp;</label>
              <label class="inline-check" style="margin:0;">
                <input type="checkbox" name="is_default" value="1"/>
                <span style="font-weight:700;">Default calendar</span>
              </label>
            </div>

            <div class="field span-3" style="margin-top:2px;">
              <label class="checkbox" style="margin:0;">
                <input type="checkbox" name="link_external" value="1" x-model="linked"/>
                Link external calendar (optional)
              </label>

              <div x-show="linked" x-cloak style="margin-top:10px;">
                <label for="new_url">Calendar URL</label>
                <input id="new_url" class="in" type="url" name="source_url" placeholder="Paste an iCal/ICS URL from Google, Apple, Outlook, etc."/>
              </div>
            </div>

            <div class="field" style="margin-top:2px;">
              <label>&nbsp;</label>
              <div class="addcal-actions">
                <button class="btn btn-primary" type="submit">Add Calendar</button>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Manual Block -->
    <div class="card manage-card">
      <div class="card-body">
        <div class="card-title">Manual Block / Availability</div>
        <p class="muted manage-help">Quickly add a manual hold or available slot (affects Check Availability).</p>

        <form method="post" class="manage-form" x-data="{ allDay: true }">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="add_block"/>

          <div class="manual-grid">
            <div class="field">
              <label>Calendar</label>
              <select class="in" name="calendar_id" required>
                <?php foreach ($cals as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= h($c['calendar_name']) ?><?= !empty($c['is_default']) ? " (default)" : "" ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>Date</label>
              <input class="in" type="date" name="date" required value="<?= date('Y-m-d') ?>"/>
            </div>

            <div class="field">
              <label>Status</label>
              <select class="in" name="type">
                <option value="busy">Busy / Blocked</option>
                <option value="available">Available</option>
              </select>
            </div>

            <div class="field">
              <label>&nbsp;</label>
              <label class="checkbox" style="margin:0;">
                <input type="checkbox" name="all_day" value="1" checked x-model="allDay"/> All Day
              </label>
            </div>

            <div class="field" x-show="!allDay" x-cloak>
              <label>Start time</label>
              <input class="in" type="time" name="start_time" value="18:00"/>
            </div>

            <div class="field" x-show="!allDay" x-cloak>
              <label>End time</label>
              <input class="in" type="time" name="end_time" value="21:00"/>
            </div>

            <div class="field span-2">
              <label>Title / Notes (optional)</label>
              <input class="in" name="title" placeholder="e.g. Out of town, Private hold…"/>
            </div>

            <div class="field">
              <label>&nbsp;</label>
              <button class="btn btn-primary" type="submit">Save</button>
            </div>
          </div>
        </form>

      </div>
    </div>
  </div>

  <!-- Calendar List -->
  <div class="card manage-table">
    <div class="card-body">
      <div class="card-title">Your Calendars</div>
        <p class="muted">Edit inline and click Save. For ICS calendars: Save first, then Import.</p>

      <?php if (!$cals): ?>
        <p class="muted">No calendars yet.</p>
      <?php else: ?>
        <div class="table">
          <div class="table-head manage-table-head" style="grid-template-columns: 1.45fr 0.75fr 0.65fr 0.7fr 1.15fr;">
            <div>Calendar</div>
            <div>Source</div>
            <div>Default</div>
            <div>Import</div>
            <div style="text-align:right;">Actions</div>
          </div>

          <?php foreach ($cals as $c): ?>
            <form class="table-row manage-row" method="post">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
              <input type="hidden" name="action" value="update_calendar"/>
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>"/>

              <!-- Calendar -->
              <div class="mc-cell">
                <span class="mc-dot" style="background: <?= h($c['calendar_color']) ?>"></span>

                <div class="mc-stack">
                  <div class="mc-top">
                    <input class="in" type="text" name="calendar_name" value="<?= h($c['calendar_name']) ?>" placeholder="Calendar name"/>
                    <input type="color" name="calendar_color" value="<?= h($c['calendar_color']) ?>" title="Calendar color"/>
                  </div>

                  <input class="in" type="text" name="description" value="<?= h($c['description'] ?? '') ?>" placeholder="Description"/>
                  <input class="in" type="url" name="source_url" value="<?= h($c['source_url'] ?? '') ?>" placeholder="ICS / iCal URL (optional)"/>

                  <?php if (!empty($c['last_error'])): ?>
                    <div class="muted mc-meta">Last error: <?= h($c['last_error']) ?></div>
                  <?php endif; ?>
                  <div class="muted mc-meta">Last synced: <?= !empty($c['last_synced_at']) ? h($c['last_synced_at']) : "Never" ?></div>
                </div>
              </div>

              <!-- Source -->
              <div>
                <?php $isLinked = (($c['source_type'] ?? 'manual') === 'ics'); ?>
                <span class="rs-badge <?= $isLinked ? 'rs-badge--info' : 'rs-badge--muted' ?>">
                  <?= $isLinked ? 'Linked' : 'Manual' ?>
                </span>
              </div>

              <!-- Default -->
              <div>
                <?php if (!empty($c['is_default'])): ?>
                  <span class="rs-badge rs-badge--gold">Default</span>
                <?php else: ?>
                  <a class="btn small"
                     href="<?= h(BASE_URL) ?>/manage_calendars.php?action=set_default&id=<?= (int)$c['id'] ?>&csrf=<?= h(csrf_token()) ?>">
                    Set Default
                  </a>
                <?php endif; ?>
              </div>

              <!-- Import -->
              <div>
                <?php
                  $hasUrl = !empty(trim((string)($c['source_url'] ?? '')));
                ?>
                <?php if ($hasUrl): ?>
                  <button
                    type="button"
                    class="btn small"
                    data-cal-id="<?= (int)$c['id'] ?>"
                    data-cal-name="<?= h($c['calendar_name']) ?>"
                    data-cal-url="<?= h($c['source_url']) ?>"
                    @click.stop="open($el.dataset.calId, $el.dataset.calName, $el.dataset.calUrl)"
                  >Import</button>
                <?php else: ?>
                  <button type="button" class="btn small" disabled title="Add a calendar URL to enable import">Import</button>
                <?php endif; ?>
              </div>

              <!-- Actions -->
              <div class="mc-actions" style="justify-content:flex-end;">
                <button type="submit" class="btn small">Save</button>
                <a class="btn small btn-danger btn-icon"
                   href="<?= h(BASE_URL) ?>/manage_calendars.php?action=delete&id=<?= (int)$c['id'] ?>&csrf=<?= h(csrf_token()) ?>"
                   onclick="return confirm('Delete this calendar?');">
                  <span aria-hidden="true">🗑️</span>
                  <span class="sr-only">Delete</span>
                </a>
              </div>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Import Calendar Modal -->
  <template x-if="isOpen">
    <div class="rs-modal-backdrop" x-cloak @click="close()" @keydown.escape.window="close()">
      <div class="rs-modal" role="dialog" aria-label="Import Calendar" @click.stop>
        <div class="rs-modal__head">
          <h3 style="margin:0;">Import Calendar</h3>
          <button class="btn small" type="button" @click="close()">✕</button>
        </div>

        <div class="rs-modal__body">
          <div class="muted" style="margin-bottom:10px;">
            <strong x-text="calName"></strong>
          </div>

          <label>Import Mode
            <select class="in" x-model="mode">
              <option value="all">Import Entire Calendar</option>
              <option value="range">Import Date Range Only</option>
            </select>
          </label>

          <template x-if="mode === 'range'">
            <div class="two-col" style="margin-top:10px;">
              <label>Start Date
                <input class="in" type="date" x-model="startDate" />
              </label>
              <label>End Date
                <input class="in" type="date" x-model="endDate" />
              </label>
            </div>
          </template>

          <div style="margin-top:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <button class="btn btn-primary" type="button" @click="preview()" :disabled="loading">
              <span x-show="!loading">Preview Events</span>
              <span x-show="loading">Loading…</span>
            </button>

            <label class="checkbox" style="margin:0;">
              <input type="checkbox" x-model="deleteInRange" />
              Delete/replace existing imported events in the chosen range
            </label>
          </div>

          <template x-if="error">
            <div class="alert" style="margin-top:12px;" x-text="error"></div>
          </template>

          <template x-if="previewReady">
            <div style="margin-top:14px;">
              <div class="muted" style="margin-bottom:8px;">
                <span x-text="events.length"></span> event instance(s) in preview
                <span class="muted" x-show="effectiveRangeText">• <span x-text="effectiveRangeText"></span></span>
              </div>

              <div class="rs-preview">
                <template x-for="(ev, idx) in events" :key="ev._key">
                  <label class="rs-preview__item">
                    <input type="checkbox" x-model="ev.selected" />
                    <div class="rs-preview__meta">
                      <div class="rs-preview__title" x-text="ev.summary"></div>
                      <div class="muted" x-text="ev.when"></div>
                      <div class="muted" x-show="ev.location" x-text="ev.location"></div>
                      <div class="muted" x-show="ev.description" x-text="ev.description"></div>
                    </div>
                  </label>
                </template>
              </div>
            </div>
          </template>
        </div>

        <div class="rs-modal__foot">
          <button class="btn" type="button" @click="close()">Cancel</button>
          <button class="btn btn-primary" type="button" @click="importSelected()" :disabled="!previewReady || importing">
            <span x-show="!importing">Import Selected</span>
            <span x-show="importing">Importing…</span>
          </button>
        </div>
      </div>
    </div>
  </template>

  <script>
  function icsImport(){
    return {
      isOpen:false,
      calId:null,
      calName:'',
      sourceUrl:'',
      mode:'all',
      startDate:'',
      endDate:'',
      deleteInRange:false,
      loading:false,
      importing:false,
      error:'',
      previewReady:false,
      events:[],
      effectiveRange:null,

      get csrf(){
        return document.querySelector('meta[name="csrf"]')?.getAttribute('content') || '';
      },

      get effectiveRangeText(){
        if(!this.effectiveRange) return '';
        return `Range: ${this.effectiveRange.start} → ${this.effectiveRange.end}`;
      },

      open(id,name,url){
        this.isOpen = true;
        this.calId = id;
        this.calName = name || 'Calendar';
        this.sourceUrl = url || '';
        this.mode = 'all';
        const today = new Date();
        const pad = n => String(n).padStart(2,'0');
        const ymd = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
        this.startDate = ymd(today);
        const end = new Date(today.getTime()); end.setDate(end.getDate()+30);
        this.endDate = ymd(end);
        this.deleteInRange = false;
        this.error = '';
        this.previewReady = false;
        this.events = [];
        this.effectiveRange = null;
      },

      close(){ this.isOpen = false; },

      async preview(){
        this.error='';
        this.loading=true;
        this.previewReady=false;
        this.events=[];
        this.effectiveRange=null;

        if(this.mode==='range'){
          if(!this.startDate || !this.endDate){
            this.loading=false;
            this.error='Start Date and End Date are required.';
            return;
          }
          if(this.endDate < this.startDate){
            this.loading=false;
            this.error='End Date must be on or after Start Date.';
            return;
          }
        }

        try{
          const res = await fetch(`${BASE_URL}/api/ics_preview.php`,{
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},
            body: JSON.stringify({
              calendar_id: this.calId,
              mode: this.mode,
              start_date: this.startDate,
              end_date: this.endDate
            })
          });
          const data = await res.json();
          if(!data.success){
            this.error = data.error || 'Preview failed.';
          } else {
            this.effectiveRange = data.effective_range || null;
            this.events = (data.events || []).map((e,i)=>({
              ...e,
              selected: true,
              _key: (e.uid || 'uid') + '|' + e.start_utc + '|' + i
            }));
            this.previewReady = true;
          }
        } catch(e){
          this.error = 'Preview failed. Please try again.';
        } finally {
          this.loading=false;
        }
      },

      async importSelected(){
        this.error='';
        this.importing=true;
        try{
          const chosen = this.events.filter(e=>e.selected);
          if(chosen.length===0){
            this.error = 'No events selected.';
            this.importing=false;
            return;
          }

          const res = await fetch(`${BASE_URL}/api/ics_import.php`,{
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},
            body: JSON.stringify({
              calendar_id: this.calId,
              mode: this.mode,
              start_date: this.startDate,
              end_date: this.endDate,
              delete_in_range: !!this.deleteInRange,
              effective_range: this.effectiveRange,
              events: chosen.map(e=>({
                uid: e.uid || null,
                summary: e.summary || '',
                description: e.description || null,
                location: e.location || null,
                is_all_day: e.is_all_day ? 1 : 0,
                start_utc: e.start_utc,
                end_utc: e.end_utc
              }))
            })
          });
          const data = await res.json();
          if(!data.success){
            this.error = data.error || 'Import failed.';
          } else {
            window.location = `${BASE_URL}/manage_calendars.php?flash=` + encodeURIComponent(data.message || 'Imported.');
          }
        } catch(e){
          this.error = 'Import failed. Please try again.';
        } finally {
          this.importing=false;
        }
      }
    }
  }
  </script>

</div>

<?php page_footer(); ?>
