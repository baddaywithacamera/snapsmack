<?php
/**
 * SNAPSMACK - Asset Sync Engine
 * Alpha v0.7.3
 *
 * Checks that all local fonts and JS engines declared in manifest-inventory.php
 * are present on disk. Any that are missing are fetched from the Smack Central
 * asset repository and verified by SHA-256 before being written to disk.
 *
 * This is a function library — it does not output HTML or handle routing.
 * Called from smack-skin.php (on skin save) and smack-update.php (post-update).
 *
 * REMOTE MANIFEST FORMAT (releases/asset-manifest.json on Smack Central):
 * {
 *   "generated": "2026-03-12T10:00:00",
 *   "assets": {
 *     "assets/fonts/BlackCasper/blackcasper.regular.ttf": {
 *       "url":    "https://snapsmack.ca/sc-assets/fonts/BlackCasper/blackcasper.regular.ttf",
 *       "sha256": "abc123...",
 *       "size":   12345
 *     },
 *     "assets/js/ss-engine-lightbox.js": { ... },
 *     "assets/css/ss-engine-glitch.css":  { ... }
 *   }
 * }
 *
 * The manifest key is the path relative to the CMS root — the same string as
 * the 'file' / 'path' / 'css' fields in manifest-inventory.php. This makes
 * the check trivially simple: does the file exist at that relative path?
 * If not, look it up in the remote manifest and fetch it.
 */

define('ASSET_SYNC_MANIFEST_URL', 'https://snapsmack.ca/releases/asset-manifest.json');
define('ASSET_SYNC_CACHE_TTL',    3600); // seconds — 1 hour
define('ASSET_SYNC_CACHE_FILE',   dirname(__DIR__) . '/backups/asset-manifest-cache.json');


// ─── MANIFEST FETCH ─────────────────────────────────────────────────────────

/**
 * Return the remote asset manifest as an associative array.
 * Uses a 1-hour on-disk cache so every skin save doesn't hit the network.
 * Returns an empty array on network failure (non-fatal — sync just skips).
 */
function asset_sync_get_manifest(): array {
    $cache = ASSET_SYNC_CACHE_FILE;

    if (file_exists($cache) && (time() - filemtime($cache)) < ASSET_SYNC_CACHE_TTL) {
        $data = json_decode(file_get_contents($cache), true);
        if (is_array($data)) return $data;
    }

    $json = _asset_sync_http_get(ASSET_SYNC_MANIFEST_URL);
    if ($json === false) return [];

    $data = json_decode($json, true);
    if (!is_array($data)) return [];

    // Cache to backups/ — directory guaranteed to exist (updater also uses it)
    $dir = dirname($cache);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($cache, $json);

    return $data;
}

/**
 * Bust the cached manifest. Call after a successful update so the next
 * skin-save or manual sync fetches a fresh copy of the remote manifest.
 */
function asset_sync_bust_cache(): void {
    if (file_exists(ASSET_SYNC_CACHE_FILE)) {
        @unlink(ASSET_SYNC_CACHE_FILE);
    }
}


// ─── DEPENDENCY CHECK ───────────────────────────────────────────────────────

/**
 * Scan every asset declared in manifest-inventory.php against the local filesystem.
 * Returns an array of missing items; empty array = everything is present.
 *
 * Each item:
 *   [
 *     'rel_path'   => 'assets/fonts/BlackCasper/blackcasper.regular.ttf',
 *     'local_path' => '/abs/path/to/assets/fonts/BlackCasper/blackcasper.regular.ttf',
 *     'remote'     => [ 'url' => '...', 'sha256' => '...', 'size' => 0 ] | null,
 *   ]
 */
function asset_sync_check_all(): array {
    $root      = dirname(__DIR__);
    $inventory = include __DIR__ . '/manifest-inventory.php';
    $manifest  = asset_sync_get_manifest();
    $remote_assets = $manifest['assets'] ?? [];

    $missing = [];

    // ── Local fonts ───────────────────────────────────────────────────────────
    foreach ($inventory['local_fonts'] ?? [] as $key => $font) {
        $rel   = ltrim($font['file'], '/');
        $local = $root . '/' . $rel;
        if (!file_exists($local)) {
            $missing[] = [
                'rel_path'   => $rel,
                'local_path' => $local,
                'remote'     => $remote_assets[$rel] ?? null,
            ];
        }
    }

    // ── JS engines ────────────────────────────────────────────────────────────
    foreach ($inventory['scripts'] ?? [] as $key => $script) {
        $rel   = ltrim($script['path'], '/');
        $local = $root . '/' . $rel;
        if (!file_exists($local)) {
            $missing[] = [
                'rel_path'   => $rel,
                'local_path' => $local,
                'remote'     => $remote_assets[$rel] ?? null,
            ];
        }

        // Optional companion CSS file
        if (!empty($script['css'])) {
            $css_rel   = ltrim($script['css'], '/');
            $css_local = $root . '/' . $css_rel;
            if (!file_exists($css_local)) {
                $missing[] = [
                    'rel_path'   => $css_rel,
                    'local_path' => $css_local,
                    'remote'     => $remote_assets[$css_rel] ?? null,
                ];
            }
        }
    }

    return $missing;
}


// ─── FETCH ───────────────────────────────────────────────────────────────────

/**
 * Download and install all items returned by asset_sync_check_all().
 *
 * Returns:
 *   [
 *     'fetched' => ['filename', ...],   — successfully downloaded + installed
 *     'failed'  => ['filename (reason)', ...], — remote had it but fetch/write failed
 *     'skipped' => ['filename', ...],   — not in remote manifest; nothing we can do
 *   ]
 */
function asset_sync_fetch(array $missing): array {
    $result = ['fetched' => [], 'failed' => [], 'skipped' => []];

    foreach ($missing as $item) {
        $fname = basename($item['rel_path']);

        if (empty($item['remote']['url'])) {
            $result['skipped'][] = $fname;
            continue;
        }

        $url      = $item['remote']['url'];
        $expected = $item['remote']['sha256'] ?? '';
        $dest     = $item['local_path'];

        // Create parent directory if needed
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $result['failed'][] = $fname . ' (cannot create directory)';
            continue;
        }

        // Download
        $content = _asset_sync_http_get($url);
        if ($content === false) {
            $result['failed'][] = $fname . ' (download failed)';
            continue;
        }

        // Checksum verification (skip if not provided — forward compat)
        if ($expected !== '' && !hash_equals(strtolower($expected), strtolower(hash('sha256', $content)))) {
            $result['failed'][] = $fname . ' (checksum mismatch — rejected)';
            continue;
        }

        // Write
        if (@file_put_contents($dest, $content) === false) {
            $result['failed'][] = $fname . ' (write failed)';
            continue;
        }

        $result['fetched'][] = $fname;
    }

    return $result;
}


// ─── CONVENIENCE WRAPPER ────────────────────────────────────────────────────

/**
 * Check all assets, fetch any that are missing, and return a summary string
 * suitable for appending to an admin flash message.
 *
 * Returns null if everything was already present (nothing to report).
 * Returns a plain-text string if any sync activity occurred.
 */
function asset_sync_run(): ?string {
    $missing = asset_sync_check_all();
    if (empty($missing)) return null;

    $result = asset_sync_fetch($missing);
    $parts  = [];

    if (!empty($result['fetched'])) {
        $n      = count($result['fetched']);
        $parts[] = "{$n} missing asset" . ($n === 1 ? '' : 's') . " fetched from remote";
    }
    if (!empty($result['failed'])) {
        $parts[] = count($result['failed']) . " failed: " . implode(', ', $result['failed']);
    }
    if (!empty($result['skipped'])) {
        $parts[] = count($result['skipped']) . " not in remote repo: " . implode(', ', $result['skipped']);
    }

    return implode('; ', $parts) ?: null;
}


// ─── INTERNAL HTTP ──────────────────────────────────────────────────────────

/**
 * HTTP GET via cURL or file_get_contents fallback.
 * Returns response body string or false on failure.
 */
function _asset_sync_http_get(string $url): string|false {
    $ua = 'SnapSmack-AssetSync/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0.0');

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => $ua,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? $body : false;
    }

    $ctx = stream_context_create([
        'http' => ['timeout' => 60, 'user_agent' => $ua],
        'ssl'  => ['verify_peer' => true],
    ]);
    return @file_get_contents($url, false, $ctx);
}
