<?php
require_once __DIR__ . "/../core/bootstrap.php";

// Toggle shortlist entries for casting requests.
// POST: csrf, action=add|remove, profile_id, type=artist|venue, return(optional)

csrf_validate($_POST['csrf'] ?? '');

$type = (($_POST['type'] ?? 'artist') === 'venue') ? 'venue' : 'artist';
$action = (string)($_POST['action'] ?? '');
$pid = (int)($_POST['profile_id'] ?? 0);

if (!isset($_SESSION['booking_shortlist'])) {
  $_SESSION['booking_shortlist'] = ['artist'=>[], 'venue'=>[]];
}

if ($pid > 0) {
  $list =& $_SESSION['booking_shortlist'][$type];
  if ($action === 'add') {
    if (!in_array($pid, $list, true)) $list[] = $pid;
  } elseif ($action === 'remove') {
    $list = array_values(array_filter($list, fn($x) => (int)$x !== $pid));
  }
}

$return = (string)($_POST['return'] ?? '');
// Only allow same-site relative redirects.
$path = parse_url($return, PHP_URL_PATH);
$query = parse_url($return, PHP_URL_QUERY);
if ($path && is_string($path) && str_starts_with($path, '/')) {
  $dest = $path . ($query ? ('?' . $query) : '');
  header('Location: ' . $dest);
  exit;
}

header('Location: ' . BASE_URL . '/search.php');
exit;
