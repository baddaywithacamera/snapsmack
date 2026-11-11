<?php
/**
 * SNAPSMACK - Cron Version Checker
 *
 * Scheduled task that checks for both core software updates AND new/updated
 * skins from the remote registry. Stores results in snap_settings so the
 * admin dashboard can display notifications without making live API calls
 * on every page load. Also runs the scheduled SMACKBACK integrity verify.
 *
 * USAGE:
 *   php cron-version-check.php
 *
 * RECOMMENDED CRON SCHEDULE (every 6 hours; explicit hours are used instead of
 * "0 [slash]6 * * *" because a literal star-slash would close this docblock):
 *   0 0,6,12,18 * * *  /usr/bin/php /path/to/cron-version-check.php >> /dev/null 2>&1
 *
 * FALLBACK:
 *   If cron is not available, smack-admin.php performs an on-load check
 *   when the cached result is older than 24 hours.
 *
 * STORED SETTINGS:
 *   update_check_result   — JSON blob with core update + skin notifications
 *   last_update_check     — ISO datetime of last successful check
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// --- BOOTSTRAP (CLI-safe, no session needed) ---
$root = __DIR__;

// Load database connection
if (!file_exists("{$root}/core/db.php")) {
    fwrite(STDERR, "SnapSmack not installed (core/db.php missing). Exiting.\n");
    exit(1);
}
require_once "{$root}/core/db.php";
require_once "{$root}/core/cron-register.php";
cron_stamp_scheduler_fire($pdo, 'version_check');   // "did cron fire" — the only truth

// Load updater engine
require_once "{$root}/core/updater.php";

// Fallback for sites upgrading from < 0.7.4 where constants.php
// doesn't yet define snap_version_compare().
if (!function_exists('snap_version_compare')) {
    function snap_version_compare(string $v1, string $v2, string $op = '>'): bool {
        $normalise = function (string $v): string {
            if (preg_match('/^(\d+(?:\.\d+)*)([a-z])$/i', $v, $m)) {
                return $m[1] . '.' . (ord(strtolower($m[2])) - ord('a') + 1);
            }
            return $v . '.0';
        };
        return version_compare($normalise($v1), $normalise($v2), $op);
    }
}

// --- FETCH CURRENT INSTALLED VERSION ---
$installed_version = SNAPSMACK_VERSION_SHORT ?? '0.0';

// Also check from settings table as a fallback
try {
    $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'installed_version'");
    $stmt->execute();
    $db_version = $stmt->fetchColumn();
    if ($db_version && snap_version_compare($db_version, $installed_version, '>')) {
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
        'version'         => $release_info['version']         ?? '',
        'version_full'    => $release_info['version_full']    ?? '',
        'codename'        => $release_info['codename']        ?? '',
        'released'        => $release_info['released']        ?? '',
        'changelog'       => $release_info['changelog']       ?? [],
        'schema_changes'  => $release_info['schema_changes']  ?? false,
        'download_size'   => $release_info['download_size']   ?? 0,
        'requires_php'    => $release_info['requires_php']    ?? '8.3',
        'download_url'    => $release_info['download_url']    ?? '',
        'checksum_sha256' => $release_info['checksum_sha256'] ?? '',
        'signature'       => $release_info['signature']       ?? '',
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

    // CRONOMETER: record this run's health so the fleet job board can light the
    // version_check row. Previously only last_update_check was written, and only
    // on success, so a failed run left a stale-green row.
    $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('version_check_last_status', 'ok')
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    )->execute();

    // --- SMACKBACK: file integrity verification ---
    require_once "{$root}/core/smackback.php";
    $smack_settings = $pdo->query(
        "SELECT setting_key, setting_val FROM snap_settings
         WHERE setting_key IN ('smackback_enabled', 'smackback_mode')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    // A verify that runs WHILE AN UPDATE IS IN FLIGHT compares half-replaced (or
    // fully replaced, not yet re-baselined) files against the PRE-UPDATE manifest
    // and reports tampering that never happened. core/constants.php exempts CLI
    // from the maintenance lock — correctly, because the updater's own chunk
    // requests must not 503 themselves — but this cron inherited that exemption
    // and walks straight through the lock a web request would respect.
    //
    // strathmore.pics, 2026-11-11: update finalized 03:57, its re-baseline was
    // skipped (SS-1284), the 04:11:40 cron verify flagged the six files the
    // update had rewritten, and a live site went BREACH -> LOCKOUT. SS-1284 stops
    // the baseline being skipped; this stops the cron verifying into the middle
    // of an update at all. They are separate defects and both are real.
    //
    // Deliberately only SKIPS: smackback_verify_due() stays true, so the very
    // next cron run verifies normally once the lock clears. A skipped pass costs
    // one interval; a false breach costs a site.
    $snap_maint_lock   = "{$root}/data/maintenance.lock";
    $snap_update_running = false;
    if (($smack_settings['smackback_enabled'] ?? '0') === '1' && file_exists($snap_maint_lock)) {
        // Honour the same 5-minute safety valve core/constants.php uses, so a
        // stale lock left by a crashed update cannot silence verification for
        // ever. Past that age the lock is ignored and the verify proceeds.
        $snap_maint      = @json_decode((string) @file_get_contents($snap_maint_lock), true) ?? [];
        $snap_maint_age  = time() - (int)($snap_maint['since'] ?? 0);
        $snap_maint_held = ((int)($snap_maint['since'] ?? 0) > 0 && $snap_maint_age <= 300);
        if ($snap_maint_held) {
            echo "SMACKBACK: verify skipped — an update holds data/maintenance.lock ({$snap_maint_age}s). "
               . "Verifying mid-update compares new files against the pre-update manifest and false-breaches. "
               . "The next scheduled run will verify.\n";
            error_log('SnapSmack cron: SMACKBACK verify skipped — maintenance lock held ('
                . $snap_maint_age . 's). See the strathmore.pics false breach, 2026-11-11.');
            $snap_update_running = true;
        }
    }

    if (($smack_settings['smackback_enabled'] ?? '0') === '1' && !$snap_update_running && smackback_verify_due()) {
        $smack_result = smackback_verify_all('cron');
        if ($smack_result['status'] === 'breach') {
            smackback_handle_breach(
                $smack_result['tampered'],
                $smack_result['missing'],
                $smack_result['truncated'] ?? [],
                $smack_result['corrupted'] ?? [],
                $smack_result['unexpected'] ?? [],
                'cron'
            );
            echo "SMACKBACK BREACH DETECTED: "
               . count($smack_result['tampered'])  . " tampered, "
               . count($smack_result['truncated'] ?? []) . " truncated, "
               . count($smack_result['corrupted'] ?? []) . " corrupted, "
               . count($smack_result['missing'])   . " missing, "
               . count($smack_result['unexpected'] ?? []) . " unexpected. Alert sent.\n";
        } else {
            $pdo->prepare(
                "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('smackback_last_full_verify', ?)
                 ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
            )->execute([date('Y-m-d H:i:s')]);
            echo "SMACKBACK: {$smack_result['ok']} files verified clean in {$smack_result['duration']}s.\n";
        }
    }

    // CLI output for cron logs
    $msg = "SnapSmack version check complete. ";
    $msg .= "Core: {$core_status}. ";
    $msg .= "Skin updates: " . count($skin_info['updated_skins']) . ".";
    echo $msg . "\n";

    // One message per completed calendar month. Hubs summarize their fleet;
    // spokes delegate to the hub; standalone sites summarize themselves.
    try {
        require_once "{$root}/core/monthly-activity-summary.php";
        $monthly = snap_monthly_activity_maybe_send($pdo);
        echo "Monthly activity: " . ($monthly['status'] ?? 'unknown') . ".\n";
    } catch (Throwable $monthly_error) {
        error_log('Monthly activity summary failed: ' . $monthly_error->getMessage());
    }

} catch (PDOException $e) {
    // CRONOMETER: best-effort record the failure so the fleet board shows a
    // recent FAILED run instead of a stale-green version_check row. If the DB
    // itself is the problem there is nothing more we can do — swallow and exit.
    try {
        $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('version_check_last_status', 'failed')
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
        )->execute();
        $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('last_update_check', ?)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
        )->execute([date('Y-m-d H:i:s')]);
    } catch (\Throwable $ignore) { /* DB unavailable — leave it */ }
    fwrite(STDERR, "Failed to store update check result: " . $e->getMessage() . "\n");
    exit(1);
}
// ===== SNAPSMACK EOF =====
