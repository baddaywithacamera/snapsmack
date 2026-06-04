<?php
/**
 * SNAPSMACK - One-time site_mode seeder
 *
 * Seeds site_mode into snap_settings if the key doesn't exist.
 * Run once, then delete this file.
 *
 * Usage:  php fix-site-mode.php [photoblog|carousel|smacktalk]
 *         Default: photoblog
 *
 * Or drop on the server and hit it in a browser.
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────
define('BASE_URL', '');
if (!file_exists(__DIR__ . '/../core/db.php')) {
    die("Run from tools/ or adjust the path to core/db.php.\n");
}
require_once __DIR__ . '/../core/db.php';

// ── Determine desired mode ────────────────────────────────────────────────────
$valid = ['photoblog', 'carousel', 'smacktalk'];
$mode  = 'photoblog'; // safe default

if (php_sapi_name() === 'cli') {
    $mode = $argv[1] ?? 'photoblog';
} else {
    $mode = $_GET['mode'] ?? $_POST['mode'] ?? 'photoblog';
}

if (!in_array($mode, $valid, true)) {
    die("Invalid mode. Use: photoblog, carousel, or smacktalk.\n");
}

// ── Apply ─────────────────────────────────────────────────────────────────────
$existing = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'site_mode'");
$existing->execute();
$current  = $existing->fetchColumn();

if ($current !== false) {
    echo "site_mode already set to '{$current}'. ";
    if ($current === $mode) {
        echo "Nothing to do.\n";
        exit;
    }
    echo "Updating to '{$mode}'...\n";
    $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'site_mode'")->execute([$mode]);
} else {
    echo "site_mode not set. Inserting '{$mode}'...\n";
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('site_mode', ?)")->execute([$mode]);
}

echo "Done. site_mode = '{$mode}'. DELETE THIS FILE.\n";
