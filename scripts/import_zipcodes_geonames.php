<?php
/**
 * Import the full US ZIP code table (lat/lng + city/state) from GeoNames.
 *
 * Source: https://download.geonames.org/export/zip/US.zip
 * License: Creative Commons Attribution 4.0 (credit GeoNames).
 *
 * Usage (CLI):
 *   php scripts/import_zipcodes_geonames.php
 *
 * Notes:
 *  - Safe to re-run; uses INSERT IGNORE (keeps first row per ZIP).
 *  - If you want to refresh everything, TRUNCATE zipcodes first.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  echo "This script must be run from the command line.\n";
  exit(1);
}

require_once __DIR__ . "/../core/bootstrap.php";

$url = 'https://download.geonames.org/export/zip/US.zip';
$workDir = sys_get_temp_dir() . '/rss_zip_import_' . bin2hex(random_bytes(4));
$zipPath = $workDir . '/US.zip';
$extractPath = $workDir . '/extract';

@mkdir($extractPath, 0777, true);

fwrite(STDOUT, "Downloading GeoNames US.zip...\n");
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_TIMEOUT => 120,
  CURLOPT_CONNECTTIMEOUT => 30,
  CURLOPT_USERAGENT => 'ReadySetShows/1.0 (zip import)'
]);
$data = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($data === false || $http < 200 || $http >= 300) {
  fwrite(STDERR, "Download failed (HTTP $http). $err\n");
  exit(1);
}

file_put_contents($zipPath, $data);

$za = new ZipArchive();
if ($za->open($zipPath) !== true) {
  fwrite(STDERR, "Could not open downloaded zip.\n");
  exit(1);
}
$za->extractTo($extractPath);
$za->close();

$txt = $extractPath . '/US.txt';
if (!file_exists($txt)) {
  // Some mirrors name it differently; fall back to first .txt
  $candidates = glob($extractPath . '/*.txt');
  if ($candidates) { $txt = $candidates[0]; }
}

if (!file_exists($txt)) {
  fwrite(STDERR, "Could not find US.txt after extraction.\n");
  exit(1);
}

$pdo = db();

// Ensure table exists
$pdo->exec(file_get_contents(__DIR__ . '/../sql/create_zipcodes.sql'));

// Import
$insert = $pdo->prepare(
  "INSERT IGNORE INTO zipcodes(zip, lat, lng, city, state) VALUES(?,?,?,?,?)"
);

$handle = fopen($txt, 'rb');
if (!$handle) {
  fwrite(STDERR, "Could not open extracted data file.\n");
  exit(1);
}

$pdo->beginTransaction();
$count = 0;
$skipped = 0;

while (($line = fgets($handle)) !== false) {
  $line = trim($line);
  if ($line === '') continue;

  // Format (tab-delimited):
  // country code, postal code, place name, admin name1, admin code1, admin name2, admin code2, admin name3, admin code3, latitude, longitude, accuracy
  $parts = explode("\t", $line);
  if (count($parts) < 12) { $skipped++; continue; }

  $zip = $parts[1] ?? '';
  if (!preg_match('/^\d{5}$/', $zip)) { $skipped++; continue; }

  $city = $parts[2] ?? null;
  $state = $parts[4] ?? null; // admin code1 (e.g., IL)
  $lat = (float)($parts[9] ?? 0);
  $lng = (float)($parts[10] ?? 0);

  // Defensive: ignore zero coordinates
  if ($lat === 0.0 && $lng === 0.0) { $skipped++; continue; }

  $insert->execute([$zip, $lat, $lng, $city, $state]);
  $count++;

  if ($count % 5000 === 0) {
    $pdo->commit();
    fwrite(STDOUT, "Imported $count ZIPs...\n");
    $pdo->beginTransaction();
  }
}

fclose($handle);
$pdo->commit();

fwrite(STDOUT, "Done. Imported ~{$count} rows. Skipped {$skipped} lines.\n");
fwrite(STDOUT, "Tip: GeoNames requires attribution (link/credit).\n");
