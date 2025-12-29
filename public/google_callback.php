<?php
require_once __DIR__ . "/../core/bootstrap.php";

$code = $_GET['code'] ?? null;
if (!$code) {
    redirect("/login.php");
}

$clientId = app_secret('oauth', 'google_client_id') ?: (defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : null);
$clientSecret = app_secret('oauth', 'google_client_secret') ?: (defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : null);

if (!$clientId || !$clientSecret) {
    header("Content-Type: text/plain; charset=utf-8");
    echo "Google Sign-In is not configured. Missing client id/secret.";
    exit;
}

// Exchange code for token
$tokenResp = null;
$ch = curl_init("https://oauth2.googleapis.com/token");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded"],
    CURLOPT_POSTFIELDS => http_build_query([
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code',
    ]),
]);
$tokenBody = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$tokenBody || $http >= 400) {
    header("Content-Type: text/plain; charset=utf-8");
    echo "Token exchange failed (HTTP {$http}).\n\n{$tokenBody}";
    exit;
}

$tokenResp = json_decode($tokenBody, true) ?: [];
$idToken = $tokenResp['id_token'] ?? null;
$accessToken = $tokenResp['access_token'] ?? null;

if (!$idToken) {
    header("Content-Type: text/plain; charset=utf-8");
    echo "Missing id_token.";
    exit;
}

// Decode JWT payload (no signature verification here; sufficient for local dev)
// For production, verify signature or call Google tokeninfo.
$parts = explode('.', $idToken);
if (count($parts) < 2) {
    header("Content-Type: text/plain; charset=utf-8");
    echo "Invalid id_token.";
    exit;
}
$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?: [];

$sub = $payload['sub'] ?? null;
$email = strtolower(trim($payload['email'] ?? ''));
$name = $payload['name'] ?? null;

if (!$sub || !$email) {
    header("Content-Type: text/plain; charset=utf-8");
    echo "Google payload missing sub/email.";
    exit;
}

$pdo = db();
$pdo->beginTransaction();

// Find existing identity
$stmt = $pdo->prepare("SELECT user_id FROM user_oauth_identities WHERE provider='google' AND provider_user_id=? LIMIT 1");
$stmt->execute([$sub]);
$row = $stmt->fetch();

$userId = null;

if ($row) {
    $userId = (int)$row['user_id'];
} else {
    // Try match by email, otherwise create new user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if ($u) {
        $userId = (int)$u['id'];
    } else {
        $publicKey = bin2hex(random_bytes(12));
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, timezone, public_key, created_at) VALUES (?,?,?,?,?,NOW())");
        $stmt->execute([$email, null, $name, 'America/Chicago', $publicKey]);
        $userId = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("INSERT INTO user_oauth_identities (user_id, provider, provider_user_id, email_at_provider, created_at) VALUES (?,?,?,?,NOW())");
    $stmt->execute([$userId, 'google', $sub, $email]);
}

// Save tokens (optional, for future Google Calendar API)
$stmt = $pdo->prepare("INSERT INTO user_oauth_tokens (user_id, provider, access_token, refresh_token, token_type, scope, expires_at, created_at)
VALUES (?,?,?,?,?,?,?,NOW())
ON DUPLICATE KEY UPDATE access_token=VALUES(access_token), refresh_token=COALESCE(VALUES(refresh_token), refresh_token), token_type=VALUES(token_type), scope=VALUES(scope), expires_at=VALUES(expires_at), updated_at=CURRENT_TIMESTAMP");
$expiresAt = null;
if (!empty($tokenResp['expires_in'])) {
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . ((int)$tokenResp['expires_in']) . ' seconds')->format('Y-m-d H:i:s');
}
$stmt->execute([
    $userId,
    'google',
    $accessToken ?: '',
    $tokenResp['refresh_token'] ?? null,
    $tokenResp['token_type'] ?? null,
    $tokenResp['scope'] ?? null,
    $expiresAt
]);

$pdo->commit();

$_SESSION['user_id'] = $userId;
session_regenerate_id(true);
redirect("/dashboard.php");
