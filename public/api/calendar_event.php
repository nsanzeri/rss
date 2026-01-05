<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/bootstrap.php';

$u = auth_user();
if (!$u) {
  echo json_encode(['success' => false, 'error' => 'Not logged in']);
  exit;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!csrf_validate($csrf)) {
  echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['success' => false, 'error' => 'Invalid request']);
  exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = $_POST;

$action = $payload['action'] ?? '';
$eventId = (int)($payload['id'] ?? 0);
if ($eventId <= 0) {
  echo json_encode(['success' => false, 'error' => 'Missing event id']);
  exit;
}

$pdo = db();
$tz = new DateTimeZone($u['timezone'] ?? 'UTC');

// Ensure the event belongs to the logged-in user
$stmt = $pdo->prepare(
  "SELECT e.id, e.calendar_id, e.title, e.notes, e.status, e.is_all_day, e.start_utc, e.end_utc, e.source
"
. "FROM calendar_events e
"
. "JOIN user_calendars c ON c.id = e.calendar_id
"
. "WHERE e.id = ? AND c.user_id = ?
"
. "LIMIT 1"
);
$stmt->execute([$eventId, $u['id']]);
$ev = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ev) {
  echo json_encode(['success' => false, 'error' => 'Event not found']);
  exit;
}

try {
  if ($action === 'delete') {
    $del = $pdo->prepare(
      "DELETE e FROM calendar_events e
"
    . "JOIN user_calendars c ON c.id = e.calendar_id
"
    . "WHERE e.id = ? AND c.user_id = ?"
    );
    $del->execute([$eventId, $u['id']]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action !== 'update') {
    echo json_encode(['success' => false, 'error' => 'Unsupported action']);
    exit;
  }

  $title = trim((string)($payload['title'] ?? ''));
  $notes = trim((string)($payload['notes'] ?? ''));
  $status = (string)($payload['status'] ?? $ev['status']);
  if (!in_array($status, ['busy','available','tentative'], true)) $status = 'busy';

  $date = trim((string)($payload['date'] ?? ''));
  $isAllDay = (int)($payload['is_all_day'] ?? $ev['is_all_day']) === 1;
  $startTime = trim((string)($payload['start_time'] ?? ''));
  $endTime = trim((string)($payload['end_time'] ?? ''));

  if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date']);
    exit;
  }

  if ($isAllDay) {
    $startLocal = new DateTimeImmutable($date . ' 00:00:00', $tz);
    $endLocal = $startLocal->modify('+1 day');
  } else {
    if (!preg_match('/^\d{2}:\d{2}$/', $startTime)) {
      echo json_encode(['success' => false, 'error' => 'Invalid start time']);
      exit;
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $endTime)) {
      echo json_encode(['success' => false, 'error' => 'Invalid end time']);
      exit;
    }

    $startLocal = new DateTimeImmutable($date . ' ' . $startTime . ':00', $tz);
    $endLocal = new DateTimeImmutable($date . ' ' . $endTime . ':00', $tz);

    // If end <= start, assume it crosses midnight
    if ($endLocal <= $startLocal) {
      $endLocal = $endLocal->modify('+1 day');
    }
  }

  $startUtc = $startLocal->setTimezone(new DateTimeZone('UTC'));
  $endUtc = $endLocal->setTimezone(new DateTimeZone('UTC'));

  $upd = $pdo->prepare(
    "UPDATE calendar_events
"
  . "SET title = ?, notes = ?, status = ?, is_all_day = ?, start_utc = ?, end_utc = ?, updated_at = NOW()
"
  . "WHERE id = ?"
  );
  $upd->execute([
    ($title !== '' ? $title : null),
    ($notes !== '' ? $notes : null),
    $status,
    $isAllDay ? 1 : 0,
    $startUtc->format('Y-m-d H:i:s'),
    $endUtc->format('Y-m-d H:i:s'),
    $eventId,
  ]);

  echo json_encode(['success' => true]);
  exit;

} catch (Throwable $e) {
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
  exit;
}
