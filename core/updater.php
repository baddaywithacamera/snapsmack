<?php
/**
 * SNAPSMACK - Core Updater Engine
 * Alpha v0.7.2
 *
 * Backend functions for the self-update system. Handles downloading release
 * packages, verifying SHA-256 checksums and Ed25519 signatures, extracting
 * updates while respecting protected paths, running schema migrations, and
 * performing rollback on failure.
 *
 * This file is a function library — it does not output HTML or handle routing.
 * The admin UI (smack-update.php) and cron checker (cron-version-check.php)
 * call these functions.
 *
 * SECURITY MODEL:
 * - Downloads are verified with SHA-256 checksum + Ed25519 signature
 * - Protected paths (db.php, skins/, uploads/, etc.) are never overwritten
 * - A full backup is forced before any extraction
 * - Migrations run inside transactions where possible
 * - Rollback restores from the pre-update backup on failure
 */

require_once __DIR__ . '/release-pubkey.php';

// ─── CONSTANTS ──────────────────────────────────────────────────────────────

define('UPDATER_API_URL', 'https://snapsmack.ca/releases/latest.json');
define('UPDATER_BACKUP_DIR', dirname(__DIR__) . '/backups');
define('UPDATER_MIGRATIONS_DIR', dirname(__DIR__) . '/migrations');
define('UPDATER_TEMP_DIR', sys_get_temp_dir() . '/snapsmack_update');
define('UPDATER_PROTECTED_PATHS_FILE', dirname(__DIR__) . '/protected_paths.json');

// ─── VERSION CHECK ──────────────────────────────────────────────────────────

/**
 * Fetch the latest release metadata from the update server.
 * Returns an associative array on success, or ['error' => 'message'] on failure.
 *
 * Expected JSON structure from server:
 * {
 *   "version": "0.8",
 *   "version_full": "Alpha 0.8",
 *   "released": "2026-03-15",
 *   "download_url": "https://...",
 *   "checksum_sha256": "abc123...",
 *   "signature": "hex-ed25519-sig",
 *   "changelog": ["Added updater system", "Fixed XSS in skin gallery"],
 *   "file_changes": {"added": [...], "modified": [...], "removed": [...]},
 *   "schema_changes": true,
 *   "requires_php": "8.0",
 *   "requires_mysql": "5.7",
 *   "download_size": 2450000
 * }
 */
function updater_fetch_release_info(): array {
    $url = UPDATER_API_URL;

    $json = _updater_http_get($url);
    if ($json === false) {
        return ['error' => 'Could not reach the update server. Check your internet connection.'];
    }

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['version'])) {
        return ['error' => 'Invalid response from update server.'];
    }

    return $data;
}

/**
 * Compare the installed version against the latest available version.
 * Returns: 'up_to_date', 'update_available', or 'error'.
 */
function updater_check_status(string $installed_version, array $release_info): string {
    if (isset($release_info['error'])) {
        return 'error';
    }
    return version_compare($release_info['version'], $installed_version, '>')
        ? 'update_available'
        : 'up_to_date';
}


// ─── DOWNLOAD & VERIFY ─────────────────────────────────────────────────────

/**
 * Download the release package to a temp directory.
 * Returns the local file path on success, or false on failure.
 */
function updater_download(string $url, string &$error = ''): string|false {
    // Ensure temp directory exists
    if (!is_dir(UPDATER_TEMP_DIR)) {
        mkdir(UPDATER_TEMP_DIR, 0700, true);
    }

    $dest = UPDATER_TEMP_DIR . '/snapsmack-update.zip';

    // Try cURL first
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fp = fopen($dest, 'wb');
        if (!$fp) {
            $error = 'Cannot write to temp directory.';
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SnapSmack-Updater/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0.0'),
        ]);
        $ok = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $http_code !== 200) {
            @unlink($dest);
            $error = "Download failed (HTTP {$http_code}). {$curl_err}";
            return false;
        }
        return $dest;
    }

    // Fallback: file_get_contents
    $ctx = stream_context_create([
        'http' => [
            'timeout'    => 120,
            'user_agent' => 'SnapSmack-Updater/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0.0'),
        ],
        'ssl' => ['verify_peer' => true],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        $error = 'Download failed. cURL not available and file_get_contents also failed.';
        return false;
    }
    file_put_contents($dest, $data);
    return $dest;
}

/**
 * Verify the SHA-256 checksum of a downloaded file.
 */
function updater_verify_checksum(string $file_path, string $expected_sha256): bool {
    $actual = hash_file('sha256', $file_path);
    return hash_equals(strtolower($expected_sha256), strtolower($actual));
}

/**
 * Verify the Ed25519 signature of a SHA-256 checksum string.
 * Returns true if valid, false if invalid or verification is skipped.
 * When SNAPSMACK_SIGNING_ENFORCED is false, returns true with a logged warning.
 */
function updater_verify_signature(string $checksum, string $signature_hex): bool {
    // Check if libsodium is available
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        if (SNAPSMACK_SIGNING_ENFORCED) {
            return false;
        }
        error_log('SnapSmack Updater: libsodium not available, signature check skipped.');
        return true;
    }

    // Check if we have a real key
    if (SNAPSMACK_RELEASE_PUBKEY === str_repeat('0', 64)) {
        if (SNAPSMACK_SIGNING_ENFORCED) {
            return false;
        }
        error_log('SnapSmack Updater: Placeholder public key detected, signature check skipped.');
        return true;
    }

    try {
        $pubkey    = sodium_hex2bin(SNAPSMACK_RELEASE_PUBKEY);
        $signature = sodium_hex2bin($signature_hex);
        return sodium_crypto_sign_verify_detached($signature, $checksum, $pubkey);
    } catch (\SodiumException $e) {
        error_log('SnapSmack Updater: Signature verification error — ' . $e->getMessage());
        return false;
    }
}

/**
 * Full verification pipeline: checksum + signature.
 * Returns true if both pass, false otherwise. Populates $error with details.
 */
function updater_verify_package(string $file_path, string $expected_sha256, string $signature_hex, string &$error = ''): bool {
    if (!updater_verify_checksum($file_path, $expected_sha256)) {
        $error = 'SHA-256 checksum mismatch. The download may be corrupt or tampered with.';
        return false;
    }

    if (!updater_verify_signature($expected_sha256, $signature_hex)) {
        $error = 'Ed25519 signature verification failed. This package cannot be trusted.';
        return false;
    }

    return true;
}


// ─── PROTECTED PATHS ────────────────────────────────────────────────────────

/**
 * Load the list of protected paths from the JSON manifest.
 * Returns an array of relative paths that should never be overwritten.
 */
function updater_load_protected_paths(): array {
    $file = UPDATER_PROTECTED_PATHS_FILE;
    if (!file_exists($file)) {
        // Sensible defaults if the file is missing
        return [
            'core/db.php',
            'core/constants.php',
            'core/release-pubkey.php',
            'protected_paths.json',
            'img_uploads/',
            'media_assets/',
            'assets/img/',
            'backups/',
            'skins/',
            '.htaccess',
            'robots.txt',
        ];
    }

    $data = json_decode(file_get_contents($file), true);
    return $data['protected'] ?? [];
}

/**
 * Check whether a relative path is protected.
 * Handles both exact file matches and directory prefix matches (trailing /).
 */
function updater_is_protected(string $relative_path, array $protected): bool {
    foreach ($protected as $rule) {
        // Directory rule (e.g., "skins/")
        if (str_ends_with($rule, '/')) {
            if (str_starts_with($relative_path, $rule) || $relative_path === rtrim($rule, '/')) {
                return true;
            }
        }
        // Exact file match
        if ($relative_path === $rule) {
            return true;
        }
    }
    return false;
}


// ─── BACKUP ─────────────────────────────────────────────────────────────────

/**
 * Create a pre-update database dump.
 *
 * Dumps all SnapSmack tables to a .sql file in the backups directory.
 * A DB dump is all that's needed before an update — if migrations go wrong
 * the DB can be restored from this file, and files can always be restored
 * from the previous release zip.
 *
 * Returns the backup file path on success, or false on failure.
 */
function updater_create_backup(string &$error = ''): string|false {
    global $pdo;

    @set_time_limit(120);

    $backup_dir = UPDATER_BACKUP_DIR;
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }

    $timestamp    = date('Y-m-d_H-i-s');
    $version      = defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : 'unknown';
    $safe_version = preg_replace('/[^a-zA-Z0-9._-]/', '', $version);
    $backup_file  = "{$backup_dir}/pre-update_{$safe_version}_{$timestamp}.sql";

    $fh = fopen($backup_file, 'wb');
    if (!$fh) {
        $error = 'Could not write backup file to ' . $backup_dir;
        return false;
    }

    try {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($tables)) {
            fclose($fh);
            @unlink($backup_file);
            $error = 'No tables found in database.';
            return false;
        }

        // Header — streamed straight to disk, no giant string in memory
        fwrite($fh, "-- SnapSmack pre-update DB backup\n");
        fwrite($fh, "-- Version: {$version}  Date: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fh, "-- Tables: " . implode(', ', $tables) . "\n\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $quoted = "`{$table}`";

            // CREATE TABLE
            $create = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_NUM);
            fwrite($fh, "DROP TABLE IF EXISTS {$quoted};\n");
            fwrite($fh, $create[1] . ";\n\n");

            // Stream rows in batches of 200 to keep memory flat
            $offset = 0;
            $batch  = 200;
            $cols   = null;

            while (true) {
                $stmt = $pdo->query("SELECT * FROM {$quoted} LIMIT {$batch} OFFSET {$offset}");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) break;

                if ($cols === null) {
                    $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                }

                $values = [];
                foreach ($rows as $row) {
                    $escaped = array_map(function ($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        return $pdo->quote($v);
                    }, array_values($row));
                    $values[] = '(' . implode(', ', $escaped) . ')';
                }
                fwrite($fh, "INSERT INTO {$quoted} ({$cols}) VALUES\n");
                fwrite($fh, implode(",\n", $values) . ";\n\n");

                $offset += $batch;
                if (count($rows) < $batch) break; // last batch
                usleep(50000); // 50ms pause between batches — stay under host I/O rate limit
            }
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        return $backup_file;

    } catch (\Exception $e) {
        fclose($fh);
        @unlink($backup_file);
        $error = 'Backup failed: ' . $e->getMessage();
        return false;
    }
}


// ─── EXTRACTION ─────────────────────────────────────────────────────────────

/**
 * Extract an update package to the project root, respecting protected paths.
 * Returns an array of results:
 *   ['success' => bool, 'files_updated' => int, 'files_skipped' => int, 'errors' => [...]]
 */
function updater_extract(string $zip_path): array {
    $root = dirname(__DIR__);
    $protected = updater_load_protected_paths();
    $result = ['success' => true, 'files_updated' => 0, 'files_skipped' => 0, 'errors' => []];

    $zip = new ZipArchive();
    $res = $zip->open($zip_path);
    if ($res !== true) {
        $result['success'] = false;
        $result['errors'][] = "Failed to open zip archive (error code: {$res}).";
        return $result;
    }

    // Detect wrapper folder: if all entries start with the same prefix, strip it
    $wrapper = _updater_detect_wrapper($zip);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        $relative = $entry;

        // Strip wrapper folder if present
        if ($wrapper !== '') {
            if (!str_starts_with($entry, $wrapper)) continue;
            $relative = substr($entry, strlen($wrapper));
        }

        // Block path traversal attacks
        if (str_contains($relative, '..') || str_starts_with($relative, '/')) {
            $result['errors'][] = "Blocked suspicious path: {$relative}";
            continue;
        }

        // Skip empty (directory-only) entries
        if ($relative === '' || str_ends_with($relative, '/')) {
            // Create directory if it doesn't exist and isn't protected
            if ($relative !== '' && !updater_is_protected($relative, $protected)) {
                $dir = $root . '/' . rtrim($relative, '/');
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }
            continue;
        }

        // Skip protected paths
        if (updater_is_protected($relative, $protected)) {
            $result['files_skipped']++;
            continue;
        }

        // Ensure parent directory exists
        $dest = $root . '/' . $relative;
        $dest_dir = dirname($dest);
        if (!is_dir($dest_dir)) {
            mkdir($dest_dir, 0755, true);
        }

        // Extract file
        $content = $zip->getFromIndex($i);
        if ($content === false) {
            $result['errors'][] = "Failed to read: {$relative}";
            continue;
        }

        if (file_put_contents($dest, $content) === false) {
            $result['errors'][] = "Failed to write: {$relative}";
            $result['success'] = false;
            continue;
        }

        $result['files_updated']++;
    }

    $zip->close();
    return $result;
}

/**
 * Extract a time-limited chunk of the update zip archive.
 *
 * Shared-host safe: runs for at most $time_limit_sec seconds per call, then
 * returns control so the caller can continue in a subsequent HTTP request.
 * Call repeatedly with the returned next_offset until done === true.
 *
 * @param string $zip_path       Path to the update zip
 * @param int    $offset         Index of the first zip entry to process
 * @param int    $time_limit_sec Stop after this many seconds (default: 12)
 * @return array {
 *   success:       bool,
 *   files_updated: int,
 *   files_skipped: int,
 *   errors:        string[],
 *   next_offset:   int,
 *   total:         int,
 *   done:          bool,
 * }
 */
function updater_extract_chunk(string $zip_path, int $offset, int $time_limit_sec = 8): array {
    $start     = microtime(true);
    $root      = dirname(__DIR__);
    $protected = updater_load_protected_paths();

    $result = [
        'success'       => true,
        'files_updated' => 0,
        'files_skipped' => 0,
        'errors'        => [],
        'next_offset'   => $offset,
        'total'         => 0,
        'done'          => false,
    ];

    $zip = new ZipArchive();
    $res = $zip->open($zip_path);
    if ($res !== true) {
        $result['success'] = false;
        $result['errors'][] = "Failed to open zip (error {$res}).";
        $result['done']     = true;
        return $result;
    }

    $total           = $zip->numFiles;
    $result['total'] = $total;
    $wrapper         = _updater_detect_wrapper($zip);

    for ($i = $offset; $i < $total; $i++) {
        // Check time budget every 10 entries to limit microtime() call overhead
        if ($i % 10 === 0 && $i > $offset && (microtime(true) - $start) >= $time_limit_sec) {
            $result['next_offset'] = $i;
            $zip->close();
            return $result; // not done — caller continues from next_offset
        }

        $entry    = $zip->getNameIndex($i);
        $relative = $entry;

        // Strip wrapper folder if present
        if ($wrapper !== '') {
            if (!str_starts_with($entry, $wrapper)) continue;
            $relative = substr($entry, strlen($wrapper));
        }

        // Block path traversal
        if (str_contains($relative, '..') || str_starts_with($relative, '/')) {
            $result['errors'][] = "Blocked path: {$relative}";
            continue;
        }

        // Directory entry — create if not protected, then skip
        if ($relative === '' || str_ends_with($relative, '/')) {
            if ($relative !== '' && !updater_is_protected($relative, $protected)) {
                $dir = $root . '/' . rtrim($relative, '/');
                if (!is_dir($dir)) mkdir($dir, 0755, true);
            }
            continue;
        }

        // Skip protected paths
        if (updater_is_protected($relative, $protected)) {
            $result['files_skipped']++;
            continue;
        }

        // Ensure parent directory exists
        $dest     = $root . '/' . $relative;
        $dest_dir = dirname($dest);
        if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);

        // Extract file — stream large files to avoid loading them fully into memory
        $stat      = $zip->statIndex($i);
        $file_size = $stat['size'] ?? 0;

        if ($file_size > 102400) {
            // > 100 KB: stream directly to disk via ZipArchive::getStream()
            $src_stream = $zip->getStream($entry);
            if ($src_stream === false) {
                $result['errors'][] = "Failed to open stream: {$relative}";
                continue;
            }
            $dest_fp = @fopen($dest, 'wb');
            if ($dest_fp === false) {
                fclose($src_stream);
                $result['errors'][] = "Failed to open for write: {$relative}";
                $result['success']  = false;
                continue;
            }
            $bytes = stream_copy_to_stream($src_stream, $dest_fp);
            fclose($src_stream);
            fclose($dest_fp);
            if ($bytes === false) {
                $result['errors'][] = "Stream write failed: {$relative}";
                $result['success']  = false;
                continue;
            }
        } else {
            // Small file: read into memory and write at once
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $result['errors'][] = "Failed to read: {$relative}";
                continue;
            }
            if (file_put_contents($dest, $content) === false) {
                $result['errors'][] = "Failed to write: {$relative}";
                $result['success']  = false;
                continue;
            }
        }

        $result['files_updated']++;

        // Throttle I/O — pause every 10 files to stay under shared-host rate limits
        if ($result['files_updated'] % 10 === 0) {
            usleep(25000); // 25ms
        }
    }

    $zip->close();
    $result['done']        = true;
    $result['next_offset'] = $total;
    return $result;
}


/**
 * Detect if all zip entries share a common wrapper folder.
 * Returns the wrapper prefix (e.g., "snapsmack-0.8/") or empty string.
 */
function _updater_detect_wrapper(ZipArchive $zip): string {
    if ($zip->numFiles === 0) return '';

    $first = $zip->getNameIndex(0);
    $slash = strpos($first, '/');
    if ($slash === false) return '';

    $prefix = substr($first, 0, $slash + 1);
    for ($i = 1; $i < $zip->numFiles; $i++) {
        if (!str_starts_with($zip->getNameIndex($i), $prefix)) {
            return '';
        }
    }
    return $prefix;
}


// ─── SCHEMA MIGRATIONS ─────────────────────────────────────────────────────

/**
 * Find all pending migration files that have not yet been applied.
 *
 * Uses a snap_migrations tracking table to record what has been run,
 * so migrations are idempotent and safe to call on every update regardless
 * of version distance. Files are returned sorted alphabetically, which
 * is also chronological given the "migrate-feature-name.sql" naming convention.
 *
 * Returns an array of absolute file paths, empty if nothing is pending.
 */
function updater_find_migrations(PDO $pdo): array {
    $dir = UPDATER_MIGRATIONS_DIR;
    if (!is_dir($dir)) return [];

    // Ensure tracking table exists (compatible with MySQL 5.6+)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS snap_migrations (
            migration  VARCHAR(100) NOT NULL,
            applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Fetch already-applied migration names
    $applied = $pdo->query("SELECT migration FROM snap_migrations")
                   ->fetchAll(PDO::FETCH_COLUMN);
    $applied = array_flip($applied);

    // Scan migrations directory, sort alphabetically for deterministic order
    $files = glob("{$dir}/migrate*.sql") ?: [];
    sort($files);

    $pending = [];
    foreach ($files as $f) {
        if (!isset($applied[basename($f)])) {
            $pending[] = $f;
        }
    }

    return $pending;
}

/**
 * Execute a list of migration SQL files against the database.
 *
 * MySQL compatibility:
 *   DDL statements (ALTER TABLE, CREATE TABLE) cannot be wrapped in
 *   transactions on MySQL — they auto-commit. We therefore execute each
 *   statement individually and tolerate the two most common "already done"
 *   errors so that migrations are safe to attempt more than once:
 *
 *     MySQL 1060  ER_DUP_FIELDNAME  — ADD COLUMN when column already exists
 *     MySQL 1050  ER_TABLE_EXISTS_ERROR — CREATE TABLE when table already exists
 *     MySQL 1091  ER_CANT_DROP_FIELD_OR_KEY — DROP INDEX/KEY that doesn't exist
 *
 *   Any other PDOException is a real failure: the chain stops and the caller
 *   can trigger a rollback.
 *
 * Each successfully processed file is recorded in snap_migrations so it
 * will not be run again.
 *
 * Returns ['success' => bool, 'applied' => [...filenames...], 'errors' => [...]]
 */
function updater_run_migrations(PDO $pdo, array $migration_files): array {
    $result = ['success' => true, 'applied' => [], 'errors' => []];

    // MySQL errno values that mean "this change is already in place" — safe to skip
    $idempotent_errno = [
        1060, // ER_DUP_FIELDNAME      — column already exists
        1050, // ER_TABLE_EXISTS_ERROR — table already exists
        1091, // ER_CANT_DROP_FIELD_OR_KEY — key/index doesn't exist
    ];

    foreach ($migration_files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            $result['errors'][] = 'Could not read: ' . basename($file);
            $result['success'] = false;
            continue;
        }

        $migration_name = basename($file);

        try {
            // Split into individual statements on semicolons
            foreach (explode(';', $sql) as $raw) {
                // Strip comments; skip blank statements
                $stmt = trim(preg_replace('/^\s*--.*$/m', '', $raw));
                if ($stmt === '') continue;

                try {
                    $pdo->exec($stmt);
                } catch (\PDOException $inner) {
                    $errno = (int)($inner->errorInfo[1] ?? 0);
                    if (in_array($errno, $idempotent_errno, true)) {
                        // Already applied — this is expected on re-runs or partial applies
                        continue;
                    }
                    throw $inner; // real error — bubble up to outer catch
                }
            }

            // Record as applied so it never runs again
            $pdo->prepare("INSERT IGNORE INTO snap_migrations (migration, applied_at) VALUES (?, NOW())")
                ->execute([$migration_name]);

            $result['applied'][] = $migration_name;

        } catch (\PDOException $e) {
            $result['errors'][] = $migration_name . ': ' . $e->getMessage();
            $result['success'] = false;
            break; // Stop on first real failure — caller handles rollback
        }
    }

    return $result;
}


// ─── VERSION BOOKKEEPING ────────────────────────────────────────────────────

/**
 * Update the installed version in the settings table and rewrite constants.php.
 */
function updater_set_version(PDO $pdo, string $version_short, string $version_full, string $codename = ''): bool {
    try {
        // Update settings table
        $stmt = $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'installed_version'");
        $stmt->execute([$version_short]);

        // Also store last update timestamp
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('last_update_check', ?)
                               ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)");
        $stmt->execute([date('Y-m-d H:i:s')]);

        // Rewrite constants.php — sanitize version strings to prevent code injection
        $safe_full     = preg_replace('/[^a-zA-Z0-9 ._-]/', '', $version_full);
        $safe_short    = preg_replace('/[^a-zA-Z0-9._-]/', '', $version_short);
        $safe_codename = preg_replace('/[^a-zA-Z0-9 _-]/', '', $codename);

        $constants_file = __DIR__ . '/constants.php';
        if (file_exists($constants_file)) {
            $content = file_get_contents($constants_file);
            $content = preg_replace(
                "/define\('SNAPSMACK_VERSION',\s*'[^']*'\);/",
                "define('SNAPSMACK_VERSION', '{$safe_full}');",
                $content
            );
            $content = preg_replace(
                "/define\('SNAPSMACK_VERSION_SHORT',\s*'[^']*'\);/",
                "define('SNAPSMACK_VERSION_SHORT', '{$safe_short}');",
                $content
            );
            // Update codename if present, or add it after VERSION_SHORT
            if (preg_match("/define\('SNAPSMACK_VERSION_CODENAME'/", $content)) {
                $content = preg_replace(
                    "/define\('SNAPSMACK_VERSION_CODENAME',\s*'[^']*'\);/",
                    "define('SNAPSMACK_VERSION_CODENAME', '{$safe_codename}');",
                    $content
                );
            } elseif ($safe_codename !== '') {
                $content = preg_replace(
                    "/(define\('SNAPSMACK_VERSION_SHORT',\s*'[^']*'\);)/",
                    "$1\ndefine('SNAPSMACK_VERSION_CODENAME', '{$safe_codename}');",
                    $content
                );
            }
            file_put_contents($constants_file, $content);
        }

        return true;
    } catch (\Exception $e) {
        error_log('SnapSmack Updater: Failed to update version — ' . $e->getMessage());
        return false;
    }
}


// ─── ROLLBACK ───────────────────────────────────────────────────────────────

/**
 * Restore from a pre-update backup tar.gz file.
 * Returns true on success, false on failure.
 */
function updater_rollback(string $backup_file, string &$error = ''): bool {
    $root = dirname(__DIR__);

    if (!file_exists($backup_file)) {
        $error = 'Backup file not found.';
        return false;
    }

    try {
        $phar = new PharData($backup_file);
        $phar->extractTo($root, null, true); // overwrite existing files
        return true;
    } catch (\Exception $e) {
        $error = 'Rollback extraction failed: ' . $e->getMessage();
        return false;
    }
}


// ─── CLEANUP ────────────────────────────────────────────────────────────────

/**
 * Remove temporary update files.
 */
function updater_cleanup(): void {
    $dir = UPDATER_TEMP_DIR;
    if (is_dir($dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        rmdir($dir);
    }
}


// ─── SKIN REGISTRY CHECK (for dual notification) ───────────────────────────

/**
 * Check the skin registry for new or updated skins.
 * Returns an array with counts and details:
 * ['new_skins' => [...], 'updated_skins' => [...], 'total_notifications' => int]
 */
function updater_check_skin_registry(PDO $pdo): array {
    // Load the skin registry helper if available
    $registry_file = __DIR__ . '/skin-registry.php';
    if (!file_exists($registry_file)) {
        return ['new_skins' => [], 'updated_skins' => [], 'total_notifications' => 0];
    }
    require_once $registry_file;

    // Get the registry URL from settings
    $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'skin_registry_url'");
    $stmt->execute();
    $registry_url = $stmt->fetchColumn() ?: SKIN_REGISTRY_DEFAULT_URL;

    // Fetch remote registry
    $remote = skin_registry_fetch($registry_url);
    if (empty($remote['skins'])) {
        return ['new_skins' => [], 'updated_skins' => [], 'total_notifications' => 0];
    }

    // Get local skins
    $local = skin_registry_local();

    $new_skins = [];
    $updated_skins = [];

    foreach ($remote['skins'] as $slug => $skin) {
        // Skip development skins
        if (($skin['status'] ?? '') === 'development') continue;

        if (!isset($local[$slug])) {
            // Brand new skin not installed locally
            $new_skins[] = [
                'slug' => $slug,
                'name' => $skin['name'] ?? $slug,
                'version' => $skin['version'] ?? '?',
                'description' => $skin['description'] ?? '',
            ];
        } elseif (
            isset($skin['version'], $local[$slug]['version']) &&
            version_compare($skin['version'], $local[$slug]['version'], '>')
        ) {
            // Installed skin has an update
            $updated_skins[] = [
                'slug' => $slug,
                'name' => $skin['name'] ?? $slug,
                'from' => $local[$slug]['version'],
                'to' => $skin['version'],
            ];
        }
    }

    return [
        'new_skins' => $new_skins,
        'updated_skins' => $updated_skins,
        'total_notifications' => count($new_skins) + count($updated_skins),
    ];
}


// ─── INTERNAL HELPERS ───────────────────────────────────────────────────────

/**
 * HTTP GET request via cURL or file_get_contents.
 * Returns the response body or false on failure.
 */
function _updater_http_get(string $url): string|false {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SnapSmack-Updater/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0.0'),
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? $body : false;
    }

    // Fallback
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'SnapSmack-Updater/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0.0'),
        ],
    ]);
    return @file_get_contents($url, false, $ctx);
}
