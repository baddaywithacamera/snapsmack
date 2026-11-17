<?php
/**
 * SNAPSMACK — who is liking, for the one table that records it.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * WHY THIS IS ITS OWN FILE. The same six lines were written twice: once in
 * process-like.php, which WRITES a like, and once in core/community-dock.php,
 * which READS whether you already gave one. Two copies of an identity rule
 * either agree or they are a bug, and the bug is invisible -- a reader would
 * see an unfilled heart, click it, and be told they had already liked it.
 *
 * SS-1323 needed a third caller, to fill the heart on every tile of a
 * conveyor in one query. A third copy is where that stops being a risk and
 * becomes a certainty, so the rule moved here and all three ask this.
 *
 * The rule itself is unchanged: a signed-in community user is their user_id;
 * everyone else is a SHA-256 of their IP and the install's download salt.
 * The salt is what stops the hash being reversible through a rainbow table.
 * No cookie, no session, and no address stored anywhere.
 */

if (!function_exists('snap_like_identity')) {

/**
 * @return array{0: ?int, 1: ?string} [user_id, guest_hash] -- exactly one set.
 */
function snap_like_identity(?PDO $pdo): array
{
    $user = function_exists('community_current_user') ? community_current_user() : null;
    if ($user && !empty($user['id'])) {
        return [(int)$user['id'], null];
    }

    // SECAUDIT 047: the salt comes from the install, never a constant.
    $salt = function_exists('snap_ensure_download_salt')
        ? (string)snap_ensure_download_salt($pdo)
        : '';
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return [null, hash('sha256', $ip . $salt)];
}

}
// ===== SNAPSMACK EOF =====
