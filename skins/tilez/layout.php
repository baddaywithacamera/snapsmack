<?php
/**
 * SNAPSMACK - Fallback layout for the Alfred skin
 * v1.0.0
 *
 * Alfred is SmackTalk-only. preload.php intercepts all valid Alfred requests
 * and exit()s before index.php reaches this file. layout.php should therefore
 * never be called during normal operation.
 *
 * If it IS called, the most likely cause is that someone selected Alfred as
 * their skin but hasn't switched the site to SmackTalk mode, or preload.php
 * fell through on an unrecognised request. Redirect to the feed.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// Redirect to the homepage feed — safest no-crash fallback.
if (!headers_sent()) {
    $base = defined('BASE_URL') ? BASE_URL : '/';
    header('Location: ' . $base, true, 302);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ALFRED &mdash; SMACKTALK</title>
</head>
<body style="background:#1d1d1d;color:#fff;font-family:sans-serif;text-align:center;padding:4rem;">
    <h1 style="text-transform:uppercase;letter-spacing:.1em;">ALFRED</h1>
    <p style="color:#999;">This skin requires SMACKTALK mode.</p>
    <p><a href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>" style="color:#1e73be;">Return to front</a></p>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
