<?php
/** FULL MONTY has no solo chrome; archive navigation occupies its open corner. */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$fm_archive = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'archive.php';
if ($fm_archive): ?>
<div class="fm-nav-well"><?php include dirname(__DIR__, 2) . '/core/header.php'; ?></div>
<?php endif; ?>
<?php // ===== SNAPSMACK EOF =====
