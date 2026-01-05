<?php
require_once __DIR__ . "/_layout.php";
require_login();

$u = auth_user();
$pdo = db();

$tz = new DateTimeZone($u['timezone'] ?? 'UTC');

// ---------------------------------------------
// Month selection (weeks start on Sunday)
// ---------------------------------------------
$ym = $_GET['ym'] ?? null; // YYYY-MM
try {
    if ($ym && preg_match('/^\d{4}-\d{2}$/', $ym)) {
        $monthStart = new DateTimeImmutable($ym . "-01 00:00:00", $tz);
    } else {
        $monthStart = (new DateTimeImmutable('now', $tz))->modify('first day of this month')->setTime(0,0,0);
        $ym = $monthStart->format('Y-m');
    }
} catch (Throwable $e) {
    $monthStart = (new DateTimeImmutable('now', $tz))->modify('first day of this month')->setTime(0,0,0);
    $ym = $monthStart->format('Y-m');
}

$monthEnd = $monthStart->modify('last day of this month')->setTime(23,59,59);

// Grid start (Sunday)
$dow = (int)$monthStart->format('w'); // 0=Sun..6=Sat
$gridStart = $monthStart->modify("-{$dow} days")->setTime(0,0,0);

// Grid end (Saturday)
$endDow = (int)$monthEnd->format('w');
$daysToSat = 6 - $endDow;
$gridEnd = $monthEnd->modify("+{$daysToSat} days")->setTime(23,59,59);

// Prev/next month
$prevYm = $monthStart->modify('-1 month')->format('Y-m');
$nextYm = $monthStart->modify('+1 month')->format('Y-m');
$todayYm = (new DateTimeImmutable('now', $tz))->format('Y-m');

// ---------------------------------------------
// Load calendars
// ---------------------------------------------
$stmt = $pdo->prepare("
  SELECT id, calendar_name, calendar_color, description
  FROM user_calendars
  WHERE user_id=?
  ORDER BY calendar_name ASC
");
$stmt->execute([$u['id']]);
$calendars = $stmt->fetchAll();

$allCalendarIds = array_map(fn($c) => (int)$c['id'], $calendars);

// Selected calendars
// Default is "all" *unless* the user has explicitly applied a filter.
// Important: the form can't submit an empty cal[] array, so we add a hidden
// cal_filter=1 flag. When that flag is present and cal[] is absent/empty,
// we interpret it as "show none" (and therefore show no events).
$filterApplied = isset($_GET['cal_filter']) || isset($_GET['cal']);

$selected = $_GET['cal'] ?? [];
if (!is_array($selected)) { $selected = [$selected]; }
$selectedIds = array_values(array_unique(array_filter(array_map('intval', $selected))));

if (!$filterApplied) {
  // First visit: show everything
  $selectedIds = $allCalendarIds;
}
$selectedSet = array_fill_keys($selectedIds, true);

// Preserve filter state across month navigation.
// We only add cal_filter to the query once the user has interacted with filters.
$calQs = '';
if ($filterApplied) {
  $params = ['cal_filter' => 1];
  if ($selectedIds) { $params['cal'] = $selectedIds; }
  $calQs = '&' . http_build_query($params);
}

// ---------------------------------------------
// Fetch events overlapping grid range (UTC storage)
// Convert local gridStart/gridEnd to UTC for query.
// ---------------------------------------------
$gridStartUtc = $gridStart->setTimezone(new DateTimeZone('UTC'));
$gridEndUtcExcl = $gridEnd->modify('+1 second')->setTimezone(new DateTimeZone('UTC')); // exclusive-ish

$eventsByDay = []; // 'Y-m-d' => [event,...]
$eventsRaw = [];

if ($allCalendarIds && $selectedIds) {
    $in = implode(',', array_fill(0, count($selectedIds), '?'));

    $sql = "
      SELECT e.id, e.calendar_id, e.title, e.notes, e.status, e.is_all_day,
             e.start_utc, e.end_utc, e.source,
             c.calendar_name, c.calendar_color
      FROM calendar_events e
      JOIN user_calendars c ON c.id = e.calendar_id
      WHERE c.user_id = ?
        AND e.calendar_id IN ($in)
        AND e.start_utc < ?
        AND e.end_utc > ?
      ORDER BY e.start_utc ASC
    ";
    $params = array_merge([$u['id']], $selectedIds, [
        $gridEndUtcExcl->format('Y-m-d H:i:s'),
        $gridStartUtc->format('Y-m-d H:i:s'),
    ]);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $eventsRaw = $stmt->fetchAll();

    // Index to days in local tz (include multi-day spans)
    $gridStartDay = $gridStart->format('Y-m-d');
    $gridEndDay = $gridEnd->format('Y-m-d');

    foreach ($eventsRaw as $e) {
        $startUtc = new DateTimeImmutable($e['start_utc'], new DateTimeZone('UTC'));
        $endUtc = new DateTimeImmutable($e['end_utc'], new DateTimeZone('UTC'));

        $startLocal = $startUtc->setTimezone($tz);
        $endLocal = $endUtc->setTimezone($tz);

        // For all-day, treat as spanning dates; otherwise, include start day plus any additional day spanned.
        $spanStart = $startLocal->setTime(0,0,0);
        // subtract 1 second so events ending at midnight count on previous day
        $spanEnd = $endLocal->modify('-1 second')->setTime(0,0,0);

        $cur = $spanStart;
        while ($cur <= $spanEnd) {
            $dayKey = $cur->format('Y-m-d');
            if ($dayKey >= $gridStartDay && $dayKey <= $gridEndDay) {
                $eventsByDay[$dayKey][] = [
                    'id' => (int)$e['id'],
                    'calendar_id' => (int)$e['calendar_id'],
                    'title' => $e['title'] ?: '(No title)',
                    'notes' => $e['notes'] ?? '',
                    'status' => $e['status'],
                    'is_all_day' => (int)$e['is_all_day'] === 1,
                    'source' => $e['source'],
                    'start_local' => $startLocal,
                    'end_local' => $endLocal,
                    'calendar_name' => $e['calendar_name'],
                    'calendar_color' => $e['calendar_color'] ?: '#3b82f6',
                ];
            }
            $cur = $cur->modify('+1 day');
        }
    }
}



// -------------------------------------------------
// Dashboard modal payload (date -> events JSON)
// -------------------------------------------------
$eventsJsonByDay = [];
foreach ($eventsByDay as $dayKey => $evs) {
    $eventsJsonByDay[$dayKey] = array_map(function($ev) use ($tz) {
        $startLocal = $ev['start_local'];
        $endLocal = $ev['end_local'];
        return [
            'id' => (int)$ev['id'],
            'calendar_id' => (int)$ev['calendar_id'],
            'calendar_name' => (string)$ev['calendar_name'],
            'calendar_color' => (string)$ev['calendar_color'],
            'title' => (string)$ev['title'],
            'status' => (string)$ev['status'],
            'source' => (string)$ev['source'],
            'is_all_day' => $ev['is_all_day'] ? 1 : 0,
            // local, for editing
            'date' => $startLocal->format('Y-m-d'),
            'start_time' => $ev['is_all_day'] ? '' : $startLocal->format('H:i'),
            'end_time' => $ev['is_all_day'] ? '' : $endLocal->format('H:i'),
            // display
            'start_label' => $ev['is_all_day'] ? 'All day' : $startLocal->format('g:ia'),
            'end_label' => $ev['is_all_day'] ? '' : $endLocal->format('g:ia'),
            'notes' => (string)($ev['notes'] ?? ''),
        ];
    }, $evs);
}

// Track a lightweight dashboard view
track_event('dashboard_month_view', [
    'ym' => $ym,
    'grid_start' => $gridStart->format('Y-m-d'),
    'grid_end' => $gridEnd->format('Y-m-d'),
    'selected_calendars' => count($selectedIds),
    'total_events' => count($eventsRaw),
], $u['id']);

page_header("Dashboard");
?>

<style>
  .dash-wrap{max-width:1200px;margin:0 auto;}
  .dash-top{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px;}
  .dash-top h1{margin:0;font-size:28px;letter-spacing:.2px}
  .dash-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
  .dash-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 11px;border-radius:12px;border:1px solid rgba(255,255,255,.14);
    background:rgba(0,0,0,.20);color:inherit;text-decoration:none;font-weight:600;font-size:13px}
  .dash-btn:hover{border-color:rgba(255,215,120,.35);transform:translateY(-1px)}
  .dash-btn.primary{border-color:rgba(255,215,120,.28);background:rgba(255,215,120,.08)}
  .dash-main{display:grid;grid-template-columns:280px 1fr;gap:16px;align-items:start}
  @media (max-width: 980px){.dash-main{grid-template-columns:1fr}.dash-actions{justify-content:flex-start}}
  .panel{border-radius:18px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.18);padding:14px;box-shadow:0 20px 60px rgba(0,0,0,.28)}
  .panel h3{margin:0 0 10px;font-size:14px;letter-spacing:.12em;text-transform:uppercase;opacity:.9}
  .cal-list{display:grid;gap:10px;margin-top:8px}
  .cal-item{display:flex;align-items:flex-start;gap:10px}
  .dot{width:10px;height:10px;border-radius:50%;margin-top:4px;box-shadow:0 0 14px rgba(0,0,0,.3)}
  .cal-item label{display:flex;gap:10px;cursor:pointer;user-select:none}
  .cal-name{font-weight:650}
  .cal-desc{font-size:12.5px;opacity:.75;margin-top:2px}
  .cal-controls{display:flex;gap:10px;margin-top:8px}
  .cal-controls button{padding:7px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.16);color:inherit;cursor:pointer;font-weight:600;font-size:12px}
  .cal-controls button:hover{border-color:rgba(255,215,120,.35)}
  .hint{font-size:13px;opacity:.85;line-height:1.45}
  .hint a{color:inherit}
  .monthbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
  .month-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .month-title{font-size:18px;font-weight:750}
  .navbtn{padding:7px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.16);color:inherit;text-decoration:none;font-weight:700}
  .navbtn:hover{border-color:rgba(255,215,120,.35)}
  .grid{display:grid;grid-template-columns:repeat(7,1fr);gap:0;border:1px solid rgba(255,255,255,.10);border-radius:16px;overflow:hidden;background:rgba(0,0,0,.12)}
  .dow{padding:10px 10px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.8;border-bottom:1px solid rgba(255,255,255,.08);background:rgba(0,0,0,.18)}
  .day{min-height:120px;padding:10px;border-right:1px solid rgba(255,255,255,.06);border-bottom:1px solid rgba(255,255,255,.06);position:relative}
  .day:nth-child(7n){border-right:none}
  .day-num{display:flex;align-items:center;justify-content:space-between;gap:10px}
  .num{font-weight:750;opacity:.95}
  .muted{opacity:.55}
  .today .num{padding:2px 7px;border-radius:999px;border:1px solid rgba(255,215,120,.28);background:rgba(255,215,120,.08)}
  .chips{margin-top:8px;display:grid;gap:6px}
  .chip{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:10px;border:1px solid rgba(255,255,255,.10);
    background:rgba(0,0,0,.16);font-size:12.5px;line-height:1.2;overflow:hidden}
  .chip .bar{width:3px;align-self:stretch;border-radius:4px}
  .chip .t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .more{font-size:12px;opacity:.7;margin-top:4px}
  .more a{color:inherit;text-decoration:none}
  .more a:hover{text-decoration:underline}
</style>

<div class="dash-wrap">

  <div class="dash-top">
    <div>
      <h1>Dashboard</h1>
      <div class="hint">A month-at-a-glance view of your calendars. Filter by calendar on the left.</div>
    </div>
    <div class="dash-actions">
      <a class="dash-btn primary" href="<?= h(BASE_URL) ?>/manage_calendars.php">Add Calendar</a>
      <a class="dash-btn" href="<?= h(BASE_URL) ?>/manage_calendars.php#import">Import ICS</a>
      <a class="dash-btn" href="<?= h(BASE_URL) ?>/check_availability.php">Check Availability</a>
      <a class="dash-btn" href="<?= h(BASE_URL) ?>/public_availability.php">Public Link</a>
    </div>
  </div>

  <div class="dash-main">

    <aside class="panel">
      <h3>Calendars</h3>

      <?php if (!$calendars): ?>
        <div class="hint">
          <strong>First time here?</strong><br/>
          Add at least one calendar to see events on your dashboard.<br/><br/>
          <a class="dash-btn primary" href="<?= h(BASE_URL) ?>/manage_calendars.php">Go to Calendars</a>
        </div>
      <?php else: ?>
        <form id="calFilter" method="get" action="<?= h(BASE_URL) ?>/dashboard.php">
          <input type="hidden" name="ym" value="<?= h($ym) ?>"/>
          <input type="hidden" name="cal_filter" value="1"/>

          <div class="cal-controls">
            <button type="button" id="calAll">All</button>
            <button type="button" id="calNone">None</button>
          </div>

          <div class="cal-list">
            <?php foreach ($calendars as $c): 
              $cid = (int)$c['id'];
              $checked = isset($selectedSet[$cid]);
              $color = $c['calendar_color'] ?: '#3b82f6';
            ?>
              <div class="cal-item">
                <label>
                  <input type="checkbox" name="cal[]" value="<?= h((string)$cid) ?>" <?= $checked ? 'checked' : '' ?> />
                  <span class="dot" style="background:<?= h($color) ?>"></span>
                  <span>
                    <div class="cal-name"><?= h($c['calendar_name']) ?></div>
                    <?php if (!empty($c['description'])): ?>
                      <div class="cal-desc"><?= h($c['description']) ?></div>
                    <?php endif; ?>
                  </span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </form>

        <script>
          (function(){
            const form = document.getElementById('calFilter');
            if(!form) return;

            // autosubmit on checkbox change
            form.addEventListener('change', (e) => {
              if (e.target && e.target.matches('input[type="checkbox"]')) form.submit();
            });

            const allBtn = document.getElementById('calAll');
            const noneBtn = document.getElementById('calNone');
            const boxes = () => Array.from(form.querySelectorAll('input[type="checkbox"][name="cal[]"]'));

            allBtn?.addEventListener('click', () => { boxes().forEach(b => b.checked = true); form.submit(); });
            noneBtn?.addEventListener('click', () => { boxes().forEach(b => b.checked = false); form.submit(); });
          })();
        </script>
      <?php endif; ?>
    </aside>

    <section class="panel" x-data='dashDayModal(<?= htmlspecialchars(json_encode($eventsJsonByDay, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>, <?= json_encode(csrf_token()) ?>)'>
      <div class="monthbar">
        <div class="month-left">
          <a class="navbtn" href="<?= h(BASE_URL) ?>/dashboard.php?ym=<?= h($prevYm) ?><?= h($calQs) ?>">‹</a>
          <a class="navbtn" href="<?= h(BASE_URL) ?>/dashboard.php?ym=<?= h($todayYm) ?><?= h($calQs) ?>">Today</a>
          <a class="navbtn" href="<?= h(BASE_URL) ?>/dashboard.php?ym=<?= h($nextYm) ?><?= h($calQs) ?>">›</a>
          <div class="month-title"><?= h($monthStart->format('F Y')) ?></div>
        </div>
        <div class="hint">Grid: <?= h($gridStart->format('M j')) ?> – <?= h($gridEnd->format('M j, Y')) ?></div>
      </div>

      <div class="grid" role="grid" aria-label="Month calendar">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
          <div class="dow"><?= h($d) ?></div>
        <?php endforeach; ?>

        <?php
          $cur = $gridStart;
          $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
          $monthKey = $monthStart->format('Y-m');

          while ($cur <= $gridEnd):
            $dayKey = $cur->format('Y-m-d');
            $inMonth = ($cur->format('Y-m') === $monthKey);
            $isToday = ($dayKey === $today);
            $events = $eventsByDay[$dayKey] ?? [];
            $maxShow = 3;
        ?>
          <div class="day <?= $isToday ? 'today' : '' ?>" role="button" tabindex="0" style="cursor:pointer" @click="openDay('<?= h($dayKey) ?>')" @keydown.enter.prevent="openDay('<?= h($dayKey) ?>')">
            <div class="day-num">
              <div class="num <?= $inMonth ? '' : 'muted' ?>"><?= h($cur->format('j')) ?></div>
              <div class="muted" style="font-size:12px;">
                <?= h($cur->format('D')) ?>
              </div>
            </div>

            <?php if ($events): ?>
              <div class="chips">
                <?php foreach (array_slice($events, 0, $maxShow) as $ev): 
                  $bar = $ev['calendar_color'] ?: '#3b82f6';
                  $time = $ev['is_all_day'] ? 'All day' : $ev['start_local']->format('g:ia');
                  $label = $time . ' ' . $ev['title'];
                ?>
                  <div class="chip" title="<?= h($ev['calendar_name']) ?>">
                    <span class="bar" style="background:<?= h($bar) ?>"></span>
                    <span class="t"><?= h($label) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if (count($events) > $maxShow): ?>
                <div class="more">
                  <a onclick="event.stopPropagation()" href="<?= h(BASE_URL) ?>/check_availability.php?date=<?= h($dayKey) ?>">+<?= h((string)(count($events)-$maxShow)) ?> more</a>
                </div>
              <?php endif; ?>
            <?php endif; ?>

          </div>
        <?php
            $cur = $cur->modify('+1 day');
          endwhile;
        ?>
      </div>

      <!-- Day details modal -->
      <div class="rs-modal-backdrop" x-cloak x-show="open" @keydown.escape.window="close()" @click.self="close()">
        <div class="rs-modal" role="dialog" aria-modal="true" aria-label="Day details">
          <div class="rs-modal__head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <div>
              <div style="font-weight:800;font-size:16px;">Events — <span x-text="dayPretty"></span></div>
              <div class="muted" style="font-size:12px;margin-top:2px;" x-text="events.length ? (events.length + ' event(s)') : 'No events'"></div>
            </div>
            <button class="icon-btn" type="button" @click="close()" aria-label="Close">
              <span style="font-size:18px;line-height:1;">×</span>
            </button>
          </div>

          <div class="rs-modal__body">
            <template x-if="!events.length">
              <div class="muted">No events on this date for the selected calendars.</div>
            </template>

            <div style="display:grid;gap:10px;" x-show="events.length">
              <template x-for="ev in events" :key="ev.id">
                <div class="card" style="border-radius:16px;">
                  <div class="card-body" style="padding:12px;">
                    <div style="display:flex;gap:12px;justify-content:space-between;flex-wrap:wrap;">
                      <div style="min-width:240px;flex:1;">
                        <div style="display:flex;align-items:center;gap:10px;">
                          <span class="dot" :style="'background:' + (ev.calendar_color || '#3b82f6')"></span>
                          <div style="font-weight:800;" x-text="ev.title || '(No title)'"></div>
                        </div>
                        <div class="muted" style="margin-top:4px;" x-text="ev.start_label + (ev.end_label ? (' – ' + ev.end_label) : '')"></div>
                        <div class="muted" style="margin-top:4px;" x-text="ev.calendar_name + ' • ' + ev.status + ' • ' + ev.source"></div>
                        <template x-if="ev.notes">
                          <div style="margin-top:8px;white-space:pre-wrap;" x-text="ev.notes"></div>
                        </template>
                      </div>

                      <div style="display:flex;gap:8px;align-items:flex-start;">
                        <button class="dash-btn" type="button" @click="startEdit(ev)">Edit</button>
                        <button class="dash-btn" type="button" @click="deleteEvent(ev)">Delete</button>
                      </div>
                    </div>

                    <!-- Inline editor -->
                    <div x-show="editingId === ev.id" style="margin-top:12px;border-top:1px solid rgba(255,255,255,.10);padding-top:12px;">
                      <div class="form-grid">
                        <div class="span-2">
                          <label>Title</label>
                          <input type="text" x-model="edit.title" />
                        </div>
                        <div>
                          <label>Status</label>
                          <select x-model="edit.status">
                            <option value="busy">Busy</option>
                            <option value="tentative">Tentative</option>
                            <option value="available">Available</option>
                          </select>
                        </div>
                        <div>
                          <label>All day</label>
                          <select x-model="edit.is_all_day">
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                          </select>
                        </div>
                        <div>
                          <label>Date</label>
                          <input type="date" x-model="edit.date" />
                        </div>
                        <div x-show="String(edit.is_all_day) !== '1'">
                          <label>Start</label>
                          <input type="time" x-model="edit.start_time" />
                        </div>
                        <div x-show="String(edit.is_all_day) !== '1'">
                          <label>End</label>
                          <input type="time" x-model="edit.end_time" />
                        </div>
                        <div class="span-2">
                          <label>Notes</label>
                          <textarea rows="4" x-model="edit.notes"></textarea>
                        </div>
                      </div>

                      <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
                        <button class="dash-btn primary" type="button" @click="saveEdit(ev)">Save changes</button>
                        <button class="dash-btn" type="button" @click="cancelEdit()">Cancel</button>
                        <div class="muted" style="font-size:12px;" x-text="editError"></div>
                      </div>
                    </div>
                  </div>
                </div>
              </template>
            </div>
          </div>

          <div class="rs-modal__foot" style="display:flex;justify-content:flex-end;gap:10px;">
            <button class="dash-btn" type="button" @click="close()">Close</button>
          </div>
        </div>
      </div>

      <script>
        function dashDayModal(eventsByDay, csrf) {
          return {
            open: false,
            day: '',
            eventsByDay: eventsByDay || {},
            events: [],
            editingId: null,
            edit: {},
            editError: '',
            get dayPretty() {
              if (!this.day) return '';
              try {
                const d = new Date(this.day + 'T00:00:00');
                return d.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
              } catch(e) { return this.day; }
            },
            openDay(dayKey) {
              this.day = dayKey;
              this.events = (this.eventsByDay[dayKey] || []).slice();
              this.open = true;
              this.cancelEdit();
            },
            close() {
              this.open = false;
              this.cancelEdit();
            },
            startEdit(ev) {
              this.editError = '';
              this.editingId = ev.id;
              this.edit = {
                title: ev.title || '',
                notes: ev.notes || '',
                status: ev.status || 'busy',
                is_all_day: String(ev.is_all_day ? 1 : 0),
                date: ev.date || this.day,
                start_time: ev.start_time || '19:00',
                end_time: ev.end_time || '22:00',
              };
            },
            cancelEdit() {
              this.editingId = null;
              this.edit = {};
              this.editError = '';
            },
            async saveEdit(ev) {
              this.editError = '';
              try {
                const payload = {
                  action: 'update',
                  id: ev.id,
                  title: this.edit.title,
                  notes: this.edit.notes,
                  status: this.edit.status,
                  is_all_day: Number(this.edit.is_all_day) ? 1 : 0,
                  date: this.edit.date,
                  start_time: this.edit.start_time,
                  end_time: this.edit.end_time,
                };

                const res = await fetch(BASE_URL + '/api/calendar_event.php', {
                  method: 'POST',
                  headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                  },
                  body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (!json.success) throw new Error(json.error || 'Save failed');

                // simplest: reload so the month grid chips match the DB
                location.reload();
              } catch (e) {
                this.editError = e.message || String(e);
              }
            },
            async deleteEvent(ev) {
              if (!confirm('Delete this event?')) return;
              try {
                const res = await fetch(BASE_URL + '/api/calendar_event.php', {
                  method: 'POST',
                  headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                  },
                  body: JSON.stringify({ action: 'delete', id: ev.id })
                });
                const json = await res.json();
                if (!json.success) throw new Error(json.error || 'Delete failed');
                location.reload();
              } catch (e) {
                alert(e.message || String(e));
              }
            }
          }
        }
      </script>
    </section>

  </div>
</div>

<?php page_footer(); ?>
