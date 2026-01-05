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
	} else if ($action === 'set_default') {
		// Fired by the "Set Default" button inside the calendar row (POST)
		$calId = (int)($_POST['id'] ?? 0);
		if ($calId <= 0) {
			$err = "Missing calendar.";
		} else {
			$stmt = $pdo->prepare("SELECT id FROM user_calendars WHERE id=? AND user_id=? LIMIT 1");
			$stmt->execute([$calId, $u['id']]);
			if ($stmt->fetch()) {
				set_default_calendar($pdo, (int)$u['id'], $calId);
				$flash = "Default calendar updated.";
			} else {
				$err = "Calendar not found.";
			}
		}
		
	} else if ($action === 'delete') {
		// Fired by the trash icon inside the calendar row (POST)
		$calId = (int)($_POST['id'] ?? 0);
		if ($calId <= 0) {
			$err = "Missing calendar.";
		} else {
			$stmt = $pdo->prepare("DELETE FROM user_calendars WHERE id=? AND user_id=?");
			$stmt->execute([$calId, $u['id']]);
			$flash = "Calendar deleted.";
		}
		
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

<style>
/* Page container like screenshot */
.mc-container{
  max-width: 1100px;
  margin: 0 auto;
  padding: 18px 14px;
}
.mc-stack{
  display:flex;
  flex-direction:column;
  gap: 18px;
}

/* Form layout helpers */
.mc-form{ display:block; }
.mc-grid-4{
  display:grid;
  grid-template-columns: 90px 260px 1fr 220px;
  gap: 14px;
  align-items:end;
}
@media (max-width: 900px){
  .mc-grid-4{ grid-template-columns: 1fr 1fr; }
}
@media (max-width: 560px){
  .mc-grid-4{ grid-template-columns: 1fr; }
}

.mc-grid-3{
  display:grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 14px;
}
@media (max-width: 900px){
  .mc-grid-3{ grid-template-columns: 1fr; }
}
.mc-grid-2{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}
@media (max-width: 900px){
  .mc-grid-2{ grid-template-columns: 1fr; }
}

.mc-field label{
  display:block;
  font-weight: 600;
  margin: 0 0 6px;
}
.mc-field .in, .mc-field select, .mc-field textarea{
 /* width:100%; */
}

/* Checkbox label tight to the right */
.mc-check{
  display:inline-flex;
  gap: 10px;
  align-items:center;
  justify-content:flex-start;
  user-select:none;
  cursor:pointer;
}
.mc-check input{ margin:0; }

/* Badges */
.badge{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: .78rem;
  border: 1px solid rgba(0,0,0,.10);
  background: rgba(0,0,0,.04);
}
.badge.linked{ }
.badge.default{ }

/* Calendars list rows */
.mc-cal-row{
  display:grid;
  grid-template-columns: 90px 260px 1fr 220px 44px;
  gap: 14px;
  align-items:start;
  padding: 12px 0;
  border-top: 1px solid rgba(0,0,0,.08);
}
.mc-cal-row:first-child{ border-top: 0; padding-top: 0; }
.mc-cal-actions{
  display:flex;
  flex-direction:column;
  gap: 10px;
  align-items:stretch;
}
.mc-cal-meta{
  display:flex;
  gap: 10px;
  flex-wrap:wrap;
  align-items:center;
  margin-top: 8px;
}

.icon-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid rgba(0,0,0,.10);
  background: rgba(0,0,0,.02);
  cursor:pointer;
}
.icon-btn:hover{ background: rgba(0,0,0,.05); }
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

/* Keep Alpine hidden blocks hidden until ready */
[x-cloak]{ display:none !important; }
</style>

<div class="mc-container" x-data="icsImport()">
  <?php if ($flash): ?><div class="alert success"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert"><?= h($err) ?></div><?php endif; ?>

  <div class="mc-stack">

    <!-- Add New Calendar -->
    <div class="card">
      <div class="card-body">
        <div class="card-title">Add New Calendar</div>

        <form method="post" class="mc-form" x-data="{ linked:false }">
          <input type="hidden" name="action" value="add_calendar"/>
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>

          <div class="mc-grid-4">
            <div class="mc-field">
              <label>Color</label>
              <input class="in color-picker" type="color" name="calendar_color" value="#3b82f6"/>
            </div>

            <div class="mc-field">
              <label>Name</label>
              <input class="in" type="text" name="calendar_name" placeholder="Calendar name" required/>
            </div>

            <div class="mc-field">
              <label>Description</label>
              <input class="in" type="text" name="description" placeholder="Optional"/>
            </div>

            <div class="mc-field">
              <label>&nbsp;</label>
              <label class="mc-check">
                <input type="checkbox" name="is_default" value="1"/>
                <span>Default calendar</span>
              </label>
            </div>
          </div>

          <div style="margin-top:14px;">
            <label class="mc-check">
              <input type="checkbox" x-model="linked" name="link_external" value="1"/>
              <span>Link external calendar (optional)</span>
            </label>
          </div>

          <div class="mc-field" x-show="linked" x-cloak style="margin-top:10px;">
            <label>Calendar URL</label>
            <input class="in" type="url" name="source_url" placeholder="https://calendar.google.com/calendar/ical/.../public/basic.ics"/>
            <div class="muted" style="margin-top:6px;">Tip: paste a public ICS feed URL (Google, iCloud, Outlook, etc.).</div>
          </div>

          <div style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
            <button class="btn btn-primary" type="submit">Add Calendar</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Manual Block / Availability -->
    <div class="card">
      <div class="card-body">
        <div class="card-title">Manual Block / Availability</div>

        <form method="post" class="mc-form" x-data="{ allDay: true }">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
          <input type="hidden" name="action" value="add_block"/>

          <div class="mc-grid-3">
            <div class="mc-field">
              <label>Calendar</label>
              <select class="in" name="calendar_id" required>
                <?php foreach ($cals as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= h($c['calendar_name'] ?? '') ?><?= !empty($c['is_default']) ? " (default)" : "" ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mc-field">
              <label>Date</label>
              <input class="in" type="date" name="date" value="<?= h(date('Y-m-d')) ?>" required/>
            </div>

            <div class="mc-field">
              <label>Status</label>
              <select class="in" name="type" required>
                <option value="busy">Busy</option>
                <option value="available">Available</option>
              </select>
            </div>
          </div>

          <div class="mc-field" style="margin-top:14px;">
            <label>Title</label>
            <input class="in" type="text" name="title" placeholder="Optional"/>
          </div>

          <div style="margin-top:14px;">
            <label class="mc-check">
              <input type="checkbox" name="all_day" value="1" checked x-model="allDay"/>
              <span>All Day</span>
            </label>
          </div>

          <div class="mc-grid-2" x-show="!allDay" x-cloak style="margin-top:14px;">
            <div class="mc-field">
              <label>Start time</label>
              <input class="in" type="time" name="start_time" value="19:00"/>
            </div>
            <div class="mc-field">
              <label>End time</label>
              <input class="in" type="time" name="end_time" value="21:00"/>
            </div>
          </div>

          <div style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
            <button class="btn btn-primary" type="submit">Save</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Your Calendars -->
    <div class="card">
      <div class="card-body">
        <div class="card-title">Your Calendars</div>
        <p class="muted" style="margin-top:6px;">Edit inline and click Save. For linked calendars: Save first, then Import.</p>

        <?php if (!$cals): ?>
          <div class="muted">No calendars yet. Add one above.</div>
        <?php else: ?>
          <?php foreach ($cals as $c): ?>
            <form class="mc-cal-row" method="post">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
              <input type="hidden" name="action" value="update_calendar"/>
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>"/>

              
              
              <?php if (!empty($c['source_url'])): ?>
                <input type="hidden" name="source_url" value="<?= h($c['source_url']) ?>"/>
              <?php endif; ?>

              <div class="mc-field">
                <label>Color</label>
                <input class="in color-picker" type="color" name="calendar_color" value="<?= h($c['calendar_color'] ?? '#3b82f6') ?>"/>
              </div>

              <div class="mc-field">
                <label>Name</label>
                <input class="in" type="text" name="calendar_name" value="<?= h($c['calendar_name'] ?? '') ?>" required/>
                <div class="mc-cal-meta">
                  <?php if (!empty($c['source_url'])): ?>
                    <span class="badge linked">Linked</span>
                  <?php endif; ?>
                  <?php if (!empty($c['is_default'])): ?>
                    <span class="badge default">Default</span>
                  <?php endif; ?>
                  <?php if (!empty($c['last_synced_at'])): ?>
                    <span class="muted">Last synced: <?= h($c['last_synced_at']) ?></span>
                  <?php else: ?>
                    <span class="muted">Last synced: Never</span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="mc-field">
                <label>Description</label>
                <input class="in" type="text" name="description" value="<?= h($c['description'] ?? '') ?>"/>
                <?php if (!empty($c['source_url'])): ?>
                  <div class="muted" style="margin-top:6px; overflow-wrap:anywhere;"><?= h($c['source_url']) ?></div>
                <?php endif; ?>
              </div>

              <div class="mc-cal-actions">
                <button class="btn" type="submit">Save</button>

                <button class="btn" type="submit" name="action" value="set_default">
                  Set Default
                </button>

                <?php if (!empty($c['source_url'])): ?>
                  <button class="btn btn-primary" type="button"
                    @click='open(<?= (int)$c['id'] ?>, <?= json_encode((string)($c['calendar_name'] ?? '')) ?>, <?= json_encode((string)($c['source_url'] ?? '')) ?>)'>
                    Import
                  </button>
                <?php else: ?>
                  <button class="btn" type="button" disabled title="Only linked calendars can be imported.">Import</button>
                <?php endif; ?>
              </div>

              <div style="display:flex; justify-content:flex-end; padding-top: 22px;">
                <button class="icon-btn" type="submit" name="action" value="delete" title="Delete calendar"
                        onclick="return confirm('Delete this calendar? This will also delete its imported events.');">
                  🗑️<span class="sr-only">Delete</span>
                </button>
              </div>
            </form>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
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
  // Use global BASE_URL from layout if present; otherwise set it once.
  window.BASE_URL = window.BASE_URL ?? <?= json_encode(BASE_URL) ?>;
  const baseUrl = window.BASE_URL;

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
          const res = await fetch(`${baseUrl}/api/ics_preview.php`,{
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

          const res = await fetch(`${baseUrl}/api/ics_import.php`,{
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
            window.location = `${baseUrl}/manage_calendars.php?flash=` + encodeURIComponent(data.message || 'Imported.');
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
