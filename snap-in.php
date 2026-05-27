<?php
/**
 * SNAPSMACK - Admin login portal
 *
 * Reached only through the configured login slug (default: /snap-in).
 * Direct access to snap-in.php is blocked except via a pre-shared recovery
 * token (?key=TOKEN), which redirects to the real login slug.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/db.php';
require_once 'core/auth-recovery.php';
require_once 'core/totp.php';

// ─────────────────────────────────────────────────────────────────────────────
// LOGIN PROTECTION HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resolve the real client IP, trusting X-Forwarded-For only for the first
 * hop (Cloudflare / reverse proxy). Falls back to REMOTE_ADDR.
 *
 * ASSUMPTION: single reverse proxy in front of PHP (Cloudflare or one Nginx).
 * X-Forwarded-For is: <client>, <proxy1>, ...
 * We trust the leftmost value, which Cloudflare sets to the real client IP.
 * If a second untrusted proxy is ever added upstream this trust model breaks —
 * revisit by validating against Cloudflare's published IP ranges instead.
 */
function snap_client_ip(): string {
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd !== '') {
        $first = trim(explode(',', $fwd)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP)
        ? $_SERVER['REMOTE_ADDR']
        : '0.0.0.0';
}

/**
 * Record a failed login attempt for the given IP.
 * If the failure count reaches the threshold within the window, auto-ban
 * the IP for 7 days and clear the rate limit counter.
 *
 * Threshold : 5 failures
 * Window    : 10 minutes
 * Ban length: 7 days
 */
function snap_record_login_failure(PDO $pdo, string $ip): void {
    // Upsert failure counter — reset if the existing window is stale
    $pdo->prepare(
        "INSERT INTO snap_rate_limits (ip, action, count, window_start)
         VALUES (?, 'login_fail', 1, NOW())
         ON DUPLICATE KEY UPDATE
           count        = IF(window_start < DATE_SUB(NOW(), INTERVAL 10 MINUTE), 1, count + 1),
           window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL 10 MINUTE), NOW(), window_start)"
    )->execute([$ip]);

    // Fetch current count within the active window
    $row = $pdo->prepare(
        "SELECT count FROM snap_rate_limits
         WHERE ip = ? AND action = 'login_fail'
           AND window_start >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
    );
    $row->execute([$ip]);
    $fail_count = (int)($row->fetchColumn() ?: 0);

    if ($fail_count >= 5) {
        // Issue a 7-day ban; ON DUPLICATE resets/extends an existing ban
        $pdo->prepare(
            "INSERT INTO snap_ip_bans (ip, reason, banned_at, expires_at)
             VALUES (?, 'auto:brute_force', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))
             ON DUPLICATE KEY UPDATE
               reason = VALUES(reason), banned_at = NOW(), expires_at = VALUES(expires_at)"
        )->execute([$ip]);
        // Clear the counter so it doesn't re-fire on every subsequent page hit
        $pdo->prepare(
            "DELETE FROM snap_rate_limits WHERE ip = ? AND action = 'login_fail'"
        )->execute([$ip]);
    }
}

// --- DIRECT ACCESS PROTECTION ---
// If the request URI ends in snap-in.php, the user bypassed the slug rewrite.
// Allow only if a valid recovery key is provided — redirect them to the slug.
// Everyone else gets a 403.
$_snap_uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
if (preg_match('#/snap-in\.php$#i', $_snap_uri)) {
    $provided = trim($_GET['key'] ?? '');
    if ($provided !== '') {
        $recovery_key = $pdo->query(
            "SELECT setting_val FROM snap_settings WHERE setting_key = 'login_recovery_key' LIMIT 1"
        )->fetchColumn();
        $login_slug = $pdo->query(
            "SELECT setting_val FROM snap_settings WHERE setting_key = 'login_slug' LIMIT 1"
        )->fetchColumn() ?: 'snap-in';
        if ($recovery_key && hash_equals((string)$recovery_key, $provided)) {
            header('Location: /' . ltrim($login_slug, '/'));
            exit;
        }
    }
    http_response_code(403);
    exit;
}

// --- USER-AGENT FILTER ---
// Reject blank or obviously scripted UAs — no real browser omits a UA or
// identifies itself as curl/python/etc. Do this silently (403, no body).
$_snap_ua = trim($_SERVER['HTTP_USER_AGENT'] ?? '');
$_ua_bot_rx = '#^$|curl/|python-?requests?/|python/\d|Go-http-client/|'
            . 'libwww-perl/|Wget/|Scrapy/|mechanize|Java/\d|Nikto|'
            . 'masscan|sqlmap|Nmap|DirBuster|zgrab|Hydra|WPScan|'
            . 'nuclei|zgrab|dirsearch|gobuster|ffuf#i';
if (preg_match($_ua_bot_rx, $_snap_ua)) {
    http_response_code(403);
    exit;
}
unset($_ua_bot_rx);

// --- IP BAN GATE ---
// Resolve client IP and check for an active ban before doing anything else.
$_snap_ip = snap_client_ip();
try {
    $_ban_check = $pdo->prepare(
        "SELECT expires_at FROM snap_ip_bans WHERE ip = ? AND expires_at > NOW() LIMIT 1"
    );
    $_ban_check->execute([$_snap_ip]);
    if ($_ban_check->fetchColumn()) {
        http_response_code(403);
        exit;
    }
} catch (PDOException $e) {
    // Table may not exist yet on older installs — fail open so login still works
}
unset($_ban_check);

// --- SESSION INITIALIZATION ---
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

// --- REDIRECT FOR EXISTING SESSIONS ---
if (isset($_SESSION['user_login'])) {
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

$error      = '';
$active_tab = 'password';

if (($_GET['err'] ?? '') === '2fa_locked') {
    $error = 'Too many failed 2FA attempts. Please log in again.';
}

// --- AUTHENTICATION HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_type = $_POST['login_type'] ?? 'password';

    if ($login_type === 'password') {
        $active_tab = 'password';
        $user_input = trim($_POST['username'] ?? '');
        $pass_input = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT id, username, password_hash, user_role, preferred_skin, force_password_change, totp_enabled, totp_secret FROM snap_users WHERE username = ?");
        $stmt->execute([$user_input]);
        $user = $stmt->fetch();

        if ($user && password_verify($pass_input, $user['password_hash'])) {
            if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
                // Check for a valid long-lived TOTP trust cookie before sending
                // the user through the interstitial. If the cookie is good, skip
                // 2FA entirely and grant the session directly.
                $ss_trust_skip = false;
                $ss_trust_token = $_COOKIE['ss_totp_trust'] ?? '';
                if (strlen($ss_trust_token) === 64) {
                    $ss_trust_hash = hash('sha256', $ss_trust_token);
                    try {
                        $ss_trust_row = $pdo->prepare(
                            "SELECT id FROM snap_totp_devices
                             WHERE user_id = ? AND token_hash = ? AND expires_at > NOW() LIMIT 1"
                        );
                        $ss_trust_row->execute([$user['id'], $ss_trust_hash]);
                        $ss_trust_skip = (bool)$ss_trust_row->fetchColumn();
                    } catch (PDOException $e) { /* table may not exist yet on older installs */ }
                }

                if ($ss_trust_skip) {
                    // Trusted device -- grant full session without TOTP.
                    session_regenerate_id(true);
                    $_SESSION['user_login']          = $user['username'];
                    $_SESSION['user_role']           = $user['user_role'] ?: 'editor';
                    $_SESSION['user_preferred_skin'] = $user['preferred_skin'] ?: null;
                    $_SESSION['user_id']             = $user['id'];
                    if (!empty($user['force_password_change'])) {
                        $_SESSION['force_password_change'] = true;
                        header('Location: smack-change-password.php');
                        exit;
                    }
                    header('Location: smack-admin.php');
                    exit;
                }

                session_regenerate_id(true);
                $_SESSION['totp_pending_user_id'] = $user['id'];
                header("Location: smack-2fa-verify.php");
                exit;
            }

            session_regenerate_id(true);
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
        } else {
            $error = "ACCESS DENIED: Invalid credentials.";
            try { snap_record_login_failure($pdo, $_snap_ip); } catch (PDOException $e) {}
        }

    } elseif ($login_type === 'recovery') {
        $active_tab = 'recovery';
        $rec_user   = trim($_POST['rec_username'] ?? '');
        $rec_code   = strtoupper(trim($_POST['rec_code'] ?? ''));

        $user = snapsmack_validate_recovery_code($pdo, $rec_user, $rec_code);

        if ($user) {
            snapsmack_consume_recovery_code($pdo, $user['id']);
            session_regenerate_id(true);
            $_SESSION['user_login']            = $user['username'];
            $_SESSION['user_role']             = $user['user_role'] ?: 'editor';
            $_SESSION['user_preferred_skin']   = $user['preferred_skin'] ?: null;
            $_SESSION['user_id']               = $user['id'];
            $_SESSION['force_password_change'] = true;
            header("Location: smack-change-password.php");
            exit;
        } else {
            $error = "ACCESS DENIED: Invalid username or recovery code.";
            try { snap_record_login_failure($pdo, $_snap_ip); } catch (PDOException $e) {}
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | SnapSmack Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="assets/css/admin-theme-geometry-master.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($active_skin_path); ?>">
</head>
<body class="login-page">

<div class="login-wrap">
    <div class="login-box">

        <div class="login-logo">
            <span class="logo-text">SNAPSMACK</span>
        </div>

        <?php if ($error): ?>
        <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="login-tabs">
            <button class="login-tab <?php echo $active_tab === 'password' ? 'active' : ''; ?>"
                    data-tab="login-password">PASSWORD</button>
            <button class="login-tab <?php echo $active_tab === 'recovery' ? 'active' : ''; ?>"
                    data-tab="login-recovery">RECOVERY CODE</button>
        </div>

        <!-- PASSWORD TAB -->
        <div class="login-panel <?php echo $active_tab === 'password' ? 'active' : ''; ?>" id="login-password">
            <form method="POST" action="" autocomplete="off">
                <input type="hidden" name="login_type" value="password">

                <div class="control-group">
                    <label for="username">IDENTIFIER</label>
                    <input type="text" id="username" name="username"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                           autocomplete="username" required>
                </div>

                <div class="control-group">
                    <label for="password">PASSCODE</label>
                    <input type="password" id="password" name="password"
                           autocomplete="current-password" required>
                </div>

                <button type="submit" class="btn-smack btn-login">AUTHORIZE ACCESS</button>
            </form>
        </div>

        <!-- RECOVERY CODE TAB -->
        <div class="login-panel <?php echo $active_tab === 'recovery' ? 'active' : ''; ?>" id="login-recovery">
            <form method="POST" action="" autocomplete="off">
                <input type="hidden" name="login_type" value="recovery">

                <div class="control-group">
                    <label for="rec_username">IDENTIFIER</label>
                    <input type="text" id="rec_username" name="rec_username"
                           value="<?php echo htmlspecialchars($_POST['rec_username'] ?? ''); ?>"
                           autocomplete="username" required>
                </div>

                <div class="control-group">
                    <label for="rec_code">RECOVERY CODE</label>
                    <input type="text" id="rec_code" name="rec_code"
                           placeholder="XXXX-XXXX-XXXX"
                           autocomplete="off" required>
                    <span class="field-tip" data-tip="Find your one-time recovery code in smack-recovery-codes.txt, generated when you enabled 2FA.">&#9432;</span>
                </div>

                <button type="submit" class="btn-smack btn-login">AUTHORIZE ACCESS</button>
            </form>
        </div>

        <div class="login-aux">
            <a href="snap-in.php?tab=recovery" class="login-aux-link">Forgot password?</a>
        </div>

        <div style="text-align:center; margin-top:14px; padding-top:14px; border-top:1px solid var(--border,rgba(255,255,255,0.08));">
            <a href="/" class="login-aux-link">&larr; RETURN TO SITE</a>
    </div><!-- /.login-box -->

</div><!-- /.login-wrap -->

<script>
(function () {
    document.querySelectorAll('.login-tabs .login-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tab = this.dataset.tab;
            document.querySelectorAll('.login-tabs .login-tab').forEach(function (b) {
                b.classList.toggle('active', b.dataset.tab === tab);
            });
            document.querySelectorAll('.login-panel').forEach(function (p) {
                p.classList.toggle('active', p.id === tab);
            });
        });
    });
}());
</script>

</body>
</html>
<?php // ===== SNAPSMACK EOF =====
