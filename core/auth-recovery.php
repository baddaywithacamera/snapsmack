<?php
/**
 * SNAPSMACK - Auth Recovery Engine
 *
 * Shared functions for one-time recovery codes and email-based password
 * reset tokens. Used by snap-in.php, smack-users.php, smack-edit-user.php,
 * smack-change-password.php, and password-reset.php.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// ─── ONE-TIME RECOVERY CODES ─────────────────────────────────────────────────

/**
 * Generate a human-readable one-time recovery code.
 * Format: SNAP-XXXX-XXXX-XXXX (uppercase alphanumeric, no ambiguous chars).
 */
function snapsmack_generate_recovery_code(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I
    $seg   = function() use ($chars): string {
        $s = '';
        for ($i = 0; $i < 4; $i++) {
            $s .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $s;
    };
    return 'SNAP-' . $seg() . '-' . $seg() . '-' . $seg();
}

/**
 * Generate a recovery code, store its bcrypt hash in snap_users, and return
 * the plaintext code. The plaintext is never stored — show it once and discard.
 */
function snapsmack_store_recovery_code(PDO $pdo, int $user_id): string {
    $code = snapsmack_generate_recovery_code();
    $hash = password_hash($code, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("UPDATE snap_users SET recovery_code_hash = ? WHERE id = ?")
        ->execute([$hash, $user_id]);
    return $code;
}

/**
 * Validate a recovery code for the given username.
 * Returns the user row on success, false on failure.
 * Does NOT consume the code — call snapsmack_consume_recovery_code() after login.
 */
function snapsmack_validate_recovery_code(PDO $pdo, string $username, string $code): array|false {
    $stmt = $pdo->prepare("SELECT * FROM snap_users WHERE username = ? AND recovery_code_hash IS NOT NULL");
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return false;
    if (!password_verify(strtoupper(trim($code)), $user['recovery_code_hash'])) return false;
    return $user;
}

/**
 * Clear the recovery code and set the force_password_change flag.
 * Call immediately after a successful recovery code login.
 */
function snapsmack_consume_recovery_code(PDO $pdo, int $user_id): void {
    $pdo->prepare("UPDATE snap_users SET recovery_code_hash = NULL, force_password_change = 1 WHERE id = ?")
        ->execute([$user_id]);
}

/**
 * Clear the force_password_change flag once the user has changed their password.
 */
function snapsmack_clear_force_change(PDO $pdo, int $user_id): void {
    $pdo->prepare("UPDATE snap_users SET force_password_change = 0 WHERE id = ?")
        ->execute([$user_id]);
}

// ─── EMAIL-BASED PASSWORD RESET TOKENS ───────────────────────────────────────

/**
 * Generate a password reset token for the given email address.
 * Clears any existing tokens for the same email first.
 * Returns the plaintext token, or false if no account with that email exists.
 */
function snapsmack_generate_reset_token(PDO $pdo, string $email): string|false {
    $email = strtolower(trim($email));
    $stmt  = $pdo->prepare("SELECT id FROM snap_users WHERE LOWER(email) = ?");
    $stmt->execute([$email]);
    if (!$stmt->fetch()) return false;

    // Clear stale tokens for this email
    $pdo->prepare("DELETE FROM snap_password_resets WHERE email = ?")->execute([$email]);

    $token  = bin2hex(random_bytes(32)); // 64 hex chars
    $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour

    $pdo->prepare("INSERT INTO snap_password_resets (email, token, expiry) VALUES (?, ?, ?)")
        ->execute([$email, $token, $expiry]);

    return $token;
}

/**
 * Validate a password reset token.
 * Returns the user row on success, false if expired or invalid.
 */
function snapsmack_validate_reset_token(PDO $pdo, string $token): array|false {
    $stmt = $pdo->prepare(
        "SELECT u.* FROM snap_password_resets r
         JOIN snap_users u ON LOWER(u.email) = LOWER(r.email)
         WHERE r.token = ? AND r.expiry > NOW()"
    );
    $stmt->execute([trim($token)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: false;
}

/**
 * Consume a reset token — delete it so it can't be reused.
 */
function snapsmack_consume_reset_token(PDO $pdo, string $token): void {
    $pdo->prepare("DELETE FROM snap_password_resets WHERE token = ?")->execute([trim($token)]);
}

// ─── EMAIL SENDERS ───────────────────────────────────────────────────────────

/**
 * Email a one-time recovery code to the user.
 * Returns true if mail() accepted the message.
 */
function snapsmack_send_recovery_email(
    string $to,
    string $username,
    string $code,
    string $site_name,
    string $site_url
): bool {
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $subject = "[{$site_name}] Your one-time recovery code";
    $body    = "Hi {$username},\n\n"
             . "A one-time recovery code has been generated for your SnapSmack account at {$site_url}.\n\n"
             . "Your recovery code is:\n\n"
             . "    {$code}\n\n"
             . "Go to {$site_url}snap-in and use the RECOVERY CODE tab to log in.\n"
             . "You will be required to set a new password immediately after.\n\n"
             . "This code can only be used once. If you did not request this, contact your administrator.\n\n"
             . "— {$site_name}";

    // Route through the central mailer (Brevo HTTP API when configured, else mail()).
    // From/sender comes from the configured email_from + site_url settings via global $pdo.
    if (!function_exists('snapsmack_send_mail')) {
        require_once __DIR__ . '/mailer.php';
    }
    return snapsmack_send_mail($to, $subject, $body);
}

/**
 * Email a password reset link.
 * Returns true if mail() accepted the message.
 */
function snapsmack_send_reset_email(
    string $to,
    string $username,
    string $token,
    string $site_name,
    string $site_url
): bool {
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $reset_url = rtrim($site_url, '/') . '/password-reset.php?token=' . urlencode($token);
    $subject   = "[{$site_name}] Password reset request";
    $body      = "Hi {$username},\n\n"
               . "A password reset was requested for your account at {$site_url}.\n\n"
               . "Click the link below to set a new password. This link expires in 1 hour.\n\n"
               . "    {$reset_url}\n\n"
               . "If you did not request a password reset, you can ignore this email.\n\n"
               . "— {$site_name}";

    // Route through the central mailer (Brevo HTTP API when configured, else mail()).
    if (!function_exists('snapsmack_send_mail')) {
        require_once __DIR__ . '/mailer.php';
    }
    return snapsmack_send_mail($to, $subject, $body);
}
// ===== SNAPSMACK EOF =====
