<?php
/**
 * SNAPSMACK - Cron Version Checker
 * Alpha v0.7.2
 *
 * Scheduled task that checks for both core software updates AND new/updated
 * skins from the remote registry. Stores results in snap_settings so the
 * admin dashboard can display notifications without making live API calls
 * on every page load.
 *
 * USAGE:
 *   php cron-version-check.php
 *
 * RECOMMENDED CRON SCHEDULE:
 *   0 */6 * * * /usr/bin/php /path/to/cron-version-check.php >> /dev/null 2>&1
 *   (Every 6 hours)
 *
 * FALLBACK:
 *   If cron is not available, smack-admin.php performs an on-load check
 *   when the cached result is older than 24 hours.
 *
 * STORED SETTINGS:
 *   update_check_result   — JSON blob with core update + skin notifications
 *   last_update_check     — ISO datetime of last successful check
 */

// --- BOOTSTRAP (CLI-safe, no session needed) ---
$root = __DIR__;

// Load database connection
if (!file_exists("{$root}/core/db.php")) {
    fwrite(STDERR, "SnapSmack not installed (core/db.php missing). Exiting.\n");
    exit(1);
}
require_once "{$root}/core/db.php";

// Load updater engine
require_once "{$root}/core/updater.php";

// --- FETCH CURRENT INSTALLED VERSION ---
$installed_version = SNAPSMACK_VERSION_SHORT ?? '0.0';

// Also check from settings table as a fallback
try {
    $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'installed_version'");
    $stmt->execute();
    $db_version = $stmt->fetchColumn();
    if ($db_version && version_compare($db_version, $installed_version, '>')) {
        $installed_version = $db_version;
    }
} catch (PDOException $e) {
    // Use constants.php value
}

// --- CHECK CORE UPDATE ---
$release_info = updater_fetch_release_info();
$core_status = updater_check_status($installed_version, $release_info);

$core_update = null;
if ($core_status === 'update_available') {
    $core_update = [
        'version'        => $release_info['version'] ?? '',
        'version_full'   => $release_info['version_full'] ?? '',
        'released'       => $release_info['released'] ?? '',
        'changelog'      => $release_info['changelog'] ?? [],
        'schema_changes' => $release_info['schema_changes'] ?? false,
        'download_size'  => $release_info['download_size'] ?? 0,
        'requires_php'   => $release_info['requires_php'] ?? '8.0',
    ];
}

// --- CHECK SKIN REGISTRY ---
$skin_info = updater_check_skin_registry($pdo);

// --- BUILD RESULT BLOB ---
$result = [
    'checked_at'         => date('c'),
    'installed_version'  => $installed_version,
    'core_status'        => $core_status,
    'core_update'        => $core_update,
    'new_skins'          => $skin_info['new_skins'],
    'updated_skins'      => $skin_info['updated_skins'],
    'skin_notifications' => $skin_info['total_notifications'],
    'total_notifications'=> ($core_update ? 1 : 0) + $skin_info['total_notifications'],
];

// --- STORE IN DATABASE ---
try {
    $json = json_encode($result, JSON_UNESCAPED_SLASHES);

    // Upsert update_check_result
    $stmt = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('update_check_result', ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    );
    $stmt->execute([$json]);

    // Upsert last_update_check timestamp
    $stmt = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('last_update_check', ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    );
    $stmt->execute([date('Y-m-d H:i:s')]);

    // CLI output for cron logs
    $msg = "SnapSmack version check complete. ";
    $msg .= "Core: {$core_status}. ";
    $msg .= "New skins: " . count($skin_info['new_skins']) . ". ";
    $msg .= "Skin updates: " . count($skin_info['updated_skins']) . ".";
    echo $msg . "\n";

} catch (PDOException $e) {
    fwrite(STDERR, "Failed to store update check result: " . $e->getMessage() . "\n");
    exit(1);
}
