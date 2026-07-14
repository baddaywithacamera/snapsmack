<?php
/**
 * SNAPSMACK - Ban Check
 *
 * Centralised ban lookup used by both comment processors.
 * Checks a submission against the snap_ban_list table by:
 *   1. Browser fingerprint hash (defeats VPN / IP rotation)
 *   2. IP address
 *   3. SHA-256 hash of the lowercased email address (if provided)
 *
 * Also checks SMACK THE ENEMY network scores if ste_enabled = 1.
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
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


if (!function_exists('ste_worst_colour')) {
    require_once __DIR__ . '/ste-client.php';
}

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
    if ($stmt->fetchColumn()) return true;

    // --- SMACK THE ENEMY network score check ---
    // If enabled, also block submitters whose cached score meets the threshold.
    try {
        $ste_enabled   = ($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='ste_enabled' LIMIT 1")->fetchColumn() ?? '0') === '1';
        $ste_threshold = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='ste_auto_ban_threshold' LIMIT 1")->fetchColumn() ?: 'red';

        if ($ste_enabled && $ste_threshold !== 'never') {
            $ste_checks = [];
            if ($fp_hash !== '') $ste_checks[] = ['ban_type' => 'fingerprint', 'ban_value' => $fp_hash];
            if ($ip !== '')      $ste_checks[] = ['ban_type' => 'ip',          'ban_value' => $ip];
            if ($email !== '')   $ste_checks[] = ['ban_type' => 'email',       'ban_value' => $email];

            if (!empty($ste_checks)) {
                $colour = ste_worst_colour($pdo, $ste_checks);
                if (ste_exceeds_threshold($colour, $ste_threshold)) {
                    return true;
                }
            }
        }
    } catch (Exception $e) {
        // STE check is non-fatal — never block a clean comment due to DB error
    }

    return false;
}

/**
 * Add a ban to snap_ban_list, and report to SMACK THE ENEMY if enabled.
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
    $inserted = $stmt->rowCount() > 0;

    // Report to SMACK THE ENEMY network (non-blocking, non-fatal)
    if ($inserted) {
        try {
            $ste_enabled = ($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='ste_enabled' LIMIT 1")->fetchColumn() ?? '0') === '1';
            if ($ste_enabled) {
                require_once __DIR__ . '/secret-store.php';
                $ste_key = secret_decrypt((string)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='ste_api_key' LIMIT 1")->fetchColumn() ?: ''));
                if ($ste_key !== '') {
                    // Translate local ban_type to STE ban_type
                    $ste_type = ($ban_type === 'email_hash') ? 'email' : $ban_type;

                    // Extract stylometric vector from this commenter's comment history.
                    // Raw text never leaves this server — only the numeric vector is transmitted.
                    $style_vector = null;
                    if (!isset($ste_style_loaded)) {
                        @include_once __DIR__ . '/ste-style.php';
                        $ste_style_loaded = true;
                    }
                    if (function_exists('ste_style_extract')) {
                        $comment_texts = _ste_fetch_comment_texts($pdo, $ban_type, $ban_value);
                        if (!empty($comment_texts)) {
                            $style_vector = ste_style_extract($comment_texts);
                        }
                    }

                    ste_client_report($ste_key, [['ban_type' => $ste_type, 'ban_value' => $ban_value]], $style_vector);
                }
            }
        } catch (Exception $e) {
            // Non-fatal — local ban is already recorded
        }
    }

    return $inserted;
}

/**
 * Fetch comment texts for a banned commenter — used for stylometric extraction.
 * Matches by ban_type: fingerprint → fp_hash, ip → comment_ip, email_hash → comment_email.
 * Returns an array of comment_text strings (may be empty).
 *
 * @internal Called only from add_ban() when STE is enabled.
 */
function _ste_fetch_comment_texts(PDO $pdo, string $ban_type, string $ban_value): array {
    try {
        switch ($ban_type) {
            case 'fingerprint':
                $stmt = $pdo->prepare(
                    "SELECT comment_text FROM snap_comments WHERE fp_hash = ? AND comment_text IS NOT NULL LIMIT 50"
                );
                $stmt->execute([$ban_value]);
                break;
            case 'ip':
                $stmt = $pdo->prepare(
                    "SELECT comment_text FROM snap_comments WHERE comment_ip = ? AND comment_text IS NOT NULL LIMIT 50"
                );
                $stmt->execute([$ban_value]);
                break;
            case 'email_hash':
                // ban_value is the raw email address at this point (hashed later by ste-client)
                $stmt = $pdo->prepare(
                    "SELECT comment_text FROM snap_comments WHERE comment_email = ? AND comment_text IS NOT NULL LIMIT 50"
                );
                $stmt->execute([$ban_value]);
                break;
            default:
                return [];
        }
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'comment_text');
    } catch (Exception $e) {
        return [];
    }
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
// ===== SNAPSMACK EOF =====
