<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

require dirname(__DIR__) . '/core/shipped-review-cleanup.php';

$releaseFile = '_review/scroll-dock-icons/scroll-dock-after-1024.png';
$changedFile = '_review/scroll-dock-icons/scroll-dock-after-1440.png';
$releaseBytes = file_get_contents(dirname(__DIR__) . '/' . $releaseFile);
if (!in_array(hash('sha256', $releaseBytes), SNAPSMACK_SHIPPED_REVIEW_FILES[$releaseFile], true)) {
    throw new RuntimeException('Fixture is not a copy from the published release.');
}
$root = sys_get_temp_dir() . '/snapsmack-review-cleanup-' . bin2hex(random_bytes(8));
mkdir(dirname($root . '/' . $releaseFile), 0700, true);
mkdir($root . '/core', 0700);
file_put_contents($root . '/core/db.php', 'configured site');
file_put_contents($root . '/' . $releaseFile, $releaseBytes);
file_put_contents($root . '/' . $changedFile, 'locally edited');
file_put_contents($root . '/_review/local.txt', 'local review material');
try {
    $result = snapsmack_cleanup_shipped_review_files($root);
    if ($result !== ['removed' => [$releaseFile], 'failed' => []]
        || file_exists($root . '/' . $releaseFile)
        || file_get_contents($root . '/' . $changedFile) !== 'locally edited'
        || file_get_contents($root . '/_review/local.txt') !== 'local review material') {
        throw new RuntimeException('Only unchanged release-owned files should be removed.');
    }
    if (snapsmack_cleanup_shipped_review_files($root) !== ['removed' => [], 'failed' => []]) {
        throw new RuntimeException('Repeating the cleanup must be harmless.');
    }
    unlink($root . '/' . $changedFile);
    unlink($root . '/_review/local.txt');
    snapsmack_cleanup_shipped_review_files($root);
    if (file_exists($root . '/_review')) {
        throw new RuntimeException('Empty release review directories should be removed.');
    }
    echo "Shipped review cleanup: PASS (exact hashes, local data preserved, idempotent)\n";
} finally {
    foreach ([$releaseFile, $changedFile, '_review/local.txt', 'core/db.php'] as $path) {
        $file = $root . '/' . $path;
        if (is_file($file)) unlink($file);
    }
    foreach (['_review/scroll-dock-icons', '_review', 'core', ''] as $path) {
        $directory = $root . ($path === '' ? '' : '/' . $path);
        if (is_dir($directory)) rmdir($directory);
    }
}

// ===== SNAPSMACK EOF =====
