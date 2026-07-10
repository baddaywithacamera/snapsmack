<?php
/**
 * SNAPSMACK - Fallback layout for the STANLEY skin
 * v1.0.0
 *
 * STANLEY is SMACKTALK-only. preload.php intercepts all valid requests and
 * exit()s before index.php reaches this file, so it should never render during
 * normal operation. Safe no-crash fallback: redirect to the feed.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

if (!headers_sent()) {
    $base = defined('BASE_URL') ? BASE_URL : '/';
    header('Location: ' . $base, true, 302);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>STANLEY &mdash; SMACKTALK</title></head>
<body style="background:#d5d6d7;color:#333;font-family:Georgia,serif;text-align:center;padding:4rem;">
    <h1>STANLEY</h1>
    <p>This skin requires SMACKTALK mode.</p>
    <p><a href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>" style="color:#2e6da4;">Return to front</a></p>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
