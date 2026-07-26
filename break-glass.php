<?php
/**
 * SNAPSMACK — Public Break-Glass recovery endpoint
 *
 * The owner uploads BREAK-GLASS-CARD.sbc to the web root by FTP, visits this
 * page, reviews the consequences, and explicitly consumes the one-use card.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/break-glass.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');

$session_dir = __DIR__ . '/data/sessions';
if (!is_dir($session_dir)) {
    @mkdir($session_dir, 0700, true);
}
if (is_dir($session_dir) && is_writable($session_dir)) {
    session_save_path($session_dir);
}
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$card_path = __DIR__ . '/' . SNAP_BREAK_GLASS_FILENAME;
$error     = '';
$verified  = null;
$success   = false;

function break_glass_read_uploaded_card(string $path): array {
    if (!is_file($path) || is_link($path)) {
        return ['ok' => false, 'error' => 'No Break-Glass Card is waiting in the site root.'];
    }
    $real = realpath($path);
    if ($real === false || dirname($real) !== __DIR__ || basename($real) !== SNAP_BREAK_GLASS_FILENAME) {
        return ['ok' => false, 'error' => 'The recovery file is not in the expected location.'];
    }
    $size = filesize($real);
    if ($size === false || $size < 100 || $size > SNAP_BREAK_GLASS_MAX_SIZE) {
        return ['ok' => false, 'error' => 'The recovery file has an invalid size.'];
    }
    $contents = file_get_contents($real);
    if ($contents === false) {
        return ['ok' => false, 'error' => 'The recovery file could not be read.'];
    }
    return ['ok' => true, 'contents' => $contents, 'fingerprint' => hash('sha256', $contents)];
}

$read = break_glass_read_uploaded_card($card_path);
if (!$read['ok']) {
    $error = $read['error'];
} else {
    $verification = break_glass_verify($pdo, $read['contents']);
    if (!$verification['ok']) {
        $error = $verification['error'];
    } else {
        $verified = $verification['payload'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $verified) {
    $csrf_ok = isset($_SESSION['break_glass_csrf'], $_POST['csrf'])
        && is_string($_POST['csrf'])
        && hash_equals($_SESSION['break_glass_csrf'], $_POST['csrf']);
    $fingerprint_ok = isset($_SESSION['break_glass_fingerprint'])
        && hash_equals($_SESSION['break_glass_fingerprint'], $read['fingerprint']);

    if (!$csrf_ok || !$fingerprint_ok) {
        $error = 'Recovery confirmation expired or the uploaded card changed. Reload and try again.';
        $verified = null;
    } elseif (($_POST['confirm'] ?? '') !== 'BURN IT') {
        $error = 'Type BURN IT exactly to confirm total-lockout recovery.';
    } else {
        try {
            $recovery_user = break_glass_consume($pdo, $verified);

            // The uploaded bearer card must not remain on disk after use.
            if (!@unlink($card_path)) {
                error_log('SNAPSMACK BREAK-GLASS: card consumed but uploaded file could not be deleted.');
            }

            // Kill every prior PHP session file. Keep this request alive long
            // enough to mint the constrained password-reset session below.
            if (is_dir($session_dir)) {
                $current_file = 'sess_' . session_id();
                foreach (new DirectoryIterator($session_dir) as $entry) {
                    if (!$entry->isFile() || !str_starts_with($entry->getFilename(), 'sess_')) {
                        continue;
                    }
                    if ($entry->getFilename() !== $current_file) {
                        @unlink($entry->getPathname());
                    }
                }
            }

            session_regenerate_id(true);
            $_SESSION = [];
            $_SESSION['user_login']            = $recovery_user['username'];
            $_SESSION['user_role']             = $recovery_user['user_role'] ?: 'admin';
            $_SESSION['user_preferred_skin']   = $recovery_user['preferred_skin'];
            $_SESSION['user_id']               = $recovery_user['id'];
            $_SESSION['force_password_change'] = true;
            $_SESSION['break_glass_recovery']  = true;
            $_SESSION['totp_enrol_required']   = true;

            unset($_SESSION['break_glass_csrf'], $_SESSION['break_glass_fingerprint']);
            header('Location: smack-change-password.php?recovery=break-glass');
            exit;
        } catch (Throwable $e) {
            error_log('SNAPSMACK BREAK-GLASS failure: ' . $e->getMessage());
            $error = 'Recovery could not be completed. The card has not been consumed.';
        }
    }
}

if ($verified) {
    $_SESSION['break_glass_csrf']        = bin2hex(random_bytes(32));
    $_SESSION['break_glass_fingerprint'] = $read['fingerprint'];
}

$site_name = break_glass_setting_get($pdo, 'site_name', 'SnapSmack');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Break the Glass | <?php echo htmlspecialchars($site_name); ?></title>
<style>
body{margin:0;background:#111;color:#eee;font:16px/1.6 system-ui,sans-serif}
main{width:min(680px,calc(100% - 36px));margin:8vh auto;padding:32px;background:#1b1b1b;border:2px solid #b33}
h1{margin-top:0;color:#ff6961;letter-spacing:.06em}code{color:#ffd27a}
.error{padding:14px;border:1px solid #b33;background:#351515;color:#ffb3b3}
.warn{padding:16px;border-left:4px solid #e6a700;background:#2b2412}
label{display:block;margin:22px 0 8px;font-weight:700}
input{width:100%;box-sizing:border-box;padding:12px;background:#090909;color:#fff;border:1px solid #666}
button{margin-top:18px;padding:13px 20px;background:#b33;color:#fff;border:0;font-weight:800;cursor:pointer}
a{color:#9fd5ff}
</style>
</head>
<body>
<main>
<h1>BREAK THE GLASS</h1>
<?php if ($error): ?>
    <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <p>Put the genuine <code><?php echo SNAP_BREAK_GLASS_FILENAME; ?></code> in this site&rsquo;s web root without renaming it, then reload this page.</p>
<?php elseif ($verified): ?>
    <p>The card&rsquo;s signature is valid and it belongs to this installation.</p>
    <div class="warn">
        <strong>This is deliberately destructive.</strong> Continuing revokes the current password,
        authenticator, recovery codes, trusted devices, password-reset links, old sessions, and this
        card. You will immediately create a new password and enrol 2FA again.
    </div>
    <p>Site: <strong><?php echo htmlspecialchars((string)$verified['site_name']); ?></strong><br>
       Account: <strong><?php echo htmlspecialchars((string)$verified['username']); ?></strong><br>
       Card made: <strong><?php echo htmlspecialchars((string)$verified['generated_at']); ?></strong></p>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['break_glass_csrf']); ?>">
        <label for="confirm">Type <code>BURN IT</code> to use and destroy this card</label>
        <input id="confirm" name="confirm" required autocomplete="off">
        <button type="submit">USE THE ONE-TIME CARD</button>
    </form>
<?php endif; ?>
</main>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
