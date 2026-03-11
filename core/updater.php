<?php
/**
 * SNAPSMACK - Core Updater Engine
 * Alpha v0.7.1
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
 * Create a forced pre-update backup of the entire source tree (minus uploads).
 * Returns the backup file path on success, or false on failure.
 *
 * The backup excludes:
 * - img_uploads/ (too large, user handles media separately)
 * - media_assets/ (same reason)
 * - backups/ (prevent recursive backup bloat)
 * - .git/ (not needed for recovery)
 */
function updater_create_backup(string &$error = ''): string|false {
    $root = dirname(__DIR__);
    $backup_dir = UPDATER_BACKUP_DIR;

    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }

    $timestamp = date('Y-m-d_H-i-s');
    $version = defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : 'unknown';
    $safe_version = preg_replace('/[^a-zA-Z0-9._-]/', '', $version);
    $backup_file = "{$backup_dir}/pre-update_{$safe_version}_{$timestamp}.tar.gz";

    // Exclusion list (relative to root)
    $exclude_dirs = ['img_uploads', 'media_assets', 'backups', '.git', 'node_modules'];

    try {
        $phar = new PharData($backup_file);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $real = $file->getRealPath();
            $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $real);
            $relative = str_replace('\\', '/', $relative);

            // Skip excluded directories
            $skip = false;
            foreach ($exclude_dirs as $excl) {
                if (str_starts_with($relative, $excl . '/') || $relative === $excl) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            if ($file->isFile()) {
                $phar->addFile($real, $relative);
            }
        }

        // Compress
        $phar->compress(Phar::GZ);

        // PharData creates .tar then .tar.gz — clean up the intermediate .tar
        $tar_file = str_replace('.tar.gz', '.tar', $backup_file);
        if (file_exists($tar_file)) {
            @unlink($tar_file);
        }

        return $backup_file;

    } catch (\Exception $e) {
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
 * Find and return the ordered chain of migration files needed to go from
 * $from_version to $to_version.
 * Returns an array of file paths in execution order, or empty if none needed.
 */
function updater_find_migrations(string $from_version, string $to_version): array {
    $dir = UPDATER_MIGRATIONS_DIR;
    if (!is_dir($dir)) return [];

    // Scan for all migration files
    $files = glob("{$dir}/migrate_*.sql");
    if (empty($files)) return [];

    // Parse version pairs from filenames
    $migrations = [];
    foreach ($files as $f) {
        $base = basename($f, '.sql');
        if (preg_match('/^migrate_(.+?)_(.+)$/', $base, $m)) {
            $migrations[] = [
                'from' => $m[1],
                'to'   => $m[2],
                'file' => $f,
            ];
        }
    }

    // Build the chain from $from_version → $to_version
    $chain = [];
    $current = $from_version;
    $max_hops = 50; // safety valve

    while (version_compare($current, $to_version, '<') && $max_hops-- > 0) {
        $found = false;
        foreach ($migrations as $m) {
            if ($m['from'] === $current) {
                $chain[] = $m['file'];
                $current = $m['to'];
                $found = true;
                break;
            }
        }
        if (!$found) break; // gap in chain — no migration available for this hop
    }

    return $chain;
}

/**
 * Execute a chain of migration SQL files against the database.
 * Returns ['success' => bool, 'applied' => [...], 'errors' => [...]]
 */
function updater_run_migrations(PDO $pdo, array $migration_files): array {
    $result = ['success' => true, 'applied' => [], 'errors' => []];

    foreach ($migration_files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            $result['errors'][] = 'Could not read: ' . basename($file);
            $result['success'] = false;
            continue;
        }

        try {
            // Split on semicolons for multi-statement migrations
            // (PDO exec handles multiple statements, but we want per-statement error tracking)
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                function($s) { return $s !== '' && !str_starts_with($s, '--'); }
            );

            $pdo->beginTransaction();

            foreach ($statements as $stmt) {
                $pdo->exec($stmt);
            }

            $pdo->commit();
            $result['applied'][] = basename($file);

        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $result['errors'][] = basename($file) . ': ' . $e->getMessage();
            $result['success'] = false;
            break; // Stop the chain on first failure
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
