<?php
/**
 * Lightweight metrics/events tracking (write-only).
 *
 * Design goals:
 * - Safe: never break page rendering if DB/table is missing.
 * - Simple: one insert per event; meta stored as JSON string.
 * - Flexible: can evolve into dashboards later.
 */

/**
 * Track an analytics event.
 *
 * @param string $name Short event name (e.g. page_view, availability_checked)
 * @param array<string,mixed> $meta Arbitrary event metadata (JSON-encoded)
 * @param int|null $userId Optional user id; defaults to current session user (if present)
 */
function track_event(string $name, array $meta = [], ?int $userId = null): void {
    // Guardrails: keep event names tidy.
    $name = strtolower(trim($name));
    if ($name === '' || strlen($name) > 64) return;

    // Derive user id from current auth session if not provided.
    if ($userId === null && function_exists('auth_user')) {
        try {
            $u = auth_user();
            if (!empty($u['id'])) $userId = (int)$u['id'];
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Add a few useful defaults (without being creepy or high-volume).
    $meta = array_merge([
        'path' => $_SERVER['REQUEST_URI'] ?? null,
        'ref'  => $_SERVER['HTTP_REFERER'] ?? null,
    ], $meta);

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    if ($ua !== null && strlen($ua) > 255) $ua = substr($ua, 0, 255);

    try {
        $pdo = db();
        $stmt = $pdo->prepare("INSERT INTO events (user_id, event_name, path, meta_json, ip, user_agent, created_at)
            VALUES (:user_id, :event_name, :path, :meta_json, :ip, :user_agent, NOW())");

        $stmt->execute([
            ':user_id' => $userId ?: null,
            ':event_name' => $name,
            ':path' => $meta['path'],
            ':meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':ip' => $ip,
            ':user_agent' => $ua,
        ]);
    } catch (Throwable $e) {
        // Silent fail by design (table not created yet, DB offline, etc.).
        return;
    }
}
