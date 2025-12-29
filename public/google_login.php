<?php
require_once __DIR__ . "/../core/bootstrap.php";

$clientId = app_secret('oauth', 'google_client_id');
if (!$clientId || $clientId === 'YOUR_CLIENT_ID.apps.googleusercontent.com') {
    // fallback to old config constants if user prefers
    $clientId = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : null;
}

if (!$clientId) {
    header("Content-Type: text/plain; charset=utf-8");
    echo "Google Sign-In is not configured yet.\n\nSet RSS_GOOGLE_CLIENT_ID / RSS_GOOGLE_CLIENT_SECRET env vars\nOR insert into app_secrets table (namespace='oauth').";
    exit;
}

$params = [
    'client_id' => $clientId,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'access_type' => 'offline',
    'prompt' => 'consent',
];
header("Location: https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($params));
exit;
