<?php
// Ready Set Shows — Ops (v1) configuration

// ===============================
// 1) ENV DETECTION
// ===============================
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// if you want a manual override, set RSS_APP_ENV in Apache env or .env loader
$appEnv = getenv('RSS_APP_ENV');
if ($appEnv) {
    $appEnv = strtolower(trim($appEnv));
} else {
    // auto-detect local by host/port
    $isLocalHost = (
        str_starts_with($host, 'localhost') ||
        str_starts_with($host, '127.0.0.1')
    );
    $appEnv = $isLocalHost ? 'local' : 'production';
}

define('APP_ENV', $appEnv); // 'local' | 'production'

// ===============================
// 2) DATABASE
// ===============================
define("DB_HOST", getenv("RSS_DB_HOST") ?: "localhost");
define("DB_NAME", getenv("RSS_DB_NAME") ?: "readysetshows");
define("DB_USER", getenv("RSS_DB_USER") ?: "root");
define("DB_PASS", getenv("RSS_DB_PASS") ?: "");

// ===============================
// 3) BASE_URL (stable per env)
// ===============================
// Preferred: set RSS_BASE_URL in env for BOTH local and production.
// Fallbacks below are sane defaults.
if (getenv('RSS_BASE_URL')) {
    $baseUrl = rtrim(getenv('RSS_BASE_URL'), '/');
} else {
    if (APP_ENV === 'local') {
        // <-- CHANGE THIS if your local path differs
        $baseUrl = "http://localhost:8080/rss/public";
    } else {
        // <-- CHANGE THIS to your real production public path
        // e.g. https://readysetshows.com/ops/public
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "https";
        $baseUrl = $scheme . "://" . $host;
        // If production lives in a subfolder, append it:
        // $baseUrl .= "/ops/public";
    }
}
define("BASE_URL", $baseUrl);

// ===============================
// 4) APP SETTINGS
// ===============================
define("APP_NAME", getenv("RSS_APP_NAME") ?: "Ready Set Shows");

// ===============================
// 5) GOOGLE SIGN-IN (optional)
// ===============================
// Prefer env vars (do NOT hardcode secrets in committed config.php)
define("RSS_GOOGLE_CLIENT_ID", getenv("RSS_GOOGLE_CLIENT_ID") ?: "");
define("RSS_GOOGLE_CLIENT_SECRET", getenv("RSS_GOOGLE_CLIENT_SECRET") ?: "");

// Always derived from BASE_URL to prevent mismatch
define("GOOGLE_REDIRECT_URI", BASE_URL . "/google_callback.php");
