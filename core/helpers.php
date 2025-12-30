<?php
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
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
