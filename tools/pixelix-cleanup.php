<?php
/** Pixelix lifecycle cleanup report/dry-run utility. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/core/db.php';
require_once dirname(__DIR__) . '/core/pixelix-lifecycle.php';
$dry = in_array('--dry-run', $argv, true);
$report = snap_pixelix_lifecycle_maintenance($pdo, dirname(__DIR__), $dry, 100);
echo ($dry ? 'DRY RUN' : 'CLEANUP') . "\n";
foreach ($report as $name => $count) echo str_pad($name, 24) . (int)$count . "\n";
// ===== SNAPSMACK EOF =====
