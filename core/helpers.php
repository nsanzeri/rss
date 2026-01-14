<?php
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void {
    header("Location: " . BASE_URL . $path);
    exit();
}

function require_login(): array {
    $u = auth_user();
    if (!$u) {
        redirect("/login.php");
    }
    return $u;
}

/**
 * Admin gate.
 *
 * We treat "admin/super user" as either:
 *  - users.is_admin = 1 (if you add that column later), OR
 *  - the user's email is listed in ADMIN_EMAILS in .env (comma-separated).
 *
 * Example in .env:
 *   ADMIN_EMAILS=nick@example.com,other@example.com
 */
function is_admin_user(?array $u): bool {
    if (!$u) return false;
    if (isset($u['is_admin']) && (int)$u['is_admin'] === 1) return true;

    // env() is loaded early in core/bootstrap.php
    $list = (string)env('ADMIN_EMAILS', '');
    if ($list === '') return false;

    $email = strtolower(trim((string)($u['email'] ?? '')));
    if ($email === '') return false;

    $admins = array_filter(array_map('trim', explode(',', strtolower($list))));
    return in_array($email, $admins, true);
}

function require_admin(): array {
    $u = require_login();
    if (!is_admin_user($u)) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
    return $u;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_validate(?string $token): bool {
    return !empty($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

// ---------------------------------------------------------------------
// Geo helpers (GeoNames ZIP dataset)
// ---------------------------------------------------------------------

/**
 * GeoNames attribution (required by GeoNames).
 * Display this anywhere you show location-derived info.
 */
function geonames_attribution_html(): string {
    // Keep it simple and non-invasive.
    return '<div class="geo-attrib">Geodata: <a href="https://www.geonames.org/" target="_blank" rel="noopener">GeoNames</a></div>';
}

/**
 * Look up a ZIP in the persistent cache first, then fall back to zipcodes.
 * Returns: ['zip'=>..., 'lat'=>..., 'lng'=>..., 'city'=>..., 'state'=>...] or null.
 */
function geo_zip_lookup(string $zip, PDO $pdo): ?array {
    $zip = preg_replace('/[^0-9]/', '', $zip);
    $zip = substr($zip, 0, 5);
    if (strlen($zip) !== 5) return null;

    // 1) Cache
    try {
        $c = $pdo->prepare('SELECT zip, lat, lng FROM zip_lookup_cache WHERE zip = ? LIMIT 1');
        $c->execute([$zip]);
        $row = $c->fetch();
        if ($row) {
            // Best-effort: enrich city/state from zipcodes (optional)
            $z = $pdo->prepare('SELECT city, state FROM zipcodes WHERE zip = ? LIMIT 1');
            $z->execute([$zip]);
            $meta = $z->fetch() ?: [];
            return [
                'zip' => $zip,
                'lat' => (float)$row['lat'],
                'lng' => (float)$row['lng'],
                'city' => $meta['city'] ?? null,
                'state' => $meta['state'] ?? null,
            ];
        }
    } catch (Throwable $e) {
        // If cache table doesn't exist yet, we'll fall back to zipcodes.
    }

    // 2) Source table
    $stmt = $pdo->prepare('SELECT zip, lat, lng, city, state FROM zipcodes WHERE zip = ? LIMIT 1');
    $stmt->execute([$zip]);
    $origin = $stmt->fetch();
    if (!$origin) return null;

    // 3) Write-through cache (best-effort)
    try {
        $w = $pdo->prepare('INSERT INTO zip_lookup_cache(zip, lat, lng, updated_at) VALUES(?,?,?,NOW())
                            ON DUPLICATE KEY UPDATE lat=VALUES(lat), lng=VALUES(lng), updated_at=NOW()');
        $w->execute([$zip, $origin['lat'], $origin['lng']]);
    } catch (Throwable $e) {
        // ignore
    }

    return [
        'zip' => $zip,
        'lat' => (float)$origin['lat'],
        'lng' => (float)$origin['lng'],
        'city' => $origin['city'] ?? null,
        'state' => $origin['state'] ?? null,
    ];
}

/**
 * Bounding box around a point for a radius in miles.
 * Returns: ['min_lat'=>..., 'max_lat'=>..., 'min_lng'=>..., 'max_lng'=>...]
 */
function geo_bounding_box(float $lat, float $lng, int $radius_miles): array {
    $radius_miles = max(1, $radius_miles);
    $lat_delta = $radius_miles / 69.0;
    $cos = cos(deg2rad($lat));
    if (abs($cos) < 0.000001) $cos = 0.000001; // avoid division by zero near poles
    $lng_delta = $radius_miles / (69.0 * $cos);

    return [
        'min_lat' => $lat - $lat_delta,
        'max_lat' => $lat + $lat_delta,
        'min_lng' => $lng - $lng_delta,
        'max_lng' => $lng + $lng_delta,
    ];
}

// ---------------------------------------------------------------------
// Mode + Profile helpers
// ---------------------------------------------------------------------

function user_has_profiles(int $userId, ?PDO $pdo = null): bool {
  $pdo = $pdo ?: (function_exists('db') ? db() : null);
  if (!$pdo) return false;

  // Prefer join-table if it exists
  try {
    $st = $pdo->prepare("SELECT 1 FROM user_profiles WHERE user_id = ? LIMIT 1");
    $st->execute([$userId]);
    if ($st->fetchColumn()) return true;
  } catch (Throwable $e) {
    // ignore if table doesn't exist
  }

  // Fallback: profiles.user_id
  $st = $pdo->prepare("SELECT 1 FROM profiles WHERE user_id = ? AND deleted_at IS NULL LIMIT 1");
  $st->execute([$userId]);
  return (bool)$st->fetchColumn();
}


/**
 * app_mode:
 * - 'artist' => user has profiles
 * - 'host'   => user has zero profiles
 *
 * Cached in session for speed; call app_mode_refresh() after profile create/delete.
 */
function app_mode(?array $u = null, ?PDO $pdo = null): string {
	if (!$u) $u = auth_user();
	if (!$u) return 'guest';
	
	if (!empty($_SESSION['app_mode'])) return (string)$_SESSION['app_mode'];
	
	$mode = user_has_profiles((int)$u['id'], $pdo) ? 'artist' : 'host';
	$_SESSION['app_mode'] = $mode;
	return $mode;
}

function app_mode_refresh(?array $u = null, ?PDO $pdo = null): string {
	unset($_SESSION['app_mode']);
	return app_mode($u, $pdo);
}

function redirect_after_login(?array $u = null, ?PDO $pdo = null): void {
  $u = $u ?: auth_user();
  if (!$u) redirect("/login.php");

  $pdo = $pdo ?: (function_exists('db') ? db() : null);
  $mode = app_mode($u, $pdo);

  // If your app stores an "intended URL", only use it if it matches mode.
  $returnTo = $_SESSION['return_to'] ?? $_SESSION['redirect_to'] ?? null;
  unset($_SESSION['return_to'], $_SESSION['redirect_to']);

  if ($returnTo) {
    // Host users should NOT be sent to artist-only pages.
    $artistOnly = preg_match('#/(dashboard|inbox|bookings|profiles)\.php#', $returnTo);
    if (!($mode === 'host' && $artistOnly)) {
      redirect($returnTo);
    }
  }

  redirect($mode === 'artist' ? "/dashboard.php" : "/discover.php");
}
