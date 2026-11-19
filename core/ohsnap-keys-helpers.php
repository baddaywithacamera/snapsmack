<?php
/**
 * SNAPSMACK - Shared snap_ohsnap_keys lifecycle helpers (SS-1398 / SS-1400).
 *
 * Enforces SS-1400 server-side: a live key must be revoked (or expired) before
 * it can be deleted.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * List all API keys (metadata only; never returns key_hash).
 *
 * @return array<int, array<string, mixed>>
 */
function snap_ohsnap_keys_list(PDO $pdo): array {
    $stmt = $pdo->query(
        "SELECT id, label, key_type, key_prefix, last_used_at, created_at, expires_at, is_active
         FROM snap_ohsnap_keys
         ORDER BY created_at DESC, id DESC"
    );
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * Revoke a key by id.
 *
 * @return array{ok:bool,status:int,error?:string}
 */
function snap_ohsnap_key_revoke(PDO $pdo, int $key_id): array {
    if ($key_id <= 0) {
        return ['ok' => false, 'status' => 400, 'error' => 'Invalid key_id'];
    }
    $stmt = $pdo->prepare("SELECT id FROM snap_ohsnap_keys WHERE id = ? LIMIT 1");
    $stmt->execute([$key_id]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        return ['ok' => false, 'status' => 404, 'error' => 'Key not found'];
    }
    $pdo->prepare("UPDATE snap_ohsnap_keys SET is_active = 0 WHERE id = ?")->execute([$key_id]);
    return ['ok' => true, 'status' => 200];
}

/**
 * Delete a key by id ONLY if it is already revoked (is_active = 0) or expired (SS-1400).
 *
 * @return array{ok:bool,status:int,error?:string}
 */
function snap_ohsnap_key_delete(PDO $pdo, int $key_id, ?int $now = null): array {
    if ($key_id <= 0) {
        return ['ok' => false, 'status' => 400, 'error' => 'Invalid key_id'];
    }
    $now = $now ?? time();
    $stmt = $pdo->prepare(
        "SELECT id, is_active, expires_at FROM snap_ohsnap_keys WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$key_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'status' => 404, 'error' => 'Key not found'];
    }
    $is_active = (int)($row['is_active'] ?? 0) === 1;
    $exp_raw = (string)($row['expires_at'] ?? '');
    $expired = ($exp_raw !== '' && ($ts = strtotime($exp_raw)) !== false && $ts <= $now);

    if ($is_active && !$expired) {
        return [
            'ok'     => false,
            'status' => 409,
            'error'  => 'Live key must be revoked before it can be deleted.',
        ];
    }
    $pdo->prepare("DELETE FROM snap_ohsnap_keys WHERE id = ?")->execute([$key_id]);
    return ['ok' => true, 'status' => 200];
}

/**
 * Step-up verification for remote key actions with server-side 5-attempt lockout.
 * Delegates to SS-1399 reauth_verify() when present and enforces the same
 * snap_rate_limits budget if SS-1399 has not merged yet.
 *
 * @return array{ok:bool,error:string,locked?:bool}
 */
function snap_ohsnap_keys_stepup(PDO $pdo, string $password, string $totp_code, ?int $now = null): array {
    $now = $now ?? time();
    if (defined('REAUTH_MAX_ATTEMPTS')) {
        return reauth_verify($pdo, $password, $totp_code, $now);
    }

    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return ['ok' => false, 'error' => 'No active session.'];
    }
    $key = 'uid:' . $uid;
    $stmt = $pdo->prepare("SELECT count, window_start FROM snap_rate_limits WHERE ip = ? AND action = 'reauth_fail' LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && (int)$row['count'] >= 5) {
        $ws = strtotime((string)$row['window_start'] . ' UTC') ?: $now;
        if ($now < $ws + 900) {
            return ['ok' => false, 'locked' => true, 'error' => 'Too many failed verification attempts. Locked for 15 minute(s).'];
        }
        $pdo->prepare("DELETE FROM snap_rate_limits WHERE ip = ? AND action = 'reauth_fail'")->execute([$key]);
    }

    $ra = reauth_verify($pdo, $password, $totp_code);
    if (!empty($ra['ok'])) {
        $pdo->prepare("DELETE FROM snap_rate_limits WHERE ip = ? AND action = 'reauth_fail'")->execute([$key]);
        return $ra;
    }

    $now_str = gmdate('Y-m-d H:i:s', $now);
    $new_count = $row ? ((int)$row['count'] + 1) : 1;
    if ($row) {
        $pdo->prepare("UPDATE snap_rate_limits SET count = ?, window_start = ? WHERE ip = ? AND action = 'reauth_fail'")
            ->execute([$new_count, $now_str, $key]);
    } else {
        $pdo->prepare("INSERT INTO snap_rate_limits (ip, action, count, window_start) VALUES (?, 'reauth_fail', 1, ?)")
            ->execute([$key, $now_str]);
    }
    if ($new_count >= 5) {
        $ra['locked'] = true;
    }
    return $ra;
}
// ===== SNAPSMACK EOF =====
