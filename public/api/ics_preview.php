<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/ics.php';

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
$startDate = (string)($input['start_date'] ?? '');
$endDate   = (string)($input['end_date'] ?? '');

if ($calId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing calendar.']);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare("SELECT c.*, ci.source_url AS ci_source_url
    FROM user_calendars c
    LEFT JOIN calendar_imports ci ON ci.calendar_id=c.id
    WHERE c.id=? AND c.user_id=? LIMIT 1");
$stmt->execute([$calId, $u['id']]);
$cal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cal) {
    echo json_encode(['success' => false, 'error' => 'Calendar not found.']);
    exit;
}

$icsUrl = $cal['source_url'] ?? ($cal['ci_source_url'] ?? null);
if (($cal['source_type'] ?? '') !== 'ics' || empty($icsUrl)) {
    echo json_encode(['success' => false, 'error' => 'This calendar does not have an ICS URL.']);
    exit;
}

$tz = new DateTimeZone($u['timezone'] ?? 'UTC');

try {
    if ($mode === 'range') {
        if (!$startDate || !$endDate) throw new RuntimeException('Start Date and End Date are required.');
        if ($endDate < $startDate) throw new RuntimeException('End Date must be on or after Start Date.');

        $fromLocal = new DateTimeImmutable($startDate . ' 00:00:00', $tz);
        // inclusive end date => add 1 day
        $toLocal = (new DateTimeImmutable($endDate . ' 00:00:00', $tz))->modify('+1 day');

        // guardrail: avoid absurd windows
        if ($fromLocal->diff($toLocal)->days > 366 * 3) {
            throw new RuntimeException('Date range too large. Please choose a shorter range.');
        }
    } else {
        // "Entire calendar" = fast, practical default window
        $fromLocal = (new DateTimeImmutable('now', $tz))->modify('-1 month');
        $toLocal   = (new DateTimeImmutable('now', $tz))->modify('+18 months');
    }

    [$ok, $body, $http, $fetchErr] = ics_fetch($icsUrl);
    if (!$ok) {
        echo json_encode(['success' => false, 'error' => 'Could not fetch ICS: ' . ($fetchErr ?: ('HTTP ' . $http))]);
        exit;
    }

    $raw = ics_parse_events($body);

    $events = [];
    $maxOut = 500; // UI friendly cap
    $maxPerEvent = 2000; // per-event expansion cap

    foreach ($raw as $re) {
        $instances = ics_to_instances_between($re, $tz, $fromLocal, $toLocal, $maxPerEvent);
        foreach ($instances as $inst) {
            $sUtc = $inst['start_dt']->setTimezone(new DateTimeZone('UTC'));
            $eUtc = $inst['end_dt']->setTimezone(new DateTimeZone('UTC'));

            $sLocal = $sUtc->setTimezone($tz);
            $eLocal = $eUtc->setTimezone($tz);

            $when = (int)($inst['is_all_day'] ?? 0)
                ? $sLocal->format('Y-m-d') . ' (all day)'
                : $sLocal->format('Y-m-d H:i') . ' → ' . $eLocal->format('Y-m-d H:i');

            $events[] = [
                'uid' => $inst['uid'] ?? null,
                'summary' => $inst['summary'] ?? '(no title)',
                'description' => $inst['description'] ?? null,
                'location' => $inst['location'] ?? null,
                'is_all_day' => (int)($inst['is_all_day'] ?? 0),
                'start_utc' => $sUtc->format('Y-m-d H:i:s'),
                'end_utc' => $eUtc->format('Y-m-d H:i:s'),
                'when' => $when,
            ];

            if (count($events) >= $maxOut) {
                break 2;
            }
        }
    }

    // Metrics: preview helps us measure import usage before we build dashboards.
    if (function_exists('track_event')) {
        track_event('calendar_import_preview', [
            'calendar_id' => $calId,
            'mode' => $mode,
            'start_date' => $startDate ?: null,
            'end_date' => $endDate ?: null,
            'effective_start' => $fromLocal->format('Y-m-d'),
            'effective_end' => $toLocal->modify('-1 day')->format('Y-m-d'),
            'event_count' => count($events),
            'truncated' => (count($events) >= $maxOut) ? 1 : 0,
        ], $u['id'] ?? null);
    }

    echo json_encode([
        'success' => true,
        'effective_range' => [
            'start' => $fromLocal->format('Y-m-d'),
            'end' => $toLocal->modify('-1 day')->format('Y-m-d'), // show inclusive end for UI
            'from_local' => $fromLocal->format(DateTimeInterface::ATOM),
            'to_local' => $toLocal->format(DateTimeInterface::ATOM),
        ],
        'events' => $events,
        'truncated' => (count($events) >= $maxOut),
    ]);

} catch (Throwable $e) {
    if (function_exists('track_event')) {
        track_event('calendar_import_preview_failed', [
            'calendar_id' => $calId,
            'mode' => $mode,
            'error' => $e->getMessage(),
        ], $u['id'] ?? null);
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
