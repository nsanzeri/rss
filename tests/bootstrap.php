<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

// Adjust this path if your ics.php lives elsewhere.
$icsPath = __DIR__ . '/../core/ics.php';

if (!file_exists($icsPath)) {
  fwrite(STDERR, "ERROR: Cannot find core/ics.php at: {$icsPath}\n");
  fwrite(STDERR, "Copy your project's core/ics.php into this starter kit OR edit tests/bootstrap.php to point to it.\n");
  exit(1);
}

require_once $icsPath;

// Sanity: ensure required functions exist
$required = ['ics_parse_events', 'ics_expand'];
foreach ($required as $fn) {
  if (!function_exists($fn)) {
    fwrite(STDERR, "ERROR: Required function '{$fn}' not found after including {$icsPath}.\n");
    exit(1);
  }
}
