<?php
/**
 * SNAPSMACK - Ban Check
 * Alpha v0.7.9c
 *
 * Centralised ban lookup used by both comment processors.
 * Checks a submission against the snap_ban_list table by:
 *   1. Browser fingerprint hash (defeats VPN / IP rotation)
 *   2. IP address
 *   3. SHA-256 hash of the lowercased email address (if provided)
 *
 * Returns true if the submission should be blocked, false if clean.
 *
 * Usage:
 *   require_once 'core/ban-check.php';
 *   if (is_banned($pdo, $fp_hash, $ip, $email)) { ... reject ... }
 *
 * Email addresses are never stored in plain text in snap_ban_list —
 * only their SHA-256 hash. This protects the submitter's privacy even
 * if the database is compromised.
 */

/**
 * Check whether a comment submission matches any active ban.
 *
 * @param  PDO    $pdo      Database connection
 * @param  string $fp_hash  64-char hex fingerprint hash (may be empty string)
 * @param  string $ip       Submitter IP address
 * @param  string $email    Raw email address (will be hashed here, never stored)
 * @return bool             true = banned, false = allowed
 */
function is_banned(PDO $pdo, string $fp_hash, string $ip, string $email = ''): bool {
    $checks = [];

    if ($fp_hash !== '') {
        $checks[] = ['fingerprint', $fp_hash];
    }

    if ($ip !== '') {
        $checks[] = ['ip', $ip];
    }

    if ($email !== '') {
        $checks[] = ['email_hash', hash('sha256', strtolower(trim($email)))];
    }

    if (empty($checks)) {
        return false;
    }

    // Build a single query: SELECT 1 WHERE (type=? AND value=?) OR (type=? AND value=?) ...
    $clauses = array_fill(0, count($checks), '(`ban_type` = ? AND `ban_value` = ?)');
    $sql     = 'SELECT 1 FROM `snap_ban_list` WHERE ' . implode(' OR ', $clauses) . ' LIMIT 1';

    $params = [];
    foreach ($checks as [$type, $value]) {
        $params[] = $type;
        $params[] = $value;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

/**
 * Add a ban to snap_ban_list.
 *
 * @param  PDO    $pdo       Database connection
 * @param  string $ban_type  'fingerprint' | 'ip' | 'email_hash'
 * @param  string $ban_value The value to ban (email should already be hashed)
 * @param  string $reason    Admin note (not shown to the user)
 * @param  int|null $banned_by  snap_users.id of the admin issuing the ban
 * @return bool              true on success, false if already exists
 */
function add_ban(PDO $pdo, string $ban_type, string $ban_value, string $reason = '', ?int $banned_by = null): bool {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO `snap_ban_list`
            (`ban_type`, `ban_value`, `reason`, `banned_by`)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$ban_type, $ban_value, $reason ?: null, $banned_by]);
    return $stmt->rowCount() > 0;
}

/**
 * Remove a ban by ID.
 *
 * @param  PDO $pdo
 * @param  int $ban_id  snap_ban_list.id
 * @return bool
 */
function remove_ban(PDO $pdo, int $ban_id): bool {
    $stmt = $pdo->prepare("DELETE FROM `snap_ban_list` WHERE `id` = ?");
    $stmt->execute([$ban_id]);
    return $stmt->rowCount() > 0;
}
