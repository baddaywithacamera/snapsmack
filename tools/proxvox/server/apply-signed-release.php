<?php
declare(strict_types=1);
// SNAPSMACK_EOF_HEADER — private CLI helper for PROX VOX signed release installs.

if (PHP_SAPI !== 'cli' || count($argv) !== 6) {
    exit(1);
}
[, $step, $domain, $from, $package, $stateDir] = $argv;
$manifest = json_decode((string) file_get_contents($stateDir . '/release.json'), true, 512, JSON_THROW_ON_ERROR);
$version = (string) $manifest['version'];
$checksum = (string) $manifest['checksum_sha256'];
$signature = (string) $manifest['signature'];
$trustedKey = 'c72e35ffd5a6bcf7225f749b2a4320f21bde6f4c253023290710792d7bd5c33e';

function result(array $data = []): void {
    echo json_encode(['ok' => true] + $data, JSON_THROW_ON_ERROR) . PHP_EOL;
}

function must(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if ($step === 'verify') {
    must(($manifest['signing_pubkey'] ?? '') === $trustedKey
        && preg_match('/^[a-f0-9]{64}$/D', $checksum) === 1
        && preg_match('/^[a-f0-9]{128}$/D', $signature) === 1
        && hash_equals($checksum, hash_file('sha256', $package))
        && sodium_crypto_sign_verify_detached(hex2bin($signature), $checksum, hex2bin($trustedKey)),
        'Published release checksum or signature did not verify.');
    result();
    exit;
}

$root = getcwd();
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = $domain;
require $root . '/core/db.php';
require_once $root . '/core/constants.php';
must($pdo instanceof PDO, 'Could not connect to the site database.');
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings
    WHERE setting_key IN ('site_url','installed_version')")->fetchAll(PDO::FETCH_KEY_PAIR);
must(parse_url((string) ($settings['site_url'] ?? ''), PHP_URL_HOST) === $domain,
    'The database belongs to a different site.');

$backup = $stateDir . '/database.sql';
switch ($step) {
    case 'backup':
        must(SNAPSMACK_VERSION_SHORT === $from && ($settings['installed_version'] ?? '') === $from,
            'The current site version is different from the approved starting version.');
        require_once $root . '/core/db-dump.php';
        $error = '';
        must(snap_dump_database($pdo, $backup, 'PROX VOX signed release backup', $error) !== false,
            'Could not back up the database.');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        must(file_put_contents($stateDir . '/tables.json', json_encode($tables, JSON_THROW_ON_ERROR)) !== false,
            'Could not save the pre-update table inventory.');
        result();
        break;

    case 'extract':
        require_once $root . '/core/updater.php';
        $error = '';
        must(updater_verify_package($package, $checksum, $signature, $error),
            'The installed updater refused the signed release.');
        $report = updater_extract($package);
        must($report['success'] && !$report['errors'], 'The installed updater reported extraction errors.');
        result(['files' => $report['files_updated']]);
        break;

    case 'finalize':
        require_once $root . '/core/updater.php';
        $migrations = updater_run_migrations($pdo, updater_find_migrations($pdo));
        must($migrations['success'] && !$migrations['errors'], 'Schema sync or migrations failed.');
        must(updater_set_version($pdo, $version, 'Alpha ' . $version, SNAPSMACK_VERSION_CODENAME, $checksum),
            'Could not save the installed release.');
        $orphans = updater_remove_known_orphans($version);
        must(!$orphans['failed'], 'The updater could not remove a deprecated package path.');
        $pdo->exec("DELETE FROM snap_settings WHERE setting_key = 'update_check_result'");
        $public = updater_reconcile_public_site($pdo);
        must(!$public['errors'], 'Public site files could not be reconciled.');
        require_once $root . '/core/smackback.php';
        must(smackback_init_from_disk(), 'SMACKBACK baseline refresh failed.');
        if (smackback_is_breach()) {
            smackback_resolve_breach('update');
        }
        result(['migrations' => count($migrations['applied'] ?? [])]);
        break;

    case 'inspect':
        must(SNAPSMACK_VERSION_SHORT === $version && ($settings['installed_version'] ?? '') === $version,
            'The installed code and database do not both report the new release.');
        require_once $root . '/core/smackback.php';
        must(!smackback_is_breach(), 'SMACKBACK remains in breach.');
        result(['version' => SNAPSMACK_VERSION_SHORT]);
        break;

    case 'restore':
        require_once $root . '/core/recovery-engine.php';
        $before = json_decode((string) file_get_contents($stateDir . '/tables.json'), true, 512, JSON_THROW_ON_ERROR);
        $after = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $added = array_diff($after, $before);
        $schema = (string) file_get_contents($stateDir . '/canonical.sql');
        $quote = chr(96);
        foreach ($added as $table) {
            must(preg_match('/^snap_[a-z0-9_]+$/D', $table) === 1
                && preg_match('/CREATE TABLE IF NOT EXISTS\s+' . $quote . preg_quote($table, '/') . $quote . '/i', $schema) === 1,
                'An unexpected database table appeared. Inspect before attempting rollback.');
        }
        if ($added) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($added as $table) {
                    $pdo->exec('DROP TABLE IF EXISTS ' . $quote . $table . $quote);
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        }
        $recovery = new SnapSmackRecovery($pdo, $root);
        $report = $recovery->importSqlDump($backup);
        must($report['imported'] > 0 && !$report['errors'], 'Database rollback did not complete.');
        $current = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='installed_version'")->fetchColumn();
        must($current === $from, 'Rollback did not restore the prior version.');
        result();
        break;

    default:
        exit(2);
}

// ===== SNAPSMACK EOF =====
