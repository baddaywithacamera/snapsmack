<?php
/**
 * SNAPSMACK - SMACKBACK redirect stub
 *
 * This file has been renamed to smack-back.php.
 * Kept as a permanent redirect so old bookmarks don't 404.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 * (Pure-PHP file — no closing tag, so the marker is a PHP comment, not <?php.)
 */

header('Location: smack-back.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
// ===== SNAPSMACK EOF =====
