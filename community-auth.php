<?php
/**
 * SNAPSMACK - Community Authentication
 * Alpha v0.7.3
 *
 * Public-facing signup, login, logout, and password reset for community
 * (visitor) accounts. Uses snap_community_users and snap_community_sessions.
 *
 * Routes via ?action= query param:
 *   login    — login form (default)
 *   signup   — registration form
 *   logout   — destroys session and redirects
 *   reset    — password reset request form
 *   reset-confirm — password reset confirm form (requires ?token=)
 *   verify   — email verification (requires ?token=)
 */

require_once 'core/db.php';
require_once 'core/community-session.php';

// --- SETTINGS ---
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                ->fetchAll(PDO::FETCH_KEY_PAIR);

$action = $_GET['action'] ?? 'login';

// Already logged in — nothing to do here except logout
if ($action !== 'logout' && $action !== 'verify') {
    $community_user = community_current_user();
    if ($community_user) {
        $redirect = $_GET['redirect'] ?? '/';
        header('Location: ' . $redirect);
        exit;
    }
}

$error   = null;
$success = null;

// ============================================================================
// ACTION: LOGOUT
// ============================================================================
if ($action === 'logout') {
    community_logout();
    header('Location: /?status=signed-out');
    exit;
}

// ============================================================================
// ACTION: VERIFY EMAIL
// ============================================================================
if ($action === 'verify') {
    $token = trim($_GET['token'] ?? '');
    if ($token) {
        $stmt = $pdo->prepare("
            SELECT t.id, t.user_id, t.expires_at, t.used_at
            FROM snap_community_tokens t
            WHERE t.token = ? AND t.type = 'verify_email'
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $tok = $stmt->fetch();

        if (!$tok) {
            $error = "Invalid verification link.";
        } elseif ($tok['used_at']) {
            $success = "Your email is already verified. You can sign in.";
        } elseif ($tok['expires_at'] < date('Y-m-d H:i:s')) {
            $error = "This verification link has expired. Please sign up again or request a new link.";
        } else {
            $pdo->prepare("UPDATE snap_community_users SET email_verified = 1, status = 'active' WHERE id = ?")
                ->execute([$tok['user_id']]);
            $pdo->prepare("UPDATE snap_community_tokens SET used_at = NOW() WHERE id = ?")
                ->execute([$tok['id']]);
            // Auto-login after verification
            community_login($tok['user_id']);
            header('Location: /?status=welcome');
            exit;
        }
    } else {
        $error = "No verification token provided.";
    }
}

// ============================================================================
// ACTION: SIGNUP — POST handler
// ============================================================================
if ($action === 'signup' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!community_rate_limit('signups')) {
        $error = "Too many signup attempts from this connection. Try again later.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm'] ?? '';

        // Validation
        if (empty($username) || empty($email) || empty($password)) {
            $error = "All fields are required.";
        } elseif (!preg_match('/^[a-zA-Z0-9_\-]{3,30}$/', $username)) {
            $error = "Username must be 3–30 characters: letters, numbers, underscores, hyphens.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Enter a valid email address.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters.";
        } elseif ($password !== $confirm) {
            $error = "Passwords do not match.";
        } else {
            // Check uniqueness
            $existing = $pdo->prepare("SELECT id FROM snap_community_users WHERE username = ? OR email = ? LIMIT 1");
            $existing->execute([$username, $email]);
            if ($existing->fetch()) {
                $error = "That username or email is already registered.";
            } else {
                // Create account
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $require_verify = community_setting('community_require_verification', '1') === '1';
                $initial_status = $require_verify ? 'unverified' : 'active';

                $pdo->prepare("
                    INSERT INTO snap_community_users (username, email, password_hash, status, email_verified)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$username, $email, $hash, $initial_status, $require_verify ? 0 : 1]);

                $new_user_id = (int)$pdo->lastInsertId();

                if ($require_verify) {
                    // Issue verification token
                    $verify_token = community_generate_token();
                    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    $pdo->prepare("
                        INSERT INTO snap_community_tokens (user_id, token, type, expires_at)
                        VALUES (?, ?, 'verify_email', ?)
                    ")->execute([$new_user_id, $verify_token, $expires]);

                    community_send_verification_email($email, $username, $verify_token, $settings);
                    $action  = 'signup-pending';
                    $success = "Account created. Check your email to verify your address before signing in.";
                } else {
                    // Verification not required — log in directly
                    community_login($new_user_id);
                    header('Location: /?status=welcome');
                    exit;
                }
            }
        }
    }
}

// ============================================================================
// ACTION: LOGIN — POST handler
// ============================================================================
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!community_rate_limit('logins')) {
        $error = "Too many login attempts. Try again later.";
    } else {
        $identifier = trim($_POST['identifier'] ?? '');  // username or email
        $password   = $_POST['password'] ?? '';
        $redirect   = $_POST['redirect'] ?? '/';

        if (empty($identifier) || empty($password)) {
            $error = "Enter your username (or email) and password.";
        } else {
            $stmt = $pdo->prepare("
                SELECT id, username, password_hash, status, email_verified
                FROM snap_community_users
                WHERE username = ? OR email = ?
                LIMIT 1
            ");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $error = "Incorrect credentials.";
            } elseif ($user['status'] === 'suspended') {
                $error = "This account has been suspended.";
            } elseif ($user['status'] === 'unverified') {
                $error = "Please verify your email before signing in. Check your inbox for the verification link.";
            } else {
                community_login((int)$user['id']);
                header('Location: ' . (filter_var($redirect, FILTER_VALIDATE_URL) ? $redirect : '/'));
                exit;
            }
        }
    }
}

// ============================================================================
// ACTION: RESET REQUEST — POST handler
// ============================================================================
if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!community_rate_limit('resets')) {
        $error = "Too many reset attempts. Try again later.";
    } else {
        $email = trim($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Enter a valid email address.";
        } else {
            // Always show success to prevent email enumeration
            $stmt = $pdo->prepare("SELECT id, username FROM snap_community_users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $reset_token = community_generate_token();
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $pdo->prepare("
                    INSERT INTO snap_community_tokens (user_id, token, type, expires_at)
                    VALUES (?, ?, 'reset_password', ?)
                ")->execute([$user['id'], $reset_token, $expires]);

                community_send_reset_email($email, $user['username'], $reset_token, $settings);
            }

            $success = "If that email is registered, a password reset link is on its way.";
        }
    }
}

// ============================================================================
// ACTION: RESET CONFIRM — POST handler
// ============================================================================
if ($action === 'reset-confirm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = trim($_POST['token'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (empty($token)) {
        $error = "Invalid reset link.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $stmt = $pdo->prepare("
            SELECT t.id, t.user_id, t.expires_at, t.used_at
            FROM snap_community_tokens t
            WHERE t.token = ? AND t.type = 'reset_password'
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $tok = $stmt->fetch();

        if (!$tok || $tok['used_at']) {
            $error = "This reset link is invalid or has already been used.";
        } elseif ($tok['expires_at'] < date('Y-m-d H:i:s')) {
            $error = "This reset link has expired. Request a new one.";
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("UPDATE snap_community_users SET password_hash = ? WHERE id = ?")
                ->execute([$hash, $tok['user_id']]);
            $pdo->prepare("UPDATE snap_community_tokens SET used_at = NOW() WHERE id = ?")
                ->execute([$tok['id']]);

            // Log out all other sessions
            community_logout_all((int)$tok['user_id']);

            // Log in automatically
            community_login((int)$tok['user_id']);
            header('Location: /?status=password-reset');
            exit;
        }
    }
}


// ============================================================================
// EMAIL HELPERS
// ============================================================================

function community_send_verification_email(string $email, string $username, string $token, array $settings): void {
    $from      = $settings['community_email_from']      ?? $settings['site_email'] ?? '';
    $from_name = $settings['community_email_from_name'] ?? ($settings['site_name'] ?? 'SnapSmack');
    $site_url  = rtrim($settings['site_url'] ?? '', '/');
    $link      = $site_url . '/community-auth.php?action=verify&token=' . urlencode($token);

    $subject = "Verify your SnapSmack account";
    $body    = "Hi {$username},\r\n\r\n"
             . "Verify your email to activate your account:\r\n"
             . $link . "\r\n\r\n"
             . "This link expires in 24 hours.\r\n\r\n"
             . "— " . $from_name;

    $headers = "From: {$from_name} <{$from}>\r\nContent-Type: text/plain; charset=UTF-8";
    if ($from) {
        @mail($email, $subject, $body, $headers);
    }
}

function community_send_reset_email(string $email, string $username, string $token, array $settings): void {
    $from      = $settings['community_email_from']      ?? $settings['site_email'] ?? '';
    $from_name = $settings['community_email_from_name'] ?? ($settings['site_name'] ?? 'SnapSmack');
    $site_url  = rtrim($settings['site_url'] ?? '', '/');
    $link      = $site_url . '/community-auth.php?action=reset-confirm&token=' . urlencode($token);

    $subject = "Reset your SnapSmack password";
    $body    = "Hi {$username},\r\n\r\n"
             . "Reset your password here:\r\n"
             . $link . "\r\n\r\n"
             . "This link expires in 1 hour. If you didn't request this, ignore this email.\r\n\r\n"
             . "— " . $from_name;

    $headers = "From: {$from_name} <{$from}>\r\nContent-Type: text/plain; charset=UTF-8";
    if ($from) {
        @mail($email, $subject, $body, $headers);
    }
}


// ============================================================================
// RENDER
// ============================================================================
$redirect_param = htmlspecialchars($_GET['redirect'] ?? $_POST['redirect'] ?? '/');
$reset_token_param = htmlspecialchars($_GET['token'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php
        $titles = [
            'login'         => 'Sign In',
            'signup'        => 'Create Account',
            'signup-pending'=> 'Check Your Email',
            'reset'         => 'Reset Password',
            'reset-confirm' => 'Set New Password',
            'verify'        => 'Email Verification',
        ];
        echo htmlspecialchars($titles[$action] ?? 'Sign In');
        echo ' | ' . htmlspecialchars($settings['site_name'] ?? 'SnapSmack');
        ?>
    </title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: #0d0d0d;
            color: #e0e0e0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .auth-wrap {
            width: 100%;
            max-width: 420px;
        }

        .auth-site-name {
            text-align: center;
            margin-bottom: 32px;
        }

        .auth-site-name a {
            color: #fff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            opacity: 0.5;
        }

        .auth-site-name a:hover { opacity: 1; }

        .auth-box {
            background: #161616;
            border: 1px solid #2a2a2a;
            padding: 36px 32px;
        }

        .auth-box h2 {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: #fff;
            margin-bottom: 28px;
        }

        .field {
            margin-bottom: 18px;
        }

        .field label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 6px;
        }

        .field input {
            width: 100%;
            background: #0d0d0d;
            border: 1px solid #333;
            color: #e0e0e0;
            padding: 10px 12px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            outline: none;
            transition: border-color 0.15s;
        }

        .field input:focus { border-color: #666; }

        .field .hint {
            font-size: 11px;
            color: #555;
            margin-top: 5px;
        }

        .btn-auth {
            width: 100%;
            background: #e0e0e0;
            color: #0d0d0d;
            border: none;
            padding: 12px;
            font-family: 'Inter', sans-serif;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            cursor: pointer;
            margin-top: 8px;
            transition: background 0.15s;
        }

        .btn-auth:hover { background: #fff; }

        .auth-msg {
            font-size: 12px;
            padding: 12px;
            margin-bottom: 20px;
            border-left: 3px solid;
        }

        .auth-msg.error   { border-color: #c0392b; color: #e07070; background: #1a0808; }
        .auth-msg.success { border-color: #27ae60; color: #70c090; background: #081a0d; }

        .auth-links {
            margin-top: 24px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            border-top: 1px solid #222;
            padding-top: 20px;
        }

        .auth-links a {
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #666;
            text-decoration: none;
            transition: color 0.15s;
        }

        .auth-links a:hover { color: #ccc; }

        .auth-links .sep { color: #333; margin: 0 6px; }

        @media (max-width: 480px) {
            .auth-box { padding: 28px 20px; }
        }
    </style>
</head>
<body>

<div class="auth-wrap">

    <div class="auth-site-name">
        <a href="/">&larr; <?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?></a>
    </div>

    <div class="auth-box">

        <?php if ($error): ?>
            <div class="auth-msg error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="auth-msg success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>


        <?php // ================================================================
              // LOGIN FORM
              // ============================================================ ?>
        <?php if ($action === 'login'): ?>

            <h2>Sign In</h2>
            <form method="POST" action="/community-auth.php?action=login">
                <input type="hidden" name="redirect" value="<?php echo $redirect_param; ?>">
                <div class="field">
                    <label>Username or Email</label>
                    <input type="text" name="identifier" required autofocus autocomplete="username"
                           value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>">
                </div>
                <div class="field">
                    <label>Password</label>
                    <input type="password" name="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn-auth">Sign In</button>
            </form>

            <div class="auth-links">
                <div>
                    <a href="/community-auth.php?action=signup&redirect=<?php echo urlencode($redirect_param); ?>">Create an account</a>
                    <span class="sep">|</span>
                    <a href="/community-auth.php?action=reset">Forgot password</a>
                </div>
            </div>


        <?php // ================================================================
              // SIGNUP FORM
              // ============================================================ ?>
        <?php elseif ($action === 'signup'): ?>

            <h2>Create Account</h2>
            <form method="POST" action="/community-auth.php?action=signup">
                <input type="hidden" name="redirect" value="<?php echo $redirect_param; ?>">
                <div class="field">
                    <label>Username</label>
                    <input type="text" name="username" required autofocus autocomplete="username"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    <div class="hint">3–30 characters. Letters, numbers, _ and - only.</div>
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" name="email" required autocomplete="email"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    <div class="hint">Used for verification and password recovery only.</div>
                </div>
                <div class="field">
                    <label>Password</label>
                    <input type="password" name="password" required autocomplete="new-password">
                    <div class="hint">Minimum 8 characters.</div>
                </div>
                <div class="field">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn-auth">Create Account</button>
            </form>

            <div class="auth-links">
                <div>
                    Already have an account?
                    <a href="/community-auth.php?action=login&redirect=<?php echo urlencode($redirect_param); ?>">Sign in</a>
                </div>
            </div>


        <?php // ================================================================
              // SIGNUP PENDING (email verification sent)
              // ============================================================ ?>
        <?php elseif ($action === 'signup-pending'): ?>

            <h2>Check Your Email</h2>
            <p style="font-size:14px; line-height:1.6; color:#aaa;">
                A verification link is on its way. Click it to activate your account and start liking and commenting.
            </p>
            <div class="auth-links">
                <a href="/community-auth.php?action=login">Back to sign in</a>
            </div>


        <?php // ================================================================
              // PASSWORD RESET REQUEST
              // ============================================================ ?>
        <?php elseif ($action === 'reset'): ?>

            <h2>Reset Password</h2>
            <?php if (!$success): ?>
                <form method="POST" action="/community-auth.php?action=reset">
                    <div class="field">
                        <label>Email Address</label>
                        <input type="email" name="email" required autofocus autocomplete="email">
                    </div>
                    <button type="submit" class="btn-auth">Send Reset Link</button>
                </form>
            <?php endif; ?>
            <div class="auth-links">
                <a href="/community-auth.php?action=login">Back to sign in</a>
            </div>


        <?php // ================================================================
              // PASSWORD RESET CONFIRM
              // ============================================================ ?>
        <?php elseif ($action === 'reset-confirm'): ?>

            <h2>Set New Password</h2>
            <?php if (!$success && $reset_token_param): ?>
                <form method="POST" action="/community-auth.php?action=reset-confirm">
                    <input type="hidden" name="token" value="<?php echo $reset_token_param; ?>">
                    <div class="field">
                        <label>New Password</label>
                        <input type="password" name="password" required autofocus autocomplete="new-password">
                        <div class="hint">Minimum 8 characters.</div>
                    </div>
                    <div class="field">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn-auth">Set Password</button>
                </form>
            <?php endif; ?>
            <div class="auth-links">
                <a href="/community-auth.php?action=login">Back to sign in</a>
            </div>


        <?php // ================================================================
              // EMAIL VERIFICATION RESULT
              // ============================================================ ?>
        <?php elseif ($action === 'verify'): ?>

            <h2>Email Verification</h2>
            <div class="auth-links">
                <a href="/community-auth.php?action=login">Sign in</a>
            </div>

        <?php endif; ?>

    </div><!-- /.auth-box -->

</div><!-- /.auth-wrap -->

</body>
</html>
