<?php
require_once __DIR__ . "/../core/bootstrap.php";
$u = require_login();
$pdo = db();

$tz = new DateTimeZone($u['timezone'] ?? 'UTC');

$start = $_GET['start'] ?? date('Y-m-d');
$end   = $_GET['end'] ?? (new DateTimeImmutable('now', $tz))->modify('+30 day')->format('Y-m-d');
$selCals = $_GET['cal'] ?? [];
if (!is_array($selCals)) $selCals = [$selCals];
$days = $_GET['dow'] ?? ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
if (!is_array($days)) $days = [$days];

$format = strtolower($_GET['format'] ?? 'txt');
if (!in_array($format, ['txt','csv'], true)) $format = 'txt';

$stmt = $pdo->prepare("SELECT id, calendar_name FROM user_calendars WHERE user_id=? ORDER BY calendar_name ASC");
$stmt->execute([$u['id']]);
$cals = $stmt->fetchAll();

$calIds = array_values(array_filter(array_map('intval', $selCals)));
if (!$calIds) $calIds = array_map(fn($c)=> (int)$c['id'], $cals);

$startDt = new DateTimeImmutable($start . " 00:00:00", $tz);
$endDt   = new DateTimeImmutable($end . " 00:00:00", $tz);
if ($endDt < $startDt) { $tmp=$startDt; $startDt=$endDt; $endDt=$tmp; }

$startUtc = $startDt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$endUtcExcl = $endDt->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$in = implode(',', array_fill(0, count($calIds), '?'));
$stmt = $pdo->prepare("SELECT start_utc, end_utc FROM calendar_events
    WHERE calendar_id IN ($in) AND status='busy' AND start_utc < ? AND end_utc > ?");
$stmt->execute(array_merge($calIds, [$endUtcExcl, $startUtc]));
$busy = $stmt->fetchAll();

$busyDates = [];
foreach ($busy as $b) {
    $s = new DateTimeImmutable($b['start_utc'], new DateTimeZone('UTC'));
    $e = new DateTimeImmutable($b['end_utc'], new DateTimeZone('UTC'));
    $sL = $s->setTimezone($tz);
    $eL = $e->setTimezone($tz);

    $cur = $sL->setTime(0,0,0);
    $endMark = $eL->setTime(0,0,0);
    while ($cur <= $endMark) {
        $busyDates[$cur->format('Y-m-d')] = true;
        $cur = $cur->modify('+1 day');
    }
}

$avail = [];
$cur = $startDt;
while ($cur <= $endDt) {
    $dow = $cur->format('D');
    if (in_array($dow, $days, true)) {
        $key = $cur->format('Y-m-d');
        if (empty($busyDates[$key])) $avail[] = $cur;
    }
    $cur = $cur->modify('+1 day');
}

$filename = "availability_" . $startDt->format('Ymd') . "_" . $endDt->format('Ymd') . "." . $format;

if ($format === 'csv') {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");

    $out = fopen("php://output", "w");
    fputcsv($out, ["date", "day", "display"]);
    foreach ($avail as $d) {
        fputcsv($out, [$d->format('Y-m-d'), $d->format('D'), $d->format('D M j, Y')]);
    }
    fclose($out);
    exit;
}

header("Content-Type: text/plain; charset=utf-8");
header("Content-Disposition: attachment; filename=\"{$filename}\"");

foreach ($avail as $d) {
    echo $d->format('D M j, Y') . "\n";
}
