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
    echo json_encode(['success' => false, 'error' => 'Session expired. Please refresh and try again.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$calId = (int)($input['calendar_id'] ?? 0);
$mode  = (string)($input['mode'] ?? 'all');
$deleteInRange = !empty($input['delete_in_range']);
$events = $input['events'] ?? [];
$effective = $input['effective_range'] ?? null;

if ($calId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing calendar.']);
    exit;
}
if (!is_array($events) || count($events) === 0) {
    echo json_encode(['success' => false, 'error' => 'No events to import.']);
    exit;
}
if (count($events) > 2000) {
    echo json_encode(['success' => false, 'error' => 'Too many events selected. Please import a smaller set.']);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare("SELECT id, source_type FROM user_calendars WHERE id=? AND user_id=? LIMIT 1");
$stmt->execute([$calId, $u['id']]);
$cal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cal) {
    echo json_encode(['success' => false, 'error' => 'Calendar not found.']);
    exit;
}
if (($cal['source_type'] ?? '') !== 'ics') {
    echo json_encode(['success' => false, 'error' => 'This calendar is not an ICS calendar.']);
    exit;
}

// Metrics: record that an import was initiated.
if (function_exists('track_event')) {
    track_event('calendar_import_started', [
        'calendar_id' => $calId,
        'mode' => $mode,
        'delete_in_range' => $deleteInRange ? 1 : 0,
        'selected_count' => is_array($events) ? count($events) : 0,
    ], $u['id'] ?? null);
}

// Determine the delete window in UTC if requested.
$fromUtc = null;
$toUtc   = null;
try {
    if ($deleteInRange) {
        if (!is_array($effective) || empty($effective['from_local']) || empty($effective['to_local'])) {
            echo json_encode(['success' => false, 'error' => 'Missing effective range for delete/replace. Please preview again.']);
            exit;
        }
        $fromLocal = new DateTimeImmutable($effective['from_local']);
        $toLocal   = new DateTimeImmutable($effective['to_local']);
        $fromUtc = $fromLocal->setTimezone(new DateTimeZone('UTC'));
        $toUtc   = $toLocal->setTimezone(new DateTimeZone('UTC'));
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Invalid range. Please preview again.']);
    exit;
}

try {
    $pdo->beginTransaction();

    if ($deleteInRange && $fromUtc && $toUtc) {
        // Delete any previously imported ICS events that overlap the selected window
        $del = $pdo->prepare("DELETE FROM calendar_events
            WHERE calendar_id=? AND source='ics'
              AND start_utc < ? AND end_utc > ?");
        $del->execute([
            $calId,
            $toUtc->format('Y-m-d H:i:s'),
            $fromUtc->format('Y-m-d H:i:s'),
        ]);
    }

    // Insert selected instances. We prevent duplicate copies of the same instance
    // by deleting an exact-match instance before insert (uid+start+end).
    $dedupe = $pdo->prepare("DELETE FROM calendar_events
        WHERE calendar_id=? AND source='ics'
          AND ((external_uid IS NULL AND ? IS NULL) OR external_uid=?)
          AND start_utc=? AND end_utc=?");

    $ins = $pdo->prepare("INSERT INTO calendar_events
        (calendar_id, external_uid, title, notes, status, is_all_day, start_utc, end_utc, source, created_at)
        VALUES (?,?,?,?,?,?,?,?, 'ics', NOW())");

    $count = 0;
    foreach ($events as $ev) {
        $uid = $ev['uid'] ?? null;
        $startUtcStr = (string)($ev['start_utc'] ?? '');
        $endUtcStr   = (string)($ev['end_utc'] ?? '');
        if (!$startUtcStr || !$endUtcStr) continue;

        // validate date formats lightly
        $s = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startUtcStr, new DateTimeZone('UTC'));
        $e = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endUtcStr, new DateTimeZone('UTC'));
        if (!$s || !$e || $e <= $s) continue;

        $summary = trim((string)($ev['summary'] ?? ''));
        $desc = $ev['description'] ?? null;
        $loc  = $ev['location'] ?? null;

        // Combine description/location into notes, keeping it simple.
        $notes = null;
        $bits = [];
        if ($loc) $bits[] = (string)$loc;
        if ($desc) $bits[] = (string)$desc;
        if ($bits) $notes = implode("\n\n", $bits);

        // instance-level dedupe
        $dedupe->execute([$calId, $uid, $uid, $startUtcStr, $endUtcStr]);

        $ins->execute([
            $calId,
            $uid,
            ($summary !== '' ? $summary : '(no title)'),
            $notes,
            'busy',
            !empty($ev['is_all_day']) ? 1 : 0,
            $startUtcStr,
            $endUtcStr,
        ]);

        $count++;
        if ($count > 5000) break;
    }

    // Update calendar_imports bookkeeping for UI
    $stmt = $pdo->prepare("INSERT INTO calendar_imports (calendar_id, last_synced_at, last_http_status, last_error)
        VALUES (?, ?, ?, NULL)
        ON DUPLICATE KEY UPDATE last_synced_at=VALUES(last_synced_at), last_http_status=VALUES(last_http_status), last_error=NULL");
    $stmt->execute([$calId, date('Y-m-d H:i:s'), 200]);

    $pdo->commit();

    if (function_exists('track_event')) {
        track_event('calendar_import_completed', [
            'calendar_id' => $calId,
            'mode' => $mode,
            'delete_in_range' => $deleteInRange ? 1 : 0,
            'imported_count' => $count,
        ], $u['id'] ?? null);
    }

    $msg = $deleteInRange
        ? "Imported {$count} event(s) and replaced existing imports in the selected range."
        : "Imported {$count} event(s).";

    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (function_exists('track_event')) {
        track_event('calendar_import_failed', [
            'calendar_id' => $calId,
            'mode' => $mode,
            'delete_in_range' => $deleteInRange ? 1 : 0,
            'error' => $e->getMessage(),
        ], $u['id'] ?? null);
    }
    echo json_encode(['success' => false, 'error' => 'Import failed: ' . $e->getMessage()]);
}
