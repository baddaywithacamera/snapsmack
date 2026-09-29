<?php defined('SNAPSMACK_SKIN_RENDER') || exit;
/**
 * SNAPSMACK - Fallback layout for the Alfred skin
 * v1.0.0
 *
 * TILEZ is SMACKTALK-only. Core normally selects preload.php through the
 * reusable SMACKTALK controller. This inert template is only a visual fallback.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


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
