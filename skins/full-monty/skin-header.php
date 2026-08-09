<?php
/** FULL MONTY has no solo chrome; archive navigation occupies its open corner. */
$fm_archive = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'archive.php';
if ($fm_archive): ?>
<div class="fm-nav-well"><?php include dirname(__DIR__, 2) . '/core/header.php'; ?></div>
<?php endif; ?>
<?php // ===== SNAPSMACK EOF =====
