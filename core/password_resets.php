<?php

// Password reset helpers.
// Stores only a SHA-256 hash of the token.

require_once __DIR__ . '/mailer.php';

/**
 * Create a password reset token for a user (by id) and email it.
 * Returns [ok, error|null].
 */
function password_reset_create_and_email(int $userId, string $toEmail, string $resetUrlBase, int $ttlMinutes = 60): array {
    $pdo = db();

    // random token (not stored in DB)
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $ttlMinutes . ' minutes')->format('Y-m-d H:i:s');

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    if ($ua !== null) $ua = substr($ua, 0, 255);

    $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, request_ip, user_agent) VALUES (?,?,?,?,?)");
    $stmt->execute([$userId, $tokenHash, $expiresAt, $ip, $ua]);

    $resetLink = rtrim($resetUrlBase, '/') . '/reset_password.php?token=' . urlencode($token);

    $appName = defined('APP_NAME') ? APP_NAME : 'Ready Set Shows';

    $subject = "Reset your {$appName} password";

    $html = ""
        . "<p>We received a request to reset your password for <strong>" . h($appName) . "</strong>.</p>"
        . "<p><a href=\"" . h($resetLink) . "\" style=\"display:inline-block;padding:10px 14px;border-radius:10px;background:#111827;color:#fff;text-decoration:none;\">Reset password</a></p>"
        . "<p>This link expires in {$ttlMinutes} minutes. If you didn’t request this, you can ignore this email.</p>";

    $text = "Reset your password: {$resetLink}\n\nThis link expires in {$ttlMinutes} minutes.";

    $res = Mailer::send([
        'to_email' => $toEmail,
        'subject'  => $subject,
        'html'     => $html,
        'text'     => $text,
    ]);

    if (!$res['ok']) {
        return [false, $res['error'] ?: 'Failed to send email'];
    }

    return [true, null];
}

/**
 * Validate a reset token and return the row (plus user_id) if valid.
 */
function password_reset_find_valid(string $token): ?array {
    $token = trim($token);
    if ($token === '' || strlen($token) < 20) return null;

    $hash = hash('sha256', $token);
    $pdo = db();

    $stmt = $pdo->prepare(
        "SELECT id, user_id, token_hash, expires_at, used_at FROM password_resets\n"
        . "WHERE token_hash=? AND used_at IS NULL AND expires_at > NOW()\n"
        . "ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Consume a reset token and update the user's password.
 * Returns [ok, error|null].
 */
function password_reset_consume(string $token, string $newPassword): array {
    if (strlen($newPassword) < 8) return [false, 'Password must be at least 8 characters.'];

    $row = password_reset_find_valid($token);
    if (!$row) return [false, 'Invalid or expired reset link.'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? LIMIT 1");
        $stmt->execute([$hash, (int)$row['user_id']]);

        // Mark this token used
        $stmt = $pdo->prepare("UPDATE password_resets SET used_at=NOW() WHERE id=? LIMIT 1");
        $stmt->execute([(int)$row['id']]);

        // Optional hardening: invalidate any other outstanding tokens for the same user
        $stmt = $pdo->prepare("UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL");
        $stmt->execute([(int)$row['user_id']]);

        $pdo->commit();
        return [true, null];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Failed to reset password.'];
    }
}
