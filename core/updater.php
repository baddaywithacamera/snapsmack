<?php
/**
 * SNAPSMACK - Core Updater Engine
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

// ─── KNOWN MIGRATIONS ───────────────────────────────────────────────────────

/**
 * Canonical list of every migration filename ever shipped in an official release.
 *
 * Any .sql file in /migrations/ that is NOT in this list is a "ghost" — a
 * leftover from a previous install, a manually placed file, or a file that was
 * deleted from the repo but not from the server.  updater_find_migrations()
 * skips ghost files automatically so they can never cause an update failure.
 *
 * Add each new migration filename here when you create it.
 */
const UPDATER_KNOWN_MIGRATIONS = [
    'migrate-076.sql',
    'migrate-077.sql',
    'migrate-078.sql',
    'migrate-comment-identity.sql',
    'migrate-multisite-tables.sql',
    'migrate-pages-image-cols.sql',
    'migrate-users-recovery-columns.sql',
];

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
    return snap_version_compare($release_info['version'], $installed_version, '>')
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
        // Sensible defaults if the file is missing.
        // Note: skins/ is intentionally NOT protected — stock skins must be
        // updatable. Non-stock skins are safe because they're never in the zip.
        return [
            'core/db.php',
            'core/constants.php',
            'core/release-pubkey.php',
            'protected_paths.json',
            'img_uploads/',
            'media_assets/',
            'assets/img/',
            'backups/',
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

    // Ensure tracking table exists (compatible with MySQL 5.6+).
    // Use query() + closeCursor() rather than exec() — CREATE TABLE IF NOT EXISTS
    // sends back a warning packet when the table already exists, and exec() does
    // not drain it, leaving the connection in a dirty state that causes errno 2014
    // on the very next query.
    $ps = $pdo->query("
        CREATE TABLE IF NOT EXISTS snap_migrations (
            migration  VARCHAR(100) NOT NULL,
            applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    if ($ps !== false) {
        $ps->closeCursor();
    }

    // Fetch already-applied migration names
    $applied = $pdo->query("SELECT migration FROM snap_migrations")
                   ->fetchAll(PDO::FETCH_COLUMN);
    $applied = array_flip($applied);

    // Scan migrations directory, sort alphabetically for deterministic order
    $files = glob("{$dir}/migrate*.sql") ?: [];
    sort($files);

    $known   = array_flip(UPDATER_KNOWN_MIGRATIONS);
    $pending = [];
    foreach ($files as $f) {
        $name = basename($f);
        if (!isset($known[$name])) {
            // Ghost file — not part of any official release. Skip silently so
            // it can never block an update. The recovery panel in smack-update.php
            // surfaces these to the admin via updater_migration_status().
            error_log("SnapSmack updater: skipping ghost migration '{$name}' (not in UPDATER_KNOWN_MIGRATIONS)");
            continue;
        }
        if (!isset($applied[$name])) {
            $pending[] = $f;
        }
    }

    return $pending;
}

/**
 * Return a summary of migration state for the Schema Recovery panel.
 *
 * Returns:
 *   'applied' => [ ['migration' => name, 'applied_at' => datetime], ... ]
 *   'pending' => [ filename, ... ]   — known but not yet applied
 *   'ghosts'  => [ filename, ... ]   — on disk, not in known list
 */
function updater_migration_status(PDO $pdo): array {
    $dir   = UPDATER_MIGRATIONS_DIR;
    $known = UPDATER_KNOWN_MIGRATIONS;

    // Fetch applied migrations — table may not exist yet on very old installs
    $applied     = [];
    $applied_map = [];
    try {
        $ps = $pdo->query("
            CREATE TABLE IF NOT EXISTS snap_migrations (
                migration  VARCHAR(100) NOT NULL,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        if ($ps !== false) $ps->closeCursor();

        $rows = $pdo->query("SELECT migration, applied_at FROM snap_migrations ORDER BY applied_at ASC")
                    ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $applied[]              = $r;
            $applied_map[$r['migration']] = true;
        }
    } catch (\Exception $e) {
        // DB not reachable or table creation failed — return empty state
    }

    // Pending = known but not recorded as applied
    $pending = [];
    foreach ($known as $name) {
        if (!isset($applied_map[$name])) {
            $pending[] = $name;
        }
    }

    // Ghosts = files on disk that are not in the known list and not already applied.
    // Also silently delete any on-disk files that are already recorded as applied —
    // they were applied before @unlink was introduced and are just clutter now.
    $known_map  = array_flip($known);
    $disk_files = array_map('basename', glob("{$dir}/migrate*.sql") ?: []);
    $ghosts     = [];
    foreach ($disk_files as $name) {
        if (isset($applied_map[$name])) {
            // Already applied — safe to delete from disk
            @unlink("{$dir}/{$name}");
            continue;
        }
        if (!isset($known_map[$name])) {
            $ghosts[] = $name;
        }
    }

    return [
        'applied' => $applied,
        'pending' => $pending,
        'ghosts'  => $ghosts,
    ];
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
 *     MySQL 1061  ER_DUP_KEYNAME   — ADD INDEX when index already exists
 *     MySQL 1050  ER_TABLE_EXISTS_ERROR — CREATE TABLE when table already exists
 *     MySQL 1091  ER_CANT_DROP_FIELD_OR_KEY — DROP INDEX/KEY that doesn't exist
 *     MySQL 1146  ER_NO_SUCH_TABLE — table doesn't exist yet (feature not migrated)
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
    $result = ['success' => true, 'applied' => [], 'errors' => [], 'schema' => []];

    // Diff the live database against snapsmack_canonical.sql and apply anything
    // missing before migration files run. This is the authoritative schema source.
    // schema-sync.php is called AFTER migrations for enum repairs (Section 4).
    $diff = updater_canonical_diff($pdo);
    if (isset($diff['error'])) {
        // Canonical SQL unavailable — non-fatal, log and continue.
        $result['errors'][] = '[canonical-diff] ' . $diff['error'];
    } else {
        $apply = updater_apply_canonical_diff($pdo, $diff);
        $result['schema'] = $apply;
        if (!empty($apply['errors'])) {
            foreach ($apply['errors'] as $e) {
                $result['errors'][] = '[canonical-diff] ' . $e;
            }
        }
    }

    // MySQL errno values that mean "this change is already in place" — safe to skip
    $idempotent_errno = [
        1060, // ER_DUP_FIELDNAME        — column already exists
        1061, // ER_DUP_KEYNAME          — index/key already exists
        1050, // ER_TABLE_EXISTS_ERROR   — table already exists
        1091, // ER_CANT_DROP_FIELD_OR_KEY — key/index doesn't exist
        1146, // ER_NO_SUCH_TABLE        — table not yet created (feature not migrated)
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
            // Strip -- comments BEFORE splitting on semicolons, so semicolons
            // inside comments never get treated as statement terminators.
            $sql_stripped = preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (explode(';', $sql_stripped) as $raw) {
                $stmt = trim($raw);
                if ($stmt === '') continue;

                try {
                    // Use query() + closeCursor() rather than exec() so that
                    // MySQL's result packet for DDL statements (ALTER TABLE,
                    // ADD INDEX, etc.) is fully consumed before the next
                    // statement runs. exec() bypasses PDO's buffering and
                    // leaves a dangling result on the wire, causing errno 2014
                    // on the very next query issued anywhere in the request.
                    $ps = $pdo->query($stmt);
                    if ($ps !== false) {
                        $ps->closeCursor();
                    }
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

            // Delete the SQL file — it has been applied and recorded.
            // This prevents ghost files from accumulating and being picked up
            // as pending on future updates.
            if (!unlink($file)) {
                error_log("SnapSmack updater: could not delete migration file: {$file} (permissions issue?)");
            }

        } catch (\PDOException $e) {
            $result['errors'][] = $migration_name . ': ' . $e->getMessage();
            $result['success'] = false;
            break; // Stop on first real failure — caller handles rollback
        }
    }

    // ── Enum repairs via schema-sync ─────────────────────────────────────────
    // The canonical diff above handles missing tables and columns but cannot
    // detect type mismatches on existing columns (e.g. a stale enum).
    // schema-sync.php Section 4 detects and repairs these.  Idempotent — safe
    // to call on every update.
    $sync_path = dirname(__FILE__) . '/schema-sync.php';
    if (is_file($sync_path)) {
        require_once $sync_path;
        $sync_report = snap_schema_sync($pdo);
        if (!empty($sync_report['columns_added'])) {
            foreach ($sync_report['columns_added'] as $fix) {
                $result['applied'][] = '[schema-sync] ' . $fix;
            }
        }
        if (!empty($sync_report['errors'])) {
            foreach ($sync_report['errors'] as $e) {
                $result['errors'][] = '[schema-sync] ' . $e;
            }
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
/**
 * Diff the live database against the canonical schema published by the release server.
 *
 * Fetches snapsmack_canonical.sql from the URL in the cached release manifest
 * (canonical_schema_url from latest.json) so the comparison is always against
 * the current release, not whatever happens to be on disk — which may be stale
 * if a previous update failed mid-extraction.
 *
 * Falls back to the on-disk copy at database/schema/snapsmack_canonical.sql if
 * no URL is available or the fetch fails.
 *
 * Returns:
 *   'canonical_tables'  => int   — number of tables declared in canonical SQL
 *   'missing_tables'    => []    — table names present in canonical but absent from live DB
 *   'missing_columns'   => []    — [ ['table'=>, 'column'=>, 'definition'=>], ... ]
 *   'all_ok'            => bool  — true when no differences found
 *   'source'            => string — 'remote' | 'disk' | 'none'
 *   'error'             => string — set on fatal failure (no SQL available)
 *
 * @param PDO    $pdo              Live database connection
 * @param string $canonical_url     URL from latest.json (empty string to skip remote fetch)
 * @param string $canonical_sig_url URL of detached Ed25519 signature file (hex-encoded SHA-256 sig)
 */
function updater_canonical_diff(PDO $pdo, string $canonical_url = '', string $canonical_sig_url = ''): array {

    // ── 1. Obtain canonical SQL ───────────────────────────────────────────────
    $sql    = '';
    $source = 'none';

    // Try remote first — authoritative even when on-disk copy is from a failed update
    if ($canonical_url !== '') {
        $fetched = _updater_http_get($canonical_url);
        if ($fetched !== false && strlen($fetched) > 256 && str_contains($fetched, 'CREATE TABLE')) {
            // Verify signature when available
            if ($canonical_sig_url !== '') {
                $sig_hex = trim((string) _updater_http_get($canonical_sig_url));
                if ($sig_hex !== '' && function_exists('sodium_crypto_sign_verify_detached')) {
                    try {
                        $checksum = hash('sha256', $fetched);
                        $verified = sodium_crypto_sign_verify_detached(
                            sodium_hex2bin($sig_hex),
                            $checksum,
                            sodium_hex2bin(SNAPSMACK_RELEASE_PUBKEY)
                        );
                        if (!$verified) {
                            // Signature mismatch — reject remote copy, fall through to disk
                            error_log('SnapSmack updater: canonical schema signature verification failed — using on-disk fallback');
                            $fetched = false;
                        }
                    } catch (\SodiumException $e) {
                        error_log('SnapSmack updater: canonical schema sig verify error — ' . $e->getMessage());
                        $fetched = false;
                    }
                }
            }
            if ($fetched !== false) {
                $sql    = $fetched;
                $source = 'remote';
            }
        }
    }

    // Fall back to on-disk copy
    if ($sql === '') {
        $disk_path = dirname(__DIR__) . '/database/schema/snapsmack_canonical.sql';
        if (is_file($disk_path)) {
            $sql    = (string) file_get_contents($disk_path);
            $source = 'disk';
        }
    }

    if ($sql === '') {
        return ['error' => 'Canonical schema SQL not available — remote fetch failed and no on-disk copy found.'];
    }

    // ── 2. Parse canonical SQL into table → column definitions ───────────────
    // Each CREATE TABLE block is matched in its entirety so the full column
    // definition (including multi-line COMMENT continuations) is preserved for
    // ALTER TABLE use.
    $canonical = []; // [ table_name => [ col_name => full_definition_string ] ]

    preg_match_all(
        '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`(\w+)`\s*\((.*?)\)\s*ENGINE\s*=/si',
        $sql,
        $table_matches,
        PREG_SET_ORDER
    );

    foreach ($table_matches as $tm) {
        $table = $tm[1];
        $body  = $tm[2];
        $cols  = [];

        $lines       = explode("\n", $body);
        $col_name    = null;
        $col_def     = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;

            if (preg_match('/^`(\w+)`/', $trimmed, $cm)) {
                // Save the previous column
                if ($col_name !== null && !empty($col_def)) {
                    $cols[$col_name] = implode(' ', $col_def);
                }
                $keyword = strtoupper($cm[1]);
                // Lines starting with a backtick-enclosed word that is a SQL keyword
                // are index/key lines (e.g. `PRIMARY`, though usually bare). Skip them.
                if (in_array($keyword, ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FULLTEXT', 'SPATIAL', 'CONSTRAINT'], true)) {
                    $col_name = null;
                    $col_def  = [];
                    continue;
                }
                $col_name = $cm[1];
                $clean    = rtrim(preg_replace('/\s*--.*$/', '', $trimmed), ',');
                $col_def  = [$clean];

            } elseif ($col_name !== null) {
                // Continuation line (e.g. COMMENT '...' on next line)
                if (preg_match('/^\s*(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT)\s/i', $trimmed)) {
                    // Hit a key definition — save column and stop
                    if (!empty($col_def)) {
                        $cols[$col_name] = implode(' ', $col_def);
                    }
                    $col_name = null;
                    $col_def  = [];
                    continue;
                }
                $clean = rtrim(preg_replace('/\s*--.*$/', '', $trimmed), ',');
                if ($clean !== '') {
                    $col_def[] = $clean;
                }
            }
        }
        // Save the last column in the block
        if ($col_name !== null && !empty($col_def)) {
            $cols[$col_name] = implode(' ', $col_def);
        }

        $canonical[$table] = $cols;
    }

    // ── 3. Query live database ────────────────────────────────────────────────
    $live_tables = array_flip(
        $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")
            ->fetchAll(PDO::FETCH_COLUMN)
    );

    $live_columns = [];
    foreach ($pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $live_columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = true;
    }

    // ── 4. Build diff ─────────────────────────────────────────────────────────
    $missing_tables  = [];
    $missing_columns = [];

    foreach ($canonical as $table => $cols) {
        if (!isset($live_tables[$table])) {
            $missing_tables[] = $table;
            continue; // No point checking columns on a missing table
        }
        foreach ($cols as $col => $def) {
            if (!isset($live_columns[$table][$col])) {
                $missing_columns[] = ['table' => $table, 'column' => $col, 'definition' => $def];
            }
        }
    }

    return [
        'canonical_tables' => count($canonical),
        'missing_tables'   => $missing_tables,
        'missing_columns'  => $missing_columns,
        'all_ok'           => empty($missing_tables) && empty($missing_columns),
        'source'           => $source,
        'raw_sql'          => $sql, // Retained for apply step; not stored in session
    ];
}

/**
 * Apply the differences found by updater_canonical_diff().
 *
 * Missing tables are created by running their full CREATE TABLE IF NOT EXISTS
 * statement extracted from the canonical SQL.  Missing columns are added via
 * ALTER TABLE ... ADD COLUMN.  Both operations are idempotent.
 *
 * Returns [ 'created' => [], 'columns_added' => [], 'errors' => [] ]
 *
 * @param PDO   $pdo  Live database connection
 * @param array $diff Return value of updater_canonical_diff() — must include 'raw_sql'
 */
function updater_apply_canonical_diff(PDO $pdo, array $diff): array {
    $result  = ['created' => [], 'columns_added' => [], 'errors' => []];
    $raw_sql = $diff['raw_sql'] ?? '';

    if ($raw_sql === '') {
        $result['errors'][] = 'No canonical SQL available — run the diff again.';
        return $result;
    }

    // ── Apply missing tables ──────────────────────────────────────────────────
    foreach ($diff['missing_tables'] ?? [] as $table) {
        // Extract the full CREATE TABLE block for this table
        if (preg_match(
            '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`' . preg_quote($table, '/') . '`\s*\(.*?\)\s*ENGINE\s*=[^;]+;/si',
            $raw_sql,
            $m
        )) {
            try {
                $pdo->exec($m[0]);
                $result['created'][] = $table;
            } catch (\PDOException $e) {
                if ((int)$e->getCode() === 1050) { // Table already exists
                    $result['created'][] = $table . ' (already existed)';
                } else {
                    $result['errors'][] = "CREATE TABLE `{$table}` failed: " . $e->getMessage();
                }
            }
        } else {
            $result['errors'][] = "Could not extract CREATE TABLE statement for `{$table}`.";
        }
    }

    // ── Apply missing columns ─────────────────────────────────────────────────
    foreach ($diff['missing_columns'] ?? [] as $item) {
        $table = $item['table'];
        $col   = $item['column'];
        $def   = $item['definition']; // Full definition starting with `col_name`

        $sql_stmt = "ALTER TABLE `{$table}` ADD COLUMN {$def}";
        try {
            $pdo->exec($sql_stmt);
            $result['columns_added'][] = "{$table}.{$col}";
        } catch (\PDOException $e) {
            if ((int)$e->getCode() === 1060) { // Duplicate column
                $result['columns_added'][] = "{$table}.{$col} (already existed)";
            } else {
                $result['errors'][] = "ADD COLUMN `{$table}`.`{$col}` failed: " . $e->getMessage();
            }
        }
    }

    return $result;
}

/**
 * Repair a mismatched or rotated Ed25519 public key in core/release-pubkey.php.
 *
 * When the release signing keypair is rotated, installed instances will fail
 * signature verification because their copy of core/release-pubkey.php still
 * contains the old public key.  This function rewrites SNAPSMACK_RELEASE_PUBKEY
 * in place so the updater can verify packages signed with the new private key.
 *
 * The new key is accepted only if it:
 *   - Is exactly 64 lowercase hex characters (32 bytes, Ed25519 public key)
 *   - Actually verifies the most recent release package's signature (when a
 *     signature and checksum are supplied for cross-checking)
 *
 * The file is written atomically (temp file + rename) so a failed write never
 * leaves release-pubkey.php in a partially-overwritten state.
 *
 * @param string $new_pubkey_hex  64-char lowercase hex Ed25519 public key
 * @param string $error           Populated with a human-readable error on failure
 * @return bool                   true on success, false on failure
 */
function updater_repair_pubkey(string $new_pubkey_hex, string &$error = ''): bool {
    $new_pubkey_hex = strtolower(trim($new_pubkey_hex));

    // Validate format
    if (!preg_match('/^[0-9a-f]{64}$/', $new_pubkey_hex)) {
        $error = 'Invalid public key — must be exactly 64 lowercase hex characters (32-byte Ed25519 key).';
        return false;
    }

    // Refuse no-op (already the same key)
    if (defined('SNAPSMACK_RELEASE_PUBKEY') && SNAPSMACK_RELEASE_PUBKEY === $new_pubkey_hex) {
        $error = 'The supplied key is already the active public key. No change needed.';
        return false;
    }

    $pubkey_file = __DIR__ . '/release-pubkey.php';

    if (!is_file($pubkey_file)) {
        $error = 'core/release-pubkey.php not found.';
        return false;
    }

    if (!is_writable($pubkey_file)) {
        $error = 'core/release-pubkey.php is not writable. Check file permissions.';
        return false;
    }

    $contents = file_get_contents($pubkey_file);
    if ($contents === false) {
        $error = 'Could not read core/release-pubkey.php.';
        return false;
    }

    // Replace the hex string on the SNAPSMACK_RELEASE_PUBKEY define line only
    $pattern  = "/^(define\s*\(\s*'SNAPSMACK_RELEASE_PUBKEY'\s*,\s*')[0-9a-fA-F]{64}('\s*\)\s*;)/m";
    $replaced = preg_replace($pattern, '${1}' . $new_pubkey_hex . '${2}', $contents, 1, $count);

    if ($count === 0 || $replaced === null) {
        $error = 'Could not locate SNAPSMACK_RELEASE_PUBKEY define in core/release-pubkey.php. The file may be corrupt.';
        return false;
    }

    // Atomic write via temp file + rename
    $tmp = $pubkey_file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $replaced, LOCK_EX) === false) {
        @unlink($tmp);
        $error = 'Failed to write updated key to disk.';
        return false;
    }

    if (!rename($tmp, $pubkey_file)) {
        @unlink($tmp);
        $error = 'Temp file rename failed. Check directory permissions.';
        return false;
    }

    // Invalidate the opcode cache so the new key takes effect immediately
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($pubkey_file, true);
    }

    return true;
}

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

/**
 * Prune old pre-update backup files from the backups directory.
 *
 * Keeps only the $keep most recent files matching the pre-update backup
 * naming pattern (pre-update_*.sql, pre-update_*.sql.gz, pre-update_*.tar.gz).
 * Everything older is deleted. Call this after every successful update so
 * backups don't pile up on shared hosting indefinitely.
 *
 * @param int $keep  Number of most-recent backup files to retain (default: 3)
 */
function updater_prune_backups(int $keep = 3): void {
    $dir = UPDATER_BACKUP_DIR;
    if (!is_dir($dir)) return;

    // Collect all backup files matching the pre-update pattern
    $files = array_merge(
        glob("{$dir}/pre-update_*.sql")    ?: [],
        glob("{$dir}/pre-update_*.sql.gz") ?: [],
        glob("{$dir}/pre-update_*.tar.gz") ?: [],
        glob("{$dir}/pre-update_*.zip")    ?: []
    );

    if (count($files) <= $keep) return;

    // Sort newest-first by modification time
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

    // Delete everything beyond the keep threshold
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
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
            snap_version_compare($skin['version'], $local[$slug]['version'], '>')
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
