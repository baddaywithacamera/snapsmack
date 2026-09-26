<?php
/** Registry skin replacements must be transactional and ownership-tolerant. */
$root = dirname(__DIR__);
$source = (string)file_get_contents($root . '/core/skin-registry.php');

$checks = [
    'moves the installed directory aside before replacement' => str_contains($source, '@rename($target_dir, $candidate)'),
    'keeps an explicit rollback path' => str_contains($source, '$restore_retired = static function'),
    'restores the previous directory after copy failure' => substr_count($source, '$restore_retired();') >= 2,
    'validates the new manifest before retiring the rollback copy' => strpos($source, 'hash_equals($expected_manifest_hash') < strpos($source, '$cleanup_pending ='),
    'reports retained read-only cleanup without failing the valid install' => str_contains($source, 'previous read-only directory was retained'),
];

foreach ($checks as $message => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

echo "PASS: transactional skin registry replacement\n";
// ===== SNAPSMACK EOF =====
