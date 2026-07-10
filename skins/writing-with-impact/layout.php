<?php
/**
 * SNAPSMACK - Fallback layout for the WRITING WITH IMPACT skin
 * v1.0.0
 *
 * SMACKTALK-only. preload.php intercepts all valid requests and exit()s before
 * index.php reaches this file. Safe no-crash fallback: redirect to the feed.
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
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>WRITING WITH IMPACT &mdash; SMACKTALK</title></head>
<body style="background:#e9e6dc;color:#2b2b2b;font-family:'Courier New',monospace;text-align:center;padding:4rem;">
    <h1 style="letter-spacing:.15em;">WRITING WITH IMPACT</h1>
    <p>This skin requires SMACKTALK mode.</p>
    <p><a href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>" style="color:#2b2b2b;">Return to front</a></p>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
