<?php
/**
 * Email change verification flow
 *
 * Table expected:
 * CREATE TABLE email_changes (
 *   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   user_id BIGINT UNSIGNED NOT NULL,
 *   new_email VARCHAR(190) NOT NULL,
 *   token_hash CHAR(64) NOT NULL,
 *   expires_at DATETIME NOT NULL,
 *   used_at DATETIME NULL,
 *   created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   request_ip VARCHAR(45) NULL,
 *   user_agent VARCHAR(255) NULL,
 *   INDEX(user_id),
 *   INDEX(token_hash),
 *   INDEX(expires_at),
 *   UNIQUE KEY uniq_active_new_email (new_email, used_at),
 *   CONSTRAINT fk_ec_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
 * );
 */

function email_change_create_and_email(int $userId, string $newEmail, string $baseUrl, int $ttlMinutes = 60): void {
    $pdo = db();

    $newEmail = trim(strtolower($newEmail));
    if ($newEmail === '') return;

    // Ensure new email isn't already used by an active user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email=? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$newEmail]);
    if ($stmt->fetch()) {
        // Don't leak; just silently do nothing.
        return;
    }

    // Token: keep raw token only for the link; store hash in DB
    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);

    $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . max(5, (int)$ttlMinutes) . ' minutes')
        ->format('Y-m-d H:i:s');

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    // Invalidate any prior pending email change requests for this user
    $pdo->prepare("UPDATE email_changes SET used_at = NOW() WHERE user_id=? AND used_at IS NULL")
        ->execute([$userId]);

    $stmt = $pdo->prepare("
        INSERT INTO email_changes (user_id, new_email, token_hash, expires_at, request_ip, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $newEmail, $hash, $expires, $ip, $ua]);

    $link = rtrim($baseUrl, '/') . '/verify_email_change.php?token=' . urlencode($token);

    $subject = "Confirm your email change";
    $html = "
      <div style='font-family: Arial, sans-serif; line-height:1.5'>
        <h2 style='margin:0 0 12px'>Confirm your email change</h2>
        <p>We received a request to change the email on your Ready Set Shows account to:</p>
        <p style='font-size:16px'><strong>" . h($newEmail) . "</strong></p>
        <p>Click the button below to confirm. This link expires in " . (int)$ttlMinutes . " minutes.</p>
        <p style='margin:18px 0'>
          <a href='" . h($link) . "' style='display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:10px 14px;border-radius:8px'>Confirm Email Change</a>
        </p>
        <p style='color:#555'>If you didn't request this, you can ignore this email.</p>
      </div>
    ";

    Mailer::send([
        'to_email' => $newEmail,
        'subject'  => $subject,
        'html'     => $html
    ]);
}

function email_change_consume(string $token): array {
    $token = trim((string)$token);
    if ($token === '') return [false, 'Invalid token'];

    $hash = hash('sha256', $token);
    $pdo = db();

    $stmt = $pdo->prepare("
      SELECT id, user_id, new_email, expires_at, used_at
      FROM email_changes
      WHERE token_hash=? LIMIT 1
    ");
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row) return [false, 'Invalid or expired link'];
    if (!empty($row['used_at'])) return [false, 'This link has already been used'];

    $expires = new DateTimeImmutable((string)$row['expires_at'], new DateTimeZone('UTC'));
    if ($expires < new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
        return [false, 'This link has expired'];
    }

    $userId = (int)$row['user_id'];
    $newEmail = (string)$row['new_email'];

    // Ensure user still exists and not deleted
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id=? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) return [false, 'Account not found'];

    // Ensure email isn't already taken by another active user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email=? AND deleted_at IS NULL AND id<>? LIMIT 1");
    $stmt->execute([$newEmail, $userId]);
    if ($stmt->fetch()) return [false, 'That email is already in use'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE users SET email=?, updated_at=NOW() WHERE id=?")->execute([$newEmail, $userId]);
        $pdo->prepare("UPDATE email_changes SET used_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
        $pdo->commit();
        return [true, null];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Failed to update email'];
    }
}
