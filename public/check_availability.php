<?php
require_once __DIR__ . "/_layout.php";

$u   = require_login();
$pdo = db();

$err = null;
$outLines = [];
$freeExportDates = []; // Y-m-d strings for export

// timezone
$tzName = $u['timezone'] ?? 'UTC';
try { $userTz = new DateTimeZone($tzName); }
catch (Throwable $e) { $tzName = 'UTC'; $userTz = new DateTimeZone('UTC'); }

// CSRF
$didSubmit = ($_SERVER["REQUEST_METHOD"] === "POST");
if ($didSubmit) {
  $posted = $_POST["csrf"] ?? null;
  if (!csrf_validate($posted)) {
    $err = "Session expired. Please refresh and try again.";
  }
}
$csrf = csrf_token();

// calendars for UI + validation
$stmt = $pdo->prepare("
  SELECT id, calendar_name, calendar_color, description, source_type, source_url, is_default
  FROM user_calendars
  WHERE user_id=?
  ORDER BY is_default DESC, calendar_name ASC
");
$stmt->execute([$u['id']]);
$cals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allowed = [];
foreach ($cals as $c) $allowed[(string)$c['id']] = $c;

// -------------------------
// Helpers (JS-equivalent)
// -------------------------
function month_header(DateTimeImmutable $d, DateTimeZone $tz): string {
  return $d->setTimezone($tz)->format("F") . " — " . $d->setTimezone($tz)->format("Y");
}
function pretty_day(DateTimeImmutable $d, DateTimeZone $tz): string {
  return $d->setTimezone($tz)->format("D M j");
}

/**
 *an all-day event that ends at Sat 00:00 will be treated as ending at Fri 23:59:59, so Saturday won’t be marked busy anymore — and your availability output should include:
 */
function mark_event_days_busy(array &$busy, DateTimeImmutable $startLocal, DateTimeImmutable $endLocal): void {

  // ICS convention: DTEND is often EXCLUSIVE, especially for all-day events.
  // So if an event ends exactly at 00:00:00, do not mark that ending day as busy.
  if ($endLocal > $startLocal && $endLocal->format('H:i:s') === '00:00:00') {
    $endLocal = $endLocal->modify('-1 second');
  }

  $cursor = $startLocal->setTime(0,0,0);
  $last   = $endLocal->setTime(23,59,59);

  while ($cursor <= $last) {
    $busy[$cursor->format("Y-m-d")] = true;
    $cursor = $cursor->modify("+1 day");
  }
}


/**
 * Fetch + parse + expand ICS instances, but only keep instances overlapping the range.
 * Uses your existing core/ics.php functions.
 */
function busy_dates_from_ics(string $url, DateTimeImmutable $rangeStartLocal, DateTimeImmutable $rangeEndLocal, DateTimeZone $userTz): array {
  require_once __DIR__ . "/../core/ics.php";
  
  [$ok, $body, $http, $fetchErr] = ics_fetch($url);
  if (!$ok) {
    throw new RuntimeException($fetchErr ?: ("HTTP " . $http));
  }

  $raw = ics_parse_events($body);
  $busy = [];
  $utc = new DateTimeZone("UTC");

  foreach ($raw as $re) {
    try {
      // this function returns instances with start_dt/end_dt DateTime objects
      $instances = ics_to_instances_between($re, $userTz, $rangeStartLocal, $rangeEndLocal, 2000);

      foreach ($instances as $inst) {
        if (empty($inst['start_dt']) || empty($inst['end_dt'])) continue;

        /** @var DateTimeInterface $s */
        $s = $inst['start_dt'];
        /** @var DateTimeInterface $e */
        $e = $inst['end_dt'];

        // Convert to user TZ, then normalize to day boundaries (JS logic)
		$sLocal = DateTimeImmutable::createFromInterface($s)->setTimezone($userTz);
		$eLocal = DateTimeImmutable::createFromInterface($e)->setTimezone($userTz);

        // overlap test with requested range (local)
        if ($eLocal < $rangeStartLocal) continue;
        if ($sLocal > $rangeEndLocal) continue;

        mark_event_days_busy($busy, $sLocal, $eLocal);
      }
    } catch (Throwable $e) {
      // skip malformed
      continue;
    }
  }
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
  header('Content-Type: text/plain');
  echo "BUSY DATES:\n";
  ksort($busy);
  foreach ($busy as $d => $_) {
    echo $d . "\n";
  }
  exit;
}

  return $busy;
}

/**
 * Optional fallback for non-ICS calendars: pull busy events from DB
 * (if you want "fetch only", delete this function and disallow non-ICS).
 */
function busy_dates_from_db(PDO $pdo, int $calendarId, DateTimeImmutable $rangeStartLocal, DateTimeImmutable $rangeEndLocal, DateTimeZone $userTz): array {
  $busy = [];
  $utc = new DateTimeZone("UTC");

  $startUtc = $rangeStartLocal->setTimezone($utc)->format("Y-m-d H:i:s");
  $endUtc   = $rangeEndLocal->setTimezone($utc)->format("Y-m-d H:i:s");

  $stmt = $pdo->prepare("
    SELECT start_utc, end_utc
    FROM calendar_events
    WHERE calendar_id=?
      AND end_utc >= ?
      AND start_utc <= ?
      AND (status IS NULL OR status <> 'available')
  ");
  $stmt->execute([$calendarId, $startUtc, $endUtc]);

  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    try {
      $sLocal = (new DateTimeImmutable($r['start_utc'], $utc))->setTimezone($userTz);
      $eLocal = (new DateTimeImmutable($r['end_utc'],   $utc))->setTimezone($userTz);
      mark_event_days_busy($busy, $sLocal, $eLocal);
    } catch (Throwable $e) {
      continue;
    }
  }

  return $busy;
}

// -------------------------
// Defaults + inputs
// -------------------------
$today = (new DateTimeImmutable("now", $userTz))->format("Y-m-d");
$defaultEnd = (new DateTimeImmutable("now", $userTz))->modify("+180 day")->format("Y-m-d");

$startStr = $_POST['startDate'] ?? $today;
$endStr   = $_POST['endDate'] ?? $defaultEnd;
$days     = $_POST['days'] ?? [];        // 0..6
$selCals  = $_POST['calendars'] ?? [];   // ids

if ($didSubmit && !$err) {

  // validation
  if (!$startStr || !$endStr) $err = "Please select both dates.";
  if (!$err && (!is_array($days) || count($days) === 0)) $err = "Select at least one weekday.";
  if (!$err && (!is_array($selCals) || count($selCals) === 0)) $err = "Select at least one calendar.";

  // normalize days
  $daySet = [];
  if (!$err) {
    foreach ($days as $d) {
      $di = (int)$d;
      if ($di >= 0 && $di <= 6) $daySet[$di] = true;
    }
    if (!$daySet) $err = "Select at least one weekday.";
  }

  // normalize calendars (ownership)
  $selected = [];
  if (!$err) {
    foreach ($selCals as $id) {
      $id = (string)$id;
      if (isset($allowed[$id])) $selected[] = $allowed[$id];
    }
    if (!$selected) $err = "Selected calendars are not valid.";
  }

  // parse range (local)
  if (!$err) {
    try {
      $rangeStartLocal = new DateTimeImmutable($startStr . " 00:00:00", $userTz);
      $rangeEndLocal   = new DateTimeImmutable($endStr   . " 23:59:59", $userTz);
      if ($rangeEndLocal < $rangeStartLocal) $err = "End Date must be after Start Date.";
    } catch (Throwable $e) {
      $err = "Invalid date range.";
    }
  }

  // compute
  if (!$err) {
    try {
      $busyDates = [];

      foreach ($selected as $cal) {
        $type = $cal['source_type'] ?? 'manual';

        if ($type === 'ics') {
          $url = trim((string)($cal['source_url'] ?? ''));
          if ($url === '') {
            throw new RuntimeException("Calendar '{$cal['calendar_name']}' has no ICS URL.");
          }
          $b = busy_dates_from_ics($url, $rangeStartLocal, $rangeEndLocal, $userTz);
          $busyDates += $b; // union
        } else {
          // fallback for manual/google
          $b = busy_dates_from_db($pdo, (int)$cal['id'], $rangeStartLocal, $rangeEndLocal, $userTz);
          $busyDates += $b;
        }
      }

      $free = [];
      $cursor = $rangeStartLocal;
      while ($cursor <= $rangeEndLocal) {
        $dow = (int)$cursor->format("w"); // 0..6 like JS
        $key = $cursor->format("Y-m-d");
        if (isset($daySet[$dow]) && empty($busyDates[$key])) {
          $free[] = $cursor;
          $freeExportDates[] = $key;
        }
        $cursor = $cursor->modify("+1 day");
      }

      if (!$free) {
        $outLines[] = "No available dates found.";
      } else {
        $current = "";
        foreach ($free as $d) {
          $h = month_header($d, $userTz);
          if ($h !== $current) {
            $current = $h;
            $outLines[] = "";
            $outLines[] = $h;
            $outLines[] = "-------------";
          }
          $outLines[] = "• " . pretty_day($d, $userTz);
        }
      }
    } catch (Throwable $e) {
      $err = "Error: " . $e->getMessage();
    }
  }
}

page_header("Check Availability");
?>

<?php if (empty($cals)): ?>
  <div class="card"><div class="card-body">
    <div class="alert">
      You don’t have any calendars yet.
      <a href="<?= h(BASE_URL) ?>/manage_calendars.php">Go to Manage Calendars</a>.
    </div>
  </div></div>
  <?php page_footer(); exit; ?>
<?php endif; ?>

<?php if ($err): ?>
  <div class="alert"><?= h($err) ?></div>
<?php endif; ?>

<?php
  $resultText = trim(implode("\n", $outLines));
  // Export payloads
  $exportTxt = $resultText;
  $exportCsv = "date\n";
  if (!empty($freeExportDates)) {
    foreach ($freeExportDates as $d) {
      $exportCsv .= $d . "\n";
    }
  }
?>

<div class="avail-layout">
  <section class="avail-panel">
    <h2 class="avail-title">Check Availability</h2>

    <form method="post" class="avail-form">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"/>

      <div class="avail-grid">
        <div class="field">
          <label class="field-label">Start Date</label>
          <input class="field-input" type="date" name="startDate" value="<?= h($startStr) ?>" required>
        </div>
        <div class="field">
          <label class="field-label">End Date</label>
          <input class="field-input" type="date" name="endDate" value="<?= h($endStr) ?>" required>
        </div>
      </div>

      <div class="avail-section">
        <div class="section-label">Select Days of the Week:</div>
        <?php
          $dayNames = ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];
          $postedDays = is_array($days) ? array_map('intval', $days) : [];
          $postedDaySet = array_flip($postedDays);
        ?>
        <div class="pill-row">
          <?php foreach ($dayNames as $i => $nm): ?>
            <label class="pill">
              <input class="pill-input" type="checkbox" name="days[]" value="<?= (int)$i ?>" <?= isset($postedDaySet[$i]) ? "checked" : "" ?>>
              <span class="pill-box" aria-hidden="true"></span>
              <span class="pill-text"><?= h($nm) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="avail-section">
        <div class="section-label">Select Calendars:</div>
        <?php
          $postedCals = is_array($selCals) ? array_map('strval', $selCals) : [];
          $postedCalSet = array_flip($postedCals);
        ?>
        <div class="cal-list">
          <?php foreach ($cals as $c): ?>
            <label class="cal-item">
              <input class="cal-check" type="checkbox" name="calendars[]" value="<?= (int)$c['id'] ?>" <?= isset($postedCalSet[(string)$c['id']]) ? "checked" : "" ?>>
              <span class="cal-swatch" style="background: <?= h($c['calendar_color'] ?: '#5c7cff') ?>"></span>
              <span class="cal-name"><?= h($c['calendar_name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="avail-actions">
        <button class="btn btn-primary" type="submit">Find Available Dates</button>
        <div class="muted">Timezone: <?= h($tzName) ?></div>
      </div>
    </form>
  </section>

  <section class="avail-results">
    <div class="results-box">
      <textarea id="availText" class="results-text" readonly><?= h($resultText) ?></textarea>

      <div class="results-actions">
        <button type="button" class="btn btn-ghost btn-sm" id="btnCopy">Copy</button>
        <button type="button" class="btn btn-ghost btn-sm" id="btnCsv">Export CSV</button>
        <button type="button" class="btn btn-ghost btn-sm" id="btnTxt">Export TXT</button>
      </div>
    </div>
  </section>
</div>

<script>
(function(){
  const txt = <?= json_encode($exportTxt) ?>;
  const csv = <?= json_encode($exportCsv) ?>;

  function download(content, filename, mime){
    const blob = new Blob([content], {type: mime});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  const btnCopy = document.getElementById('btnCopy');
  const btnCsv  = document.getElementById('btnCsv');
  const btnTxt  = document.getElementById('btnTxt');
  const area    = document.getElementById('availText');

  if (btnCopy) btnCopy.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(txt || (area ? area.value : ''));
    } catch (e) {
      // fallback
      if (area) {
        area.focus();
        area.select();
        document.execCommand('copy');
      }
    }
  });
  if (btnCsv) btnCsv.addEventListener('click', () => download(csv, 'available_dates.csv', 'text/csv;charset=utf-8'));
  if (btnTxt) btnTxt.addEventListener('click', () => download(txt, 'available_dates.txt', 'text/plain;charset=utf-8'));
})();
</script>

<?php page_footer(); ?>
