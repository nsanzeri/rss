<?php
// Secrets helper: env vars override DB stored values.
// DB storage exists mainly to simplify local/dev setups.
function app_secret(string $namespace, string $key, ?string $default = null): ?string {
    // 1) Environment override conventions
    if ($namespace === 'oauth' && $key === 'google_client_id') {
        $v = getenv("RSS_GOOGLE_CLIENT_ID");
        if ($v) return $v;
    }
    if ($namespace === 'oauth' && $key === 'google_client_secret') {
        $v = getenv("RSS_GOOGLE_CLIENT_SECRET");
        if ($v) return $v;
    }

    // 2) DB lookup
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT secret_value FROM app_secrets WHERE namespace=? AND secret_key=? LIMIT 1");
        $stmt->execute([$namespace, $key]);
        $row = $stmt->fetch();
        if ($row && isset($row['secret_value'])) {
            return $row['secret_value'];
        }
    } catch (Throwable $e) {
        // If DB isn't migrated yet, just fall back.
    }

    return $default;
}
