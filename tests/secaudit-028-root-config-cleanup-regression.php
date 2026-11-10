<?php
/**
 * SECAUDIT 028 regression — a signed update removes only the obsolete
 * webroot sc-config.php copy before SMACKBACK rebuilds its disk baseline.
 * SNAPSMACK_EOF_HEADER — preserve the canonical EOF marker below.
 */

$source  = dirname(__DIR__);
$scratch = sys_get_temp_dir() . '/snapsmack-root-config-cleanup-' . bin2hex(random_bytes(6));
mkdir($scratch . '/core', 0777, true);
mkdir($scratch . '/smack-central', 0777, true);

foreach (['updater.php', 'diaglog.php', 'release-pubkey.php'] as $file) {
    copy($source . '/core/' . $file, $scratch . '/core/' . $file);
}

define('SNAPSMACK_DIAGLOG_OFF', true);
require $scratch . '/core/updater.php';

$fail = 0;
function sa028_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    if (!$ok) $fail++;
}

try {
    $root_config = $scratch . '/sc-config.php';
    $hub_config  = $scratch . '/smack-central/sc-config.php';
    file_put_contents($root_config, 'obsolete root copy');
    file_put_contents($hub_config, 'live hub config');

    $result = updater_remove_known_orphans('0.7.992D');
    sa028_test(!file_exists($root_config), 'the exact webroot sc-config.php is removed');
    sa028_test(file_get_contents($hub_config) === 'live hub config',
        'smack-central/sc-config.php is untouched');
    sa028_test($result['removed'] === ['sc-config.php'] && $result['failed'] === [],
        'the cleanup reports only the stale root copy');

    $second = updater_remove_known_orphans('0.7.992D');
    sa028_test($second === ['removed' => [], 'failed' => []],
        'an install without the stale root copy is a harmless no-op');

    $manual = file_get_contents($source . '/smack-update.php');
    $fleet  = file_get_contents($source . '/core/multisite-api.php');
    $manual_cleanup = strpos($manual, 'updater_remove_known_orphans(');
    $manual_baseline = strpos($manual, 'smackback_init_from_disk()', $manual_cleanup ?: 0);
    sa028_test($manual_cleanup !== false && $manual_baseline !== false
        && $manual_cleanup < $manual_baseline,
        'manual update cleanup precedes the SMACKBACK disk baseline');

    $fleet_cleanup = strpos($fleet, 'updater_remove_known_orphans(');
    $fleet_baseline = strpos($fleet, 'smackback_init_from_disk()', $fleet_cleanup ?: 0);
    sa028_test($fleet_cleanup !== false && $fleet_baseline !== false
        && $fleet_cleanup < $fleet_baseline,
        'multisite dispatch cleanup precedes the SMACKBACK disk baseline');
} finally {
    if (is_dir($scratch)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($scratch);
    }
}

echo $fail === 0 ? "ALL PASS\n" : ("{$fail} FAILURE(S)\n");
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
