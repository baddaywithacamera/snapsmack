<?php
/**
 * SNAPSMACK - Core Authentication Guard
 *
 * Manages user sessions, login gates, and theme preferences. On each page
 * load, this file ensures a user's preferred skin is pulled from the database
 * and stored in the session, so theme switching persists across the admin
 * interface.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */




// --- ENVIRONMENT BOOTSTRAP ---
// Define BASE_URL if not already set by another file. This ensures all
// relative links work correctly regardless of subdirectory deployment.
if (!defined('BASE_URL')) {
    $is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
             || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $protocol = $is_https ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    define('BASE_URL', $protocol . "://" . $host . "/");
}

// --- SESSION INITIALIZATION ---
// Start the session if it hasn't been started yet. Keep the admin
// logged in for a full day (86 400 seconds) so sessions don't expire
// mid-workflow.
//
// Why not just ini_set? On shared cPanel hosting the host runs its own
// cron job that purges /tmp/sess_* files every ~24 minutes regardless of
// PHP's gc_maxlifetime. We work around this by storing sessions in a
// private directory inside the SnapSmack install that the host cron never
// touches. session_set_cookie_params() is also more reliable than
// ini_set('session.cookie_lifetime') on shared hosts.
if (session_status() === PHP_SESSION_NONE) {
    $ss_session_dir = dirname(__DIR__) . '/data/sessions';
    if (!is_dir($ss_session_dir)) {
        @mkdir($ss_session_dir, 0700, true);
        // Drop a deny-all .htaccess so session files aren't web-accessible
        @file_put_contents($ss_session_dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
    }
    if (is_dir($ss_session_dir) && is_writable($ss_session_dir)) {
        session_save_path($ss_session_dir);
    }
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                   || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', 86400);
    session_start();
}

require_once __DIR__ . '/csrf.php';
require_once 'db.php';

// --- CSRF AUTOVALIDATE ---
// Pages that legitimately POST without a CSRF token (login form, public
// API endpoints, multisite hub-spoke traffic) call csrf_exempt() before
// including this file. Everything else gets enforced here so individual
// admin pages don't have to remember to call csrf_check() themselves.
csrf_check();

// --- LOGOUT HANDLER ---
// If the user clicks logout, destroy the session and redirect to login.
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: " . BASE_URL . "smack-admin.php");
    exit;
}

// --- LOGIN GATE ---
// Block unauthenticated users from seeing admin pages by redirecting to login.
// For XHR requests, return a 401 JSON response instead of the login page HTML
// so the client-side JS can detect the expired session and redirect cleanly.
if (!isset($_SESSION['user_login'])) {
    $is_xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($is_xhr) {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode(['status' => 'session_expired', 'msg' => 'Session expired. Please log in again.']);
        exit;
    }
    $login_slug = $pdo->query(
        "SELECT setting_val FROM snap_settings WHERE setting_key = 'login_slug' LIMIT 1"
    )->fetchColumn() ?: 'snap-in';
    header("Location: " . BASE_URL . ltrim($login_slug, '/'));
    exit;
}

// --- FORCED PASSWORD CHANGE INTERCEPT ---
// Belt-and-suspenders: if the session flag was set (e.g. after a recovery code
// login) redirect to the change-password screen on every authenticated page load
// until the user completes the reset. Critical system pages are exempt so an
// in-progress update or other system operation is never interrupted mid-flight.
if (isset($_SESSION['force_password_change'])) {
    $current_script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $exempt = [
        'smack-change-password.php', // destination page — must not loop
        'smack-update.php',           // never interrupt a live update mid-extraction
    ];
    if (!in_array($current_script, $exempt, true)) {
        header("Location: " . BASE_URL . "smack-change-password.php");
        exit;
    }
}

// --- USER PREFERENCE SYNC ---
// Fetch the user's preferred skin from the database so theme switching
// persists. Store both user_id and preferred_skin in the session.
if (isset($_SESSION['user_login'])) {
    try {
        $stmt = $pdo->prepare("SELECT id, preferred_skin, ui_mode FROM snap_users WHERE username = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_login']]);
        $u_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($u_data) {
            // Store user ID for save operations like theme switching
            $_SESSION['user_id'] = $u_data['id'];

            // Only set the session skin if it hasn't been defined yet.
            // Default to midnight-lime if the database field is empty.
            if (!isset($_SESSION['user_preferred_skin'])) {
                $_SESSION['user_preferred_skin'] = (!empty($u_data['preferred_skin'])) ? $u_data['preferred_skin'] : 'midnight-lime';
            }

            // Load UI mode (bigwheel / pimpmobile) from DB so it persists across sessions.
            if (!isset($_SESSION['user_ui_mode'])) {
                $_SESSION['user_ui_mode'] = (!empty($u_data['ui_mode'])) ? $u_data['ui_mode'] : 'bigwheel';
            }
        }
    } catch (PDOException $e) {
        // Silent fail: a database hiccup should not lock the entire admin interface
    }

    // Refresh the session cookie expiry on every authenticated page load.
    // Without this the cookie (and therefore the session) ages from login
    // time. With this, it's always "24 hours from last activity".
    setcookie(
        session_name(),
        session_id(),
        [
            'expires'  => time() + 86400,
            'path'     => '/',
            'secure'   => snap_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}

// --- FORCE TOTP 2FA ENROLMENT (post-grace) ---
// After installed_at + 30 days, every admin must have TOTP enrolled. Until they
// do, all admin pages redirect to the enrolment screen. The enrolment pages,
// the updater, change-password, and logout are exempt so nobody is bricked.
// An owner emergency override file (core/release-2fa-override — any contents)
// suspends enforcement entirely: the documented escape hatch for a lost
// authenticator AND lost recovery codes (see spec #1; Sean approved).
if (isset($_SESSION['user_login']) && !file_exists(__DIR__ . '/release-2fa-override')) {
    $_2fa_current = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $_2fa_exempt  = ['smack-2fa.php', 'smack-2fa-verify.php', 'smack-update.php', 'smack-change-password.php'];
    if (!in_array($_2fa_current, $_2fa_exempt, true)) {
        try {
            $_installed_at = $pdo->query(
                "SELECT setting_val FROM snap_settings WHERE setting_key = 'installed_at' LIMIT 1"
            )->fetchColumn();
            // Defensive: stamp installed_at the first time it's missing. Upgraded
            // installs thus get a fresh 30-day clock from now — honest, and it can
            // never brick an existing admin mid-session.
            if (!$_installed_at) {
                $pdo->prepare(
                    "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('installed_at', NOW())
                     ON DUPLICATE KEY UPDATE setting_val = setting_val"
                )->execute();
                $_installed_at = date('Y-m-d H:i:s');
            }
            $_inst_ts = strtotime((string)$_installed_at);
            if ($_inst_ts && time() > $_inst_ts + (30 * 86400)) {
                $_totp_stmt = $pdo->prepare("SELECT totp_enabled FROM snap_users WHERE id = ? LIMIT 1");
                $_totp_stmt->execute([$_SESSION['user_id'] ?? 0]);
                if (!(int)$_totp_stmt->fetchColumn()) {
                    $_SESSION['totp_enrol_required'] = 1;
                    header('Location: ' . BASE_URL . 'smack-2fa.php?enrol=required');
                    exit;
                }
                unset($_totp_stmt);
            }
        } catch (PDOException $e) {
            // Non-fatal: a DB hiccup must never hard-lock the admin out.
        }
        unset($_installed_at, $_inst_ts);
    }
    unset($_2fa_current, $_2fa_exempt);
}

// --- SMACKBACK BREACH GATE ---
// In lockout or paranoid mode, redirect all admin pages to smack-back.php
// when a breach is active. smack-back.php and smack-update.php are exempt
// (they're how you resolve the breach). Alert mode: no redirect.
// Breach LOCKDOWN allowlist (Sean's spec #2): while locked down the admin can
// reach only — the breach screen (silence the flash; the red skin stays), the
// updater (replace bad files), the support forum (get help), and the backup
// utilities (grab a backup BEFORE replacing files). Everything else redirects
// to the breach screen, forcing the owner to deal with the tampering.
$_smack_current = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
$_smack_exempt  = [
    'smack-back.php',             // breach detail + silence-flash
    'smack-update.php',           // updater — replace bad files
    'smack-forum.php',            // support forum — get help
    'smack-backup.php',           // backup util (solo)
    'smack-multisite-backup.php', // backup util (fleet)
];

if (!in_array($_smack_current, $_smack_exempt, true)) {
    try {
        $_smack_row = $pdo->query(
            "SELECT setting_key, setting_val FROM snap_settings
             WHERE setting_key IN ('smackback_enabled', 'smackback_mode', 'smackback_status')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        if (($_smack_row['smackback_enabled'] ?? '0') === '1'
            && ($_smack_row['smackback_status'] ?? 'clean') === 'breach') {
            if (($_smack_row['smackback_mode'] ?? 'lockout') !== 'alert') {
                header('Location: ' . BASE_URL . 'smack-back.php');
                exit;
            } else {
                // Alert mode: let admin through but flag a banner for admin-header.php
                $GLOBALS['_smackback_alert_breach'] = true;
            }
        }
    } catch (PDOException $e) { /* non-fatal */ }
}
unset($_smack_current, $_smack_exempt, $_smack_row);

// --- NETWORK ALERT STATUS CHECK (Layer 2 — SC global YELLOW only) ---
// Poll SC for current alert level (throttled to every 30 min).
// Sets banner globals picked up by admin-header.php.
// Entirely separate from the Layer 1 hub/spoke RED above.
if (!defined('NALERT_CHECK_DONE')) {
    define('NALERT_CHECK_DONE', true);
    try {
        require_once __DIR__ . '/network-alert.php';
        nalert_maybe_poll();
        $_na = nalert_get_local();
        if ($_na['receive'] && in_array($_na['status'], ['yellow_slow', 'yellow_fast'], true)) {
            $GLOBALS['_nalert_status']  = $_na['status'];
            $GLOBALS['_nalert_message'] = $_na['message'];
        }
        unset($_na);
    } catch (Throwable $e) { /* non-fatal — network alert must never break admin access */ }
}
// ===== SNAPSMACK EOF =====
