<?php
/**
 * SNAPSMACK - Two-Factor Authentication Verification
 *
 * Interstitial step between successful password authentication and full
 * session grant. Shown only when the user has 2FA enabled. Accepts either
 * a live TOTP code or a one-time 2FA recovery code.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/db.php';
require_once 'core/totp.php';

// --- SESSION INITIALIZATION ---
// Must match the session config in snap-in.php and core/auth-smack.php exactly.
if (session_status() === PHP_SESSION_NONE) {
    $ss_session_dir = __DIR__ . '/data/sessions';
    if (!is_dir($ss_session_dir)) {
        @mkdir($ss_session_dir, 0700, true);
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

// --- GUARD ---
// Only reachable if snap-in.php planted a pending user ID.
// If it's missing, the user hasn't passed password verification — send them back.
if (empty($_SESSION['totp_pending_user_id'])) {
    header("Location: snap-in.php");
    exit;
}

// Also block already-authenticated users.
if (isset($_SESSION['user_login'])) {
    header("Location: smack-admin.php");
    exit;
}

// --- BRUTE-FORCE PROTECTION ---
// Allow at most 5 failed TOTP attempts per pending session before expiring
// the pending session entirely. This prevents online brute-force of the
// 6-digit code space (1,000,000 possibilities) within the 30-second window.
if (!isset($_SESSION['totp_fail_count'])) {
    $_SESSION['totp_fail_count'] = 0;
}

if ($_SESSION['totp_fail_count'] >= 5) {
    // Too many failures — wipe the pending session and force a fresh login.
    session_unset();
    session_destroy();
    header("Location: snap-in.php?err=2fa_locked");
    exit;
}

// --- TOTP TRUST COOKIE CHECK ---
// If the user has a valid ss_totp_trust cookie, verify it against the DB and,
// if good, skip the TOTP interstitial entirely and grant a full session now.
// This is the "remember this device for N days" fast-path.
function ss_totp_trust_days(PDO $pdo): int {
    $val = $pdo->query(
        "SELECT setting_val FROM snap_settings WHERE setting_key = 'totp_trust_days' LIMIT 1"
    )->fetchColumn();
    $days = (int)($val ?: 30);
    return max(1, min(90, $days));
}

function ss_totp_grant_trust(PDO $pdo, int $user_id, string $ua): string {
    $token      = bin2hex(random_bytes(32));   // 64-char hex raw token
    $token_hash = hash('sha256', $token);
    $days       = ss_totp_trust_days($pdo);
    // Defensive CREATE in case migration hasn't run yet on older installs
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `snap_totp_devices` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`     INT UNSIGNED NOT NULL,
            `token_hash`  CHAR(64) NOT NULL,
            `device_hint` VARCHAR(120) NOT NULL DEFAULT '',
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at`  DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_token_hash` (`token_hash`),
            KEY `idx_user_expires` (`user_id`, `expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Derive a friendly device hint from the UA string
    $hint = substr(preg_replace('/[^\x20-\x7E]/', '', $ua), 0, 120);
    $pdo->prepare(
        "INSERT INTO snap_totp_devices (user_id, token_hash, device_hint, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))"
    )->execute([$user_id, $token_hash, $hint, $days]);
    return $token;
}

function ss_totp_check_trust(PDO $pdo, int $user_id): bool {
    $token = $_COOKIE['ss_totp_trust'] ?? '';
    if (strlen($token) !== 64) return false;
    $hash = hash('sha256', $token);
    try {
        $row = $pdo->prepare(
            "SELECT id FROM snap_totp_devices
             WHERE user_id = ? AND token_hash = ? AND expires_at > NOW() LIMIT 1"
        );
        $row->execute([$user_id, $hash]);
        return (bool)$row->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

if (ss_totp_check_trust($pdo, $pending_id)) {
    // Trusted device — grant full session, no TOTP needed.
    session_regenerate_id(true);
    unset($_SESSION['totp_pending_user_id'], $_SESSION['totp_fail_count']);
    $_SESSION['user_login']          = $user['username'];
    $_SESSION['user_role']           = $user['user_role'] ?: 'editor';
    $_SESSION['user_preferred_skin'] = $user['preferred_skin'] ?: null;
    $_SESSION['user_id']             = $user['id'];
    if (!empty($user['force_password_change'])) {
        $_SESSION['force_password_change'] = true;
        header("Location: smack-change-password.php");
        exit;
    }
    header("Location: smack-admin.php");
    exit;
}

// --- ADMIN THEME RESOLUTION ---
if (!isset($settings)) {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

$active_theme    = $settings['active_theme'] ?? 'midnight-lime';
$theme_base      = "assets/adminthemes/{$active_theme}/";
$manifest_path   = $theme_base . "{$active_theme}-manifest.php";
$colour_css_file = "admin-theme-colours-{$active_theme}.css";

if (file_exists($manifest_path)) {
    $m_data = include $manifest_path;
    if (isset($m_data['css_file'])) {
        $colour_css_file = $m_data['css_file'];
    }
}

$active_skin_path = $theme_base . $colour_css_file;

// --- LOAD PENDING USER ---
$pending_id = (int) $_SESSION['totp_pending_user_id'];
$stmt = $pdo->prepare(
    "SELECT id, username, user_role, preferred_skin, force_password_change,
            totp_secret, totp_recovery_json
     FROM snap_users WHERE id = ? LIMIT 1"
);
$stmt->execute([$pending_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || empty($user['totp_secret'])) {
    // Shouldn't happen, but if the user no longer has 2FA active just log them in.
    unset($_SESSION['totp_pending_user_id']);
    $_SESSION['user_login']          = $user['username'];
    $_SESSION['user_role']           = $user['user_role'] ?: 'editor';
    $_SESSION['user_preferred_skin'] = $user['preferred_skin'] ?: null;
    $_SESSION['user_id']             = $user['id'];
    header("Location: smack-admin.php");
    exit;
}

$error      = '';
$active_tab = 'totp'; // 'totp' or 'recovery'

// --- VERIFICATION HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $verify_type = $_POST['verify_type'] ?? 'totp';

    // ── Live TOTP code ───────────────────────────────────────────────────────
    if ($verify_type === 'totp') {
        $submitted = trim($_POST['totp_code'] ?? '');
        if (totp_verify($user['totp_secret'], $submitted)) {
            // Code is good — regenerate session ID to prevent session fixation,
            // then clear the pending state and grant a full session.
            session_regenerate_id(true);
            unset($_SESSION['totp_pending_user_id'], $_SESSION['totp_fail_count']);
            $_SESSION['user_login']          = $user['username'];
            $_SESSION['user_role']           = $user['user_role'] ?: 'editor';
            $_SESSION['user_preferred_skin'] = $user['preferred_skin'] ?: null;
            $_SESSION['user_id']             = $user['id'];

            // Issue long-lived trust cookie if user checked "Trust this device"
            if (!empty($_POST['trust_device'])) {
                $trust_token = ss_totp_grant_trust($pdo, $user['id'], $_SERVER['HTTP_USER_AGENT'] ?? '');
                $trust_days  = ss_totp_trust_days($pdo);
                $is_https    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
                setcookie('ss_totp_trust', $trust_token, [
                    'expires'  => time() + ($trust_days * 86400),
                    'path'     => '/',
                    'secure'   => $is_https,
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
            }

            if (!empty($user['force_password_change'])) {
                $_SESSION['force_password_change'] = true;
                header("Location: smack-change-password.php");
                exit;
            }

            header("Location: smack-admin.php");
            exit;
        } else {
            $_SESSION['totp_fail_count']++;
            $error      = 'Incorrect code. Check your authenticator app and try again.';
            $active_tab = 'totp';
        }

    // ── 2FA recovery code ────────────────────────────────────────────────────
    } elseif ($verify_type === 'recovery') {
        $active_tab  = 'recovery';
        $submitted   = strtoupper(trim($_POST['recovery_code'] ?? ''));
        $hashed_json = $user['totp_recovery_json'] ?? '';
        $hashed      = $hashed_json ? json_decode($hashed_json, true) : [];

        $matched_index = ($hashed && is_array($hashed)) ? totp_verify_recovery($submitted, $hashed) : -1;

        if ($matched_index >= 0) {
            // Burn the used recovery code.
            array_splice($hashed, $matched_index, 1);
            $pdo->prepare("UPDATE snap_users SET totp_recovery_json = ? WHERE id = ?")
                ->execute([json_encode(array_values($hashed)), $user['id']]);

            // Regenerate session ID before granting access.
            session_regenerate_id(true);
            unset($_SESSION['totp_pending_user_id'], $_SESSION['totp_fail_count']);
            $_SESSION['user_login']            = $user['username'];
            $_SESSION['user_role']             = $user['user_role'] ?: 'editor';
            $_SESSION['user_preferred_skin']   = $user['preferred_skin'] ?: null;
            $_SESSION['user_id']               = $user['id'];
            // Force a password change — burning a recovery code means something
            // went wrong with the authenticator and the admin should be aware.
            $_SESSION['force_password_change'] = true;

            header("Location: smack-change-password.php");
            exit;
        } else {
            $_SESSION['totp_fail_count']++;
            $error = 'Invalid recovery code.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication | SnapSmack Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="assets/css/admin-theme-geometry-master.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($active_skin_path); ?>">
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-box">
            <h1>SNAPSMACK</h1>
            <p style="font-size: 0.75rem; color: var(--text-muted); text-align: center; margin: -8px 0 20px; letter-spacing: 0.08em; text-transform: uppercase;">Two-Factor Authentication</p>

            <?php if ($error): ?>
                <div class="alert alert-error">&gt; <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="login-tabs">
                <button type="button" class="login-tab <?php echo $active_tab === 'totp' ? 'active' : ''; ?>"
                        data-tab="totp" onclick="switchTab('totp')">Authenticator Code</button>
                <button type="button" class="login-tab <?php echo $active_tab === 'recovery' ? 'active' : ''; ?>"
                        data-tab="recovery" onclick="switchTab('recovery')">Recovery Code</button>
            </div>

            <!-- TOTP CODE -->
            <div id="panel-totp" class="login-panel <?php echo $active_tab === 'totp' ? 'active' : ''; ?>">
                <form method="POST">
                    <input type="hidden" name="verify_type" value="totp">
                    <div class="control-group">
                        <label>6-DIGIT CODE</label>
                        <input type="text" name="totp_code" maxlength="6" pattern="\d{6}"
                               inputmode="numeric" autocomplete="one-time-code"
                               placeholder="000000"
                               <?php echo $active_tab === 'totp' ? 'autofocus' : ''; ?>
                               required>
                    </div>
                    <div class="control-group" style="margin:12px 0 18px;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.8rem;letter-spacing:0.06em;">
                            <input type="checkbox" name="trust_device" value="1" style="width:auto;margin:0;">
                            TRUST THIS DEVICE FOR <?php echo ss_totp_trust_days($pdo); ?> DAYS
                        </label>
                    </div>
                    <button type="submit" class="master-update-btn">VERIFY &amp; CONTINUE</button>
                </form>
            </div>

            <!-- 2FA RECOVERY CODE -->
            <div id="panel-recovery" class="login-panel <?php echo $active_tab === 'recovery' ? 'active' : ''; ?>">
                <form method="POST">
                    <input type="hidden" name="verify_type" value="recovery">
                    <p class="login-hint">Enter one of the recovery codes you saved when you set up 2FA. Each code works once and will be removed after use.</p>
                    <div class="control-group">
                        <label>RECOVERY CODE</label>
                        <input type="text" name="recovery_code" required autocomplete="off"
                               placeholder="XXXXXXXX-XXXXXXXX"
                               style="font-family: 'Courier New', monospace; letter-spacing: 0.1em;"
                               <?php echo $active_tab === 'recovery' ? 'autofocus' : ''; ?>>
                    </div>
                    <button type="submit" class="master-update-btn">USE RECOVERY CODE</button>
                </form>
            </div>

            <a href="snap-in.php" class="login-aux-link">&larr; BACK TO LOGIN</a>
        </div>
    </div>
    <script src="assets/js/smack-login.js"></script>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====