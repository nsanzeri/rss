<?php
function auth_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    static $cache = null;
    if ($cache !== null) return $cache;

    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, email, display_name, timezone, created_at FROM users WHERE id=? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $cache = $stmt->fetch() ?: null;
    return $cache;
}

function auth_login_password(string $email, string $password): array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, email, password_hash FROM users WHERE email=? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || empty($u['password_hash']) || !password_verify($password, $u['password_hash'])) {
        return [false, "Invalid email or password."];
    }
    $_SESSION['user_id'] = (int)$u['id'];
    session_regenerate_id(true);
    return [true, null];
}

function auth_register_password(string $email, string $password, ?string $display_name = null): array {
    $email = trim(strtolower($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, "Invalid email."];
    if (strlen($password) < 8) return [false, "Password must be at least 8 characters."];

    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) return [false, "An account with that email already exists."];

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $publicKey = bin2hex(random_bytes(12));
    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, timezone, public_key, created_at) VALUES (?,?,?,?,?,NOW())");
    $stmt->execute([$email, $hash, $display_name, 'America/Chicago', $publicKey]);
    $_SESSION['user_id'] = (int)$pdo->lastInsertId();
    session_regenerate_id(true);
    return [true, null];
}

function auth_logout(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
