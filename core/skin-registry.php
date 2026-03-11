<?php
/**
 * SNAPSMACK - Skin Registry Helper
 * Alpha v0.7.1
 *
 * Backend functions for the skin gallery system. Handles fetching the remote
 * skin registry, comparing against locally installed skins, downloading and
 * extracting skin packages, verifying Ed25519 signatures, and removing skins.
 *
 * The remote registry is a JSON file hosted at a configurable URL. Each skin
 * entry includes metadata, a download URL, an optional Ed25519 signature, and
 * a status field (stable / beta / development).
 *
 * Development skins are displayed in the gallery but cannot be installed.
 * This prevents users from grabbing broken or incomplete work.
 */

// --- REGISTRY FORMAT ---
// The remote registry JSON must follow this structure:
//
// {
//   "registry_version": 1,
//   "generated": "2026-03-03T12:00:00Z",
//   "skins": {
//     "skin_slug": {
//       "name": "Human-Readable Name",
//       "version": "5.5",
//       "status": "stable",            // stable | beta | development
//       "author": "Author Name",
//       "description": "Short description.",
//       "screenshot": "https://url/to/screenshot.png",
//       "download_url": "https://url/to/skin-slug-5.5.zip",
//       "download_size": 245000,        // bytes (optional)
//       "signature": "hex-ed25519-sig", // optional until signing is live
//       "requires_php": "8.0",
//       "requires_snapsmack": "0.7",
//       "features": {
//         "supports_wall": true,
//         "archive_layouts": ["square", "cropped", "masonry"]
//       }
//     }
//   }
// }

// Default registry URL — can be overridden via snap_settings.skin_registry_url
define('SKIN_REGISTRY_DEFAULT_URL', 'https://snapsmack.ca/skins/registry.json');

// Where skins live on disk
define('SKINS_DIR', dirname(__DIR__) . '/skins');

/**
 * Fetch the remote skin registry JSON.
 *
 * Tries cURL first, falls back to file_get_contents. Returns decoded array
 * on success, or ['error' => 'message'] on failure. Results are cached in
 * the session for 10 minutes to avoid hammering the server on every page load.
 *
 * @param string $registry_url  URL to the registry JSON
 * @return array  Decoded registry or ['error' => '...']
 */
function skin_registry_fetch(string $registry_url): array {

    // Session cache: avoid re-fetching on every page load
    $cache_key = 'skin_registry_cache';
    $cache_ttl = 600; // 10 minutes

    if (isset($_SESSION[$cache_key]) && ($_SESSION[$cache_key]['fetched'] ?? 0) > time() - $cache_ttl) {
        return $_SESSION[$cache_key]['data'];
    }

    $json = false;

    // Try cURL first
    if (function_exists('curl_init')) {
        $ch = curl_init($registry_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SnapSmack/' . (SNAPSMACK_VERSION_SHORT ?? '0.7'),
        ]);
        $json = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code !== 200 || $json === false) {
            $json = false;
        }
    }

    // Fallback: file_get_contents
    if ($json === false && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => 'SnapSmack/' . (SNAPSMACK_VERSION_SHORT ?? '0.7'),
            ]
        ]);
        $json = @file_get_contents($registry_url, false, $ctx);
    }

    if ($json === false) {
        return ['error' => 'Could not reach the skin registry. Check your server\'s outbound HTTPS.'];
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['skins'])) {
        return ['error' => 'Invalid registry format. Expected JSON with a "skins" key.'];
    }

    // Cache in session
    $_SESSION[$cache_key] = ['fetched' => time(), 'data' => $data];

    return $data;
}

/**
 * Clear the session-cached registry so the next load fetches fresh data.
 */
function skin_registry_clear_cache(): void {
    unset($_SESSION['skin_registry_cache']);
}

/**
 * Scan installed skins and return their manifest metadata.
 *
 * Returns an associative array keyed by skin slug. Each entry contains the
 * manifest's top-level fields (name, version, status, author, etc.) without
 * the full options tree.
 *
 * @return array  ['slug' => ['name'=>..., 'version'=>..., 'status'=>...], ...]
 */
function skin_registry_local(): array {
    $installed = [];
    $dirs = array_filter(glob(SKINS_DIR . '/*'), 'is_dir');

    foreach ($dirs as $dir) {
        $slug = basename($dir);
        $manifest_path = $dir . '/manifest.php';

        if (!file_exists($manifest_path)) continue;

        $manifest = include $manifest_path;
        if (!is_array($manifest)) continue;

        $installed[$slug] = [
            'name'        => $manifest['name'] ?? ucfirst($slug),
            'version'     => $manifest['version'] ?? '0.0',
            'status'      => $manifest['status'] ?? 'stable',
            'author'      => $manifest['author'] ?? 'Unknown',
            'description' => $manifest['description'] ?? '',
            'features'    => $manifest['features'] ?? [],
            'has_variants' => !empty($manifest['variants']),
        ];
    }

    return $installed;
}

/**
 * Compare remote registry against locally installed skins.
 *
 * Returns each remote skin enriched with:
 *   'installed'       => bool
 *   'local_version'   => string|null
 *   'update_available' => bool
 *   'installable'     => bool  (false if status === 'development')
 *
 * @param array $registry   Decoded registry JSON
 * @param array $local      Output of skin_registry_local()
 * @return array  Enriched skin list keyed by slug
 */
function skin_registry_compare(array $registry, array $local): array {
    $result = [];

    foreach (($registry['skins'] ?? []) as $slug => $remote) {
        $is_installed = isset($local[$slug]);
        $local_ver    = $local[$slug]['version'] ?? null;
        $remote_ver   = $remote['version'] ?? '0.0';

        $result[$slug] = array_merge($remote, [
            'installed'        => $is_installed,
            'local_version'    => $local_ver,
            'update_available' => $is_installed && version_compare($remote_ver, $local_ver, '>'),
            'installable'      => ($remote['status'] ?? 'stable') !== 'development',
        ]);
    }

    return $result;
}

/**
 * Download and install a skin from the registry.
 *
 * Downloads the zip, optionally verifies the Ed25519 signature, extracts to
 * the skins directory, and validates that a manifest.php exists inside.
 *
 * @param string $slug         Skin slug (becomes the directory name)
 * @param string $download_url URL to the skin zip
 * @param string $signature    Hex-encoded Ed25519 signature (empty = skip verify)
 * @param string $public_key   Hex-encoded Ed25519 public key (from settings)
 * @return array  ['success' => bool, 'message' => string]
 */
function skin_registry_install(string $slug, string $download_url, string $signature = '', string $public_key = ''): array {

    // Sanitize slug — alphanumeric, hyphens, underscores only
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        return ['success' => false, 'message' => 'Invalid skin slug.'];
    }

    $target_dir = SKINS_DIR . '/' . $slug;
    $tmp_zip    = sys_get_temp_dir() . '/snapsmack-skin-' . $slug . '-' . time() . '.zip';

    // --- DOWNLOAD ---
    $zip_data = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($download_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SnapSmack/' . (SNAPSMACK_VERSION_SHORT ?? '0.7'),
        ]);
        $zip_data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_code !== 200) $zip_data = false;
    } elseif (ini_get('allow_url_fopen')) {
        $zip_data = @file_get_contents($download_url);
    }

    if ($zip_data === false || strlen($zip_data) < 500) {
        return ['success' => false, 'message' => 'Download failed. Check that this server can reach the skin registry.'];
    }

    // --- SIGNATURE VERIFICATION (optional) ---
    if (!empty($signature) && !empty($public_key) && function_exists('sodium_crypto_sign_verify_detached')) {
        try {
            $sig_bin = sodium_hex2bin($signature);
            $key_bin = sodium_hex2bin($public_key);
            $valid   = sodium_crypto_sign_verify_detached($sig_bin, $zip_data, $key_bin);

            if (!$valid) {
                return ['success' => false, 'message' => 'Signature verification FAILED. The download may have been tampered with.'];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Signature check error: ' . $e->getMessage()];
        }
    }

    // --- WRITE & EXTRACT ---
    file_put_contents($tmp_zip, $zip_data);

    $zip = new ZipArchive();
    if ($zip->open($tmp_zip) !== true) {
        @unlink($tmp_zip);
        return ['success' => false, 'message' => 'Failed to open the downloaded zip. It may be corrupted.'];
    }

    // Determine the top-level folder inside the zip.
    // Skin zips should either contain files at root level or inside a single
    // top-level folder named after the skin slug.
    $top_folder = null;
    $first_entry = $zip->getNameIndex(0);
    if ($first_entry !== false && substr_count(rtrim($first_entry, '/'), '/') === 0 && substr($first_entry, -1) === '/') {
        // First entry is a directory — probably the wrapper folder
        $top_folder = rtrim($first_entry, '/');
    }

    // Extract to a temp staging directory
    $staging = sys_get_temp_dir() . '/snapsmack-skin-staging-' . $slug . '-' . time();
    $zip->extractTo($staging);
    $zip->close();
    @unlink($tmp_zip);

    // If there's a wrapper folder, the actual skin files are inside it
    $source = $staging;
    if ($top_folder && is_dir($staging . '/' . $top_folder . '/manifest.php') === false
        && is_dir($staging . '/' . $top_folder)) {
        // Check if manifest.php is inside the wrapper
        if (file_exists($staging . '/' . $top_folder . '/manifest.php')) {
            $source = $staging . '/' . $top_folder;
        }
    }

    // Validate: manifest.php must exist
    if (!file_exists($source . '/manifest.php')) {
        _skin_rmdir_recursive($staging);
        return ['success' => false, 'message' => 'Invalid skin package: no manifest.php found inside the zip.'];
    }

    // If the skin directory already exists, remove it (update scenario)
    if (is_dir($target_dir)) {
        _skin_rmdir_recursive($target_dir);
    }

    // Move staging into place
    if (!rename($source, $target_dir)) {
        // Fallback: copy file by file
        _skin_copy_recursive($source, $target_dir);
    }

    // Clean up staging leftovers
    if (is_dir($staging)) {
        _skin_rmdir_recursive($staging);
    }

    // Final check
    if (!file_exists($target_dir . '/manifest.php')) {
        return ['success' => false, 'message' => 'Installation failed — manifest.php not found after extraction.'];
    }

    return ['success' => true, 'message' => 'Skin "' . $slug . '" installed successfully.'];
}

/**
 * Remove an installed skin.
 *
 * Refuses to remove the currently active skin. Deletes the entire skin
 * directory from disk.
 *
 * @param string $slug        Skin slug to remove
 * @param string $active_skin Currently active skin slug
 * @return array  ['success' => bool, 'message' => string]
 */
function skin_registry_remove(string $slug, string $active_skin): array {

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        return ['success' => false, 'message' => 'Invalid skin slug.'];
    }

    if ($slug === $active_skin) {
        return ['success' => false, 'message' => 'Cannot remove the active skin. Switch to a different skin first.'];
    }

    $target = SKINS_DIR . '/' . $slug;

    if (!is_dir($target)) {
        return ['success' => false, 'message' => 'Skin directory not found.'];
    }

    _skin_rmdir_recursive($target);

    if (is_dir($target)) {
        return ['success' => false, 'message' => 'Failed to remove skin directory. Check file permissions.'];
    }

    return ['success' => true, 'message' => 'Skin "' . $slug . '" removed.'];
}


// --- INTERNAL HELPERS ---

/**
 * Recursively delete a directory and its contents.
 */
function _skin_rmdir_recursive(string $dir): void {
    if (!is_dir($dir)) return;
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) ? _skin_rmdir_recursive($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Recursively copy a directory tree.
 */
function _skin_copy_recursive(string $src, string $dst): void {
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    $items = array_diff(scandir($src), ['.', '..']);
    foreach ($items as $item) {
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        is_dir($s) ? _skin_copy_recursive($s, $d) : copy($s, $d);
    }
}
