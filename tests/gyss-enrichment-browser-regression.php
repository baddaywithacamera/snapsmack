<?php
/**
 * GYSS enrichment results must identify photographs and use explicit checks.
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be the canonical PHP EOF marker.
 */

$root = dirname(__DIR__);
$desktop = file_get_contents($root . '/tools/gyss/gyss_qt.py');
$api = file_get_contents($root . '/core/gyss-api.php');

$checks = [
    'audit API includes filename' => strpos($api, "'filename'  => basename") !== false,
    'audit API includes posting date' => strpos($api, "'posted_date'") !== false,
    'audit thumbnails are downloaded' => strpos($desktop, 'def _audit_with_thumbs') !== false,
    'results use a thumbnail grid' => strpos($desktop, 'self.audit.setViewMode(QListWidget.IconMode)') !== false,
    'results have explicit checkboxes' => strpos($desktop, 'Qt.ItemIsUserCheckable') !== false,
    'enrichment reads checked items' => strpos($desktop, 'checkState()==Qt.Checked') !== false,
    'result identity includes photo id' => strpos($desktop, 'Photo #{x[\'id\']}') !== false,
    'prompt is collapsed by default' => strpos($desktop, 'self.prompt.hide()') !== false,
    'cost exposure is visible' => strpos($desktop, 'paid AI calls') !== false,
];

$failed = array_keys(array_filter($checks, fn($ok) => !$ok));
if ($failed) {
    fwrite(STDERR, "GYSS enrichment browser regression failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}
echo "GYSS enrichment browser regression: PASS (" . count($checks) . " checks)\n";
// ===== SNAPSMACK EOF =====
