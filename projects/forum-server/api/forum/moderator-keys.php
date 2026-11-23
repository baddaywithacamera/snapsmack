<?php
/**
 * SNAPSMACK FORUM — per-person moderator key authentication (SS-1510).
 *
 * A moderator key is deliberately narrower than both an install key and the
 * global owner key. It may enter read paths needed to inspect the forum and
 * the mutation paths that explicitly opt into moderation, but it cannot post,
 * react, manage boards or installs, or issue another key.
 */

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

if (!function_exists('forum_moderator_key_hash')) {

function forum_moderator_key_hash(string $secret): string {
    $pepper = hash('sha256', 'forum-person-key:' . FORUM_MOD_KEY, true);
    return hash_hmac('sha256', $secret, $pepper);
}

/** @return array{key_id:string,secret:string}|null */
function forum_parse_moderator_key(string $token): ?array {
    if (preg_match('/^modp_([a-f0-9]{16})_([a-f0-9]{64})$/', $token, $m) !== 1) {
        return null;
    }
    return ['key_id' => $m[1], 'secret' => $m[2]];
}

/**
 * Return the bounded synthetic actor used by moderation routes and the audit
 * logger, or null when the token is absent, malformed, revoked or invalid.
 */
function forum_authenticate_moderator_key(PDO $pdo, string $token): ?array {
    $parsed = forum_parse_moderator_key($token);
    if ($parsed === null) return null;

    try {
        $stmt = $pdo->prepare(
            "SELECT id, key_id, label, secret_hash
             FROM ss_forum_moderator_keys
             WHERE key_id = ? AND revoked_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$parsed['key_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals((string)$row['secret_hash'], forum_moderator_key_hash($parsed['secret']))) {
            return null;
        }

        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $pdo->prepare(
            "UPDATE ss_forum_moderator_keys
             SET last_used_at = CURRENT_TIMESTAMP, last_used_ip = ?
             WHERE id = ?"
        )->execute([$ip, (int)$row['id']]);

        return [
            'id'               => null,
            'domain'           => 'moderator:' . (string)$row['label'] . '#' . (string)$row['key_id'],
            'display_name'     => (string)$row['label'],
            'role'             => 2,
            'is_moderator'     => 1,
            'auth_kind'        => 'moderator_key',
            'moderator_key_id' => (string)$row['key_id'],
        ];
    } catch (Throwable $e) {
        error_log('[forum moderator-key] authentication failed: ' . $e->getMessage());
        return null;
    }
}

}

// ===== SNAPSMACK EOF =====
