<?php
/**
 * SNAPSMACK - Authorization (privilege-level) helpers
 *
 * core/auth.php gates on being LOGGED IN. These gate on privilege LEVEL for
 * sensitive actions (managing user accounts, moderating community members).
 *
 * Roles live in snap_users.user_role (NOT NULL DEFAULT 'editor'); the installer
 * creates the primary account as 'admin'. Login mirrors the value into
 * $_SESSION['user_role']. Historically NO admin page checked the role, so an
 * 'editor' (meant to be content-only) could reach user-management handlers and
 * promote itself. These helpers close that.
 *
 * function_exists-guarded so this file is safe to include more than once.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!function_exists('snap_current_role')) {
    function snap_current_role(): string {
        return (string)($_SESSION['user_role'] ?? '');
    }
}

if (!function_exists('snap_is_admin')) {
    function snap_is_admin(): bool {
        return snap_current_role() === 'admin';
    }
}

if (!function_exists('snap_require_admin')) {
    /**
     * Halt with 403 unless the current session is an Administrator. Call at the
     * top of admin-only pages, or immediately before a privilege-sensitive
     * handler, AFTER core/auth.php has established the session.
     */
    function snap_require_admin(): void {
        if (!snap_is_admin()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            die('403 — Administrator privileges are required for this action.');
        }
    }
}
// ===== SNAPSMACK EOF =====
