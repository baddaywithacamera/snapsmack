<?php
/**
 * SNAPSMACK - SMACKBACK redirect stub
 *
 * Legacy URL. Redirects to smack-back.php.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

header('Location: smack-back.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
<?php // ===== SNAPSMACK EOF =====
