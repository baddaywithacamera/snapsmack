<?php
/** SNAPSMACK_EOF_HEADER — preserve the canonical EOF marker below. */
$source = dirname(__DIR__);
$scratch = sys_get_temp_dir() . '/snapsmack-one-shot-' . bin2hex(random_bytes(6));
mkdir($scratch . '/core', 0777, true);
foreach (['updater.php', 'diaglog.php', 'installer-cleanup.php', 'release-pubkey.php'] as $file) {
    copy($source . '/core/' . $file, $scratch . '/core/' . $file);
}
define('SNAPSMACK_DIAGLOG_OFF', true);
require $scratch . '/core/updater.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE snap_smackback_log (id INTEGER PRIMARY KEY, detected_at TEXT, resolved_at TEXT, affected_files TEXT, file_count INTEGER, resolution TEXT)');
$zipPath = $scratch . '/update.zip';
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);
foreach (['install.php', 'setup.php', 'fedup.php', 'INSTALL.PHP', 'ordinary.php'] as $file) {
    $zip->addFromString('package/' . $file, 'new package content');
}
$zip->close();
try {
    // A fresh extraction still receives all one-shot files.
    $fresh = updater_extract($zipPath);
    if (!$fresh['success'] || !is_file($scratch . '/install.php')) throw new RuntimeException('Fresh extraction lost its installer.');
    // Case variants exercise extraction refusal; pre-existing canonical files
    // model the artifacts shipped by the actual release package.
    if (is_file($scratch . '/INSTALL.PHP') && realpath($scratch . '/INSTALL.PHP') !== realpath($scratch . '/install.php')) unlink($scratch . '/INSTALL.PHP');
    file_put_contents($scratch . '/core/db.php', 'configured');
    foreach (['full', 'chunk'] as $mode) {
        foreach (['install.php', 'setup.php', 'fedup.php', 'core/.snapsmack-installer-state.php'] as $file) {
            file_put_contents($scratch . '/' . $file, 'old artifact');
        }
        $result = $mode === 'full' ? updater_extract($zipPath) : updater_extract_chunk($zipPath, 0, 60);
        if (!$result['success'] || $result['files_skipped'] !== 4
            || snapsmack_installer_artifacts($scratch)
            || file_get_contents($scratch . '/ordinary.php') !== 'new package content') {
            throw new RuntimeException('One-shot update prevention failed: ' . $mode . ' ' . json_encode($result));
        }
        $row = $pdo->query('SELECT * FROM snap_smackback_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($row['resolution'] !== 'installer_cleanup' || (int)$row['file_count'] !== 4 || !$row['resolved_at']) {
            throw new RuntimeException('Missing completed cleanup audit record: ' . $mode);
        }
    }
    echo "Installer extraction/cleanup: PASS (fresh, full, chunked, audit records)\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    rmdir($scratch);
}
// ===== SNAPSMACK EOF =====
