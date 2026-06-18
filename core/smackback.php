<?php
// SNAPSMACK_EOF_HEADER
//     
// ─── HUB BREACH REPORTING (Phase 2 — paranoid mode) ────────────────────────

/**
 * Report a local breach to the multisite hub.
 * Called only on spokes running in paranoid mode.
 * Sends a POST to the hub's multisite/smackback/report endpoint.
 * Failures are logged but do not block local breach handling.
 *
 * @param  string[] $tampered
 * @param  string[] $missing
 * @param  string[] $truncated
 * @param  string[] $corrupted
 * @return void
 */
function smackback_hub_report(
    array $tampered,
    array $missing,
    array $truncated = [],
    array $corrupted = []
): void {
    global $pdo;

    // Find our registered hub
    $hub = $pdo->query(
        "SELECT site_url, api_key_remote FROM snap_multisite_nodes
         WHERE role = 'hub' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$hub || empty($hub['site_url']) || empty($hub['api_key_remote'])) {
        error_log('SMACKBACK: No hub configured or missing API key — breach not reported to hub');
        return;
    }

    $url     = rtrim($hub['site_url'], '/') . '/api.php?route=multisite/smackback/report';
    $payload = json_encode([
        'tampered'    => $tampered,
        'missing'     => $missing,
        'truncated'   => $truncated,
        'corrupted'   => $corrupted,
        'detected_at' => date('c'),
    ]);

    if (!function_exists('curl_init')) {
        // No cURL — skip hub report (rare on modern PHP, log and bail)
        error_log('SMACKBACK: cURL unavailable — hub breach report skipped');
        return;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $hub['api_key_remote'],
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if (!$result || $code !== 200) {
        error_log("SMACKBACK: Hub breach report failed — HTTP {$code} {$err}");
        return;
    }

    $data = json_decode($result, true);
    if (!empty($data['breach_count']) && (int)$data['breach_count'] >= 2) {
        // Hub has flagged a coordinated breach — note it locally
        error_log("SMACKBACK: Hub reports coordinated breach on {$data['breach_count']} spokes");
    }
}

// ─── SKIN JS SECURITY SCAN ───────────────────────────────────────────────────

/**
 * External script hosts that non-base skins are allowed to load from.
 * Anything not on this list is flagged as a violation (or warning if custom JS is enabled).
 */
if (!defined('SMACKBACK_JS_TRUSTED_HOSTS')) {
    define('SMACKBACK_JS_TRUSTED_HOSTS', [
        'cdnjs.cloudflare.com',
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'code.jquery.com',
        'cdn.jsdelivr.net',
        'unpkg.com',
    ]);
}

/**
 * Core skins that ship with the base release and are always trusted.
 * Never scanned for JS violations.
 */
if (!defined('SMACKBACK_BASE_SKINS')) {
    define('SMACKBACK_BASE_SKINS', ['50-shades-of-noah-grey', 'new-horizon']);
}

/**
 * Scan all non-base installed skins for inline or unauthorized JS.
 *
 * Severity levels:
 *   'violation' — eval(), external script from untrusted host
 *   'warning'   — atob(), document.write(), inline <script> block
 *   'info'      — inline <script> when skin_allow_custom_js is enabled
 *
 * Each finding array:
 *   ['skin', 'file', 'line', 'type', 'detail', 'severity']
 *
 * @param  bool $allow_custom  If true (skin_allow_custom_js=1), demote violations → warnings,
 *                             inline scripts → info. eval() is never demoted.
 * @return array
 */
function smackback_scan_skin_js(bool $allow_custom = false): array {
    $skins_dir = SNAPSMACK_ROOT . DIRECTORY_SEPARATOR . 'skins';
    if (!is_dir($skins_dir)) {
        return [];
    }

    $findings = [];

    // Resolve the canonical skins root once. Used below to guard against symlink escape —
    // a symlink inside a skin dir could otherwise point to an arbitrary file on the server.
    $skins_real = realpath($skins_dir);

    foreach (scandir($skins_dir) as $skin_slug) {
        if ($skin_slug === '.' || $skin_slug === '..') continue;
        $skin_path = $skins_dir . DIRECTORY_SEPARATOR . $skin_slug;
        if (!is_dir($skin_path)) continue;
        if (in_array($skin_slug, SMACKBACK_BASE_SKINS, true)) continue;

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($skin_path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iter as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['php', 'html', 'htm', 'js'], true)) continue;

            $abs = $file->getPathname();
            $abs_fwd = str_replace('\\', '/', $abs);

            // Skip design-reference / gitignored dirs inside skins
            if (strpos($abs_fwd, 'reference work from Claude Design') !== false) continue;
            if (strpos($abs_fwd, '/.git/') !== false) continue;

            // Symlink escape guard: resolve the real path and confirm it's still inside skins/.
            // RecursiveDirectoryIterator without FOLLOW_SYMLINKS will traverse symlinks to files
            // (though not to directories). A malicious or accidental symlink could point outside
            // the skins tree. If realpath() returns false (broken symlink) or resolves to a path
            // outside skins_real, skip silently.
            if ($skins_real !== false) {
                $real_abs = realpath($abs);
                if ($real_abs === false || strpos($real_abs, $skins_real) !== 0) continue;
            }

            $lines = @file($abs, FILE_IGNORE_NEW_LINES);
            if ($lines === false) continue;

            $skin_path_fwd = str_replace('\\', '/', $skin_path);
            $rel_file = 'skins/' . $skin_slug . '/' . ltrim(
                str_replace($skin_path_fwd, '', $abs_fwd),
                '/'
            );

            foreach ($lines as $i => $line) {
                $lnum = $i + 1;

                // ── External <script src="..."> from untrusted host ─────────
                if (preg_match('/<script\b[^>]*\bsrc=["\']https?:\/\/([^\/\'">\s]+)/i', $line, $m)) {
                    $host = strtolower($m[1]);
                    $trusted = false;
                    foreach (SMACKBACK_JS_TRUSTED_HOSTS as $th) {
                        if ($host === $th || str_ends_with($host, '.' . $th)) {
                            $trusted = true;
                            break;
                        }
                    }
                    if (!$trusted) {
                        $findings[] = [
                            'skin'     => $skin_slug,
                            'file'     => $rel_file,
                            'line'     => $lnum,
                            'type'     => 'external_script',
                            'detail'   => "External script from untrusted host: {$host}",
                            'severity' => $allow_custom ? 'warning' : 'violation',
                        ];
                    }
                }

                // ── eval() call — never demoted, always a violation ─────────
                if (preg_match('/\beval\s*\(/i', $line)) {
                    $findings[] = [
                        'skin'     => $skin_slug,
                        'file'     => $rel_file,
                        'line'     => $lnum,
                        'type'     => 'eval',
                        'detail'   => 'eval() call — code execution risk',
                        'severity' => 'violation',
                    ];
                }

                // ── atob() — common obfuscation tool ───────────────────────
                if (preg_match('/\batob\s*\(/i', $line)) {
                    $findings[] = [
                        'skin'     => $skin_slug,
                        'file'     => $rel_file,
                        'line'     => $lnum,
                        'type'     => 'obfuscation',
                        'detail'   => 'atob() — base64 decode, potential code obfuscation',
                        'severity' => 'warning',
                    ];
                }

                // ── document.write() ───────────────────────────────────────
                if (preg_match('/document\.write\s*\(/i', $line)) {
                    $findings[] = [
                        'skin'     => $skin_slug,
                        'file'     => $rel_file,
                        'line'     => $lnum,
                        'type'     => 'document_write',
                        'detail'   => 'document.write() — obsolete, sometimes used for injection',
                        'severity' => 'warning',
                    ];
                }

                // ── Inline <script> block (no src attribute) ───────────────
                // Matches <script> or <script type="text/javascript"> etc. but NOT <script src=...>
                if (preg_match('/<script\b(?![^>]*\bsrc=)[^>]*>/i', $line)) {
                    $findings[] = [
                        'skin'     => $skin_slug,
                        'file'     => $rel_file,
                        'line'     => $lnum,
                        'type'     => 'inline_script',
                        'detail'   => 'Inline <script> block',
                        'severity' => $allow_custom ? 'info' : 'warning',
                    ];
                }
            }
        }
    }

    return $findings;
}

/**
 * Run the skin JS scan and persist results to snap_settings.
 * Reads the skin_allow_custom_js setting to set severity context.
 *
 * @return array{violations: int, warnings: int, infos: int, findings: array, scanned_at: string}
 */
function smackback_run_skin_js_scan(): array {
    global $pdo;

    $allow_custom = ($pdo->query(
        "SELECT setting_val FROM snap_settings WHERE setting_key = 'skin_allow_custom_js'"
    )->fetchColumn() ?: '0') === '1';

    $findings  = smackback_scan_skin_js($allow_custom);
    $now       = date('Y-m-d H:i:s');

    $violations = count(array_filter($findings, fn($f) => $f['severity'] === 'violation'));
    $warnings   = count(array_filter($findings, fn($f) => $f['severity'] === 'warning'));
    $infos      = count(array_filter($findings, fn($f) => $f['severity'] === 'info'));

    $upsert = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    );
    $upsert->execute(['skin_js_violations_json',  json_encode($findings, JSON_UNESCAPED_UNICODE)]);
    $upsert->execute(['skin_js_scan_at',          $now]);
    $upsert->execute(['skin_js_violation_count',  (string) $violations]);

    return [
        'violations' => $violations,
        'warnings'   => $warnings,
        'infos'      => $infos,
        'findings'   => $findings,
        'scanned_at' => $now,
    ];
}

/**
 * SMACKBACK — File Integrity Monitoring
 *
 * SHA-256 hash verification for all monitored PHP/CSS/JS files.
 * Baseline hashes are set at install/update time from the build-signed manifest.
 * Verification triggers: admin login, cron, manual, public page stat check (optional).
 *
 * @package SnapSmack
 * @since   0.7.170
 */

if (!defined('SNAPSMACK_ROOT')) {
    define('SNAPSMACK_ROOT', dirname(__DIR__));
}

// ─── FILE INCLUSION / EXCLUSION ─────────────────────────────────────────────

/**
 * Return list of all absolute file paths that should be monitored.
 * Applies inclusion rules (PHP/CSS/JS) and exclusion path rules.
 *
 * @return string[]
 */
function smackback_get_monitored_files(): array {
    $root  = SNAPSMACK_ROOT;
    $files = [];

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iter as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }
        $abs = $file->getPathname();
        if (smackback_should_monitor($abs)) {
            $files[] = $abs;
        }
    }

    return $files;
}

/**
 * Determine if a given absolute path should be monitored.
 *
 * @param  string $abs_path  Absolute filesystem path.
 * @return bool
 */
function smackback_should_monitor(string $abs_path): bool {
    $root = SNAPSMACK_ROOT;

    // Must be under webroot
    if (strpos($abs_path, $root) !== 0) {
        return false;
    }

    // Get relative path (forward slashes, no leading slash)
    $rel = ltrim(str_replace('\\', '/', substr($abs_path, strlen($root))), '/');

    // Extension check: only PHP, CSS, JS
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (!in_array($ext, ['php', 'css', 'js'], true)) {
        return false;
    }

    // ─── Excluded directories ───────────────────────────────────────────────
    $excluded_dirs = [
        'uploads/',
        'smack-central/',
        'reference/',
        'node_modules/',
        'vendor/',
        'tools/',
        'backups/',
        'migrations/',
    ];
    foreach ($excluded_dirs as $dir) {
        if (strpos($rel, $dir) === 0) {
            return false;
        }
    }

    // ─── Excluded filename patterns ─────────────────────────────────────────
    $basename = basename($rel);

    // Installer / setup files: present on disk at install time, then self-deleted
    // immediately after (install.php @unlink(__FILE__); setup.php likewise). If they
    // get baselined, the very next scan sees them gone → false MISSING breach →
    // lockout on every fresh install. Never monitor them.
    if (in_array($basename, ['install.php', 'setup.php'], true)) {
        return false;
    }

    // Minified files
    if (str_ends_with($basename, '.min.js') || str_ends_with($basename, '.min.css')) {
        return false;
    }

    // Third-party Flickr Justified Gallery
    if (strpos($rel, 'fjGallery') !== false) {
        return false;
    }

    // Session files
    if (strpos($rel, 'sess_') === 0) {
        return false;
    }

    return true;
}

/**
 * Convert an absolute path to a relative path from webroot.
 * Always uses forward slashes.
 *
 * @param  string $abs
 * @return string
 */
function smackback_rel(string $abs): string {
    $root = SNAPSMACK_ROOT;
    return ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
}

/**
 * Convert a relative path to an absolute path.
 *
 * @param  string $rel
 * @return string
 */
function smackback_abs(string $rel): string {
    return SNAPSMACK_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

// ─── EOF SENTINEL ───────────────────────────────────────────────────────────

/**
 * Read the last non-empty line of a file (up to 512 chars).
 * Returns 'NULL_BYTES' if null bytes are found in the last 1024 bytes.
 * Returns '' if the file is empty or unreadable.
 *
 * This value is stored as the eof_signature in snap_file_manifest at baseline
 * time and compared against the live file during verification.
 *
 * @param  string $abs_path  Absolute file path.
 * @return string
 */
function smackback_get_eof_signature(string $abs_path): string {
    $size = @filesize($abs_path);
    if ($size === false || $size === 0) {
        return '';
    }

    $read_size = min($size, 1024);
    $fp        = @fopen($abs_path, 'rb');
    if (!$fp) {
        return '';
    }
    fseek($fp, -$read_size, SEEK_END);
    $tail = fread($fp, $read_size);
    fclose($fp);

    if ($tail === false) {
        return '';
    }

    // Null bytes in tail = corruption sentinel; store as special value
    if (strpos($tail, "\x00") !== false) {
        return 'NULL_BYTES';
    }

    // Return the last non-empty line (trimmed, capped at 512 chars)
    $lines = explode("\n", $tail);
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = rtrim($lines[$i]);
        if ($line !== '') {
            return substr($line, 0, 512);
        }
    }

    return '';
}

/**
 * Compare a file's current EOF state against its stored baseline signature.
 *
 * Returns:
 *   'ok'        — EOF matches baseline (or no baseline; conservative pass)
 *   'null_bytes'— Null bytes found in last 64 bytes → corruption
 *   'mismatch'  — Last line differs from baseline → truncation or replacement
 *   'unknown'   — Could not read file or no baseline stored
 *
 * @param  string      $abs_path     Absolute file path.
 * @param  string|null $expected_sig Stored eof_signature from snap_file_manifest.
 * @return string
 */
function smackback_check_eof(string $abs_path, ?string $expected_sig): string {
    if (empty($expected_sig)) {
        return 'unknown';  // No baseline — cannot compare
    }

    $size = @filesize($abs_path);
    if ($size === false || $size === 0) {
        return 'mismatch';  // File empty or gone
    }

    // Null-byte check on last 64 bytes (fast, no full tail read needed)
    $probe_size = min($size, 64);
    $fp         = @fopen($abs_path, 'rb');
    if (!$fp) {
        return 'unknown';
    }
    fseek($fp, -$probe_size, SEEK_END);
    $last_bytes = fread($fp, $probe_size);
    fclose($fp);

    if ($last_bytes !== false && strpos($last_bytes, "\x00") !== false) {
        return 'null_bytes';
    }

    // Compare last non-empty line to stored signature
    $current_sig = smackback_get_eof_signature($abs_path);

    if ($current_sig === $expected_sig) {
        return 'ok';
    }

    // Baseline itself was 'NULL_BYTES' but current file has no null bytes —
    // something changed significantly; treat as mismatch
    return 'mismatch';
}

/**
 * Classify a hash-mismatch file using the EOF signal matrix.
 *
 * Hash already known to differ. Determine why:
 *   'tampered'  — content changed, EOF intact (active modification)
 *   'truncated' — last line differs from baseline (write failure, partial transfer)
 *   'corrupted' — null bytes present (filesystem fault, atomic write failure)
 *
 * @param  string      $abs_path     Absolute path of the mismatched file.
 * @param  string|null $expected_sig Stored eof_signature.
 * @return string  'tampered'|'truncated'|'corrupted'
 */
function smackback_classify_mismatch(string $abs_path, ?string $expected_sig): string {
    $eof = smackback_check_eof($abs_path, $expected_sig);

    if ($eof === 'null_bytes') {
        return 'corrupted';
    }
    if ($eof === 'mismatch') {
        return 'truncated';
    }
    // 'ok' or 'unknown': EOF looks intact → treat as active tampering
    return 'tampered';
}

// ─── BASELINE INITIALISATION ────────────────────────────────────────────────

/**
 * Read smackback-manifest.json from a verified release or skin ZIP.
 * UPSERTs all entries into snap_file_manifest.
 * Called by core/updater.php after successful update.
 * Called by skin-registry.php after skin install/update.
 *
 * @param  string      $zip_path  Absolute path to verified ZIP file.
 * @param  string|null $skin_id   Null for core release; skin slug for skin packages.
 * @return bool
 */
function smackback_init_manifest(string $zip_path, ?string $skin_id = null): bool {
    global $pdo;

    if (!file_exists($zip_path)) {
        error_log("SMACKBACK: ZIP not found at {$zip_path}");
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        error_log("SMACKBACK: Cannot open ZIP at {$zip_path}");
        return false;
    }

    $manifest_json = $zip->getFromName('smackback-manifest.json');
    $zip->close();

    if ($manifest_json === false) {
        // No manifest in this package (pre-0.7.170 package during upgrade path)
        error_log("SMACKBACK: No smackback-manifest.json in {$zip_path}");
        return false;
    }

    $manifest = json_decode($manifest_json, true);
    if (!is_array($manifest) || empty($manifest['files'])) {
        error_log('SMACKBACK: Invalid or empty manifest JSON');
        return false;
    }

    $now   = date('Y-m-d H:i:s');
    $count = 0;

    // snap_file_manifest may not exist yet on a spoke whose schema is behind
    // canonical (the table is created by the schema sync). A hub-pushed update
    // calls this BEFORE the sync has necessarily created it, so an unguarded
    // prepare here threw an uncaught PDOException and 500'd the whole update
    // endpoint — the same missing-table footgun that was fixed in
    // smackback_verify_all(). Treat a missing manifest table as "nothing to
    // baseline here" and let the update proceed; the schema sync creates the
    // table and SMACKBACK initialises on the next arm/verify.
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO snap_file_manifest
                 (file_path, expected_hash, file_size, eof_signature, skin_id, baseline_set, last_status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')
             ON DUPLICATE KEY UPDATE
                 expected_hash  = VALUES(expected_hash),
                 file_size      = VALUES(file_size),
                 eof_signature  = VALUES(eof_signature),
                 skin_id        = VALUES(skin_id),
                 baseline_set   = VALUES(baseline_set),
                 last_status    = 'pending',
                 last_verified  = NULL,
                 expected_mtime = NULL"
        );

        foreach ($manifest['files'] as $path => $info) {
            if (empty($info['hash']) || !isset($info['size'])) {
                continue;
            }
            // Defence in depth: a CORE package manifest must never carry skin rows.
            // Skins are monitored via their own skin_id rows (Skin Packager); a stray
            // skins/ path here is exactly what false-breached the fleet on a core update.
            if (str_starts_with($path, 'skins/')) {
                continue;
            }
            // Use eof_signature from manifest JSON if present (build pipeline sets this).
            // Fall back to computing from installed file on disk.
            $eof_sig = $info['eof_signature'] ?? null;
            if ($eof_sig === null) {
                $abs_tmp = smackback_abs($path);
                $eof_sig = file_exists($abs_tmp) ? smackback_get_eof_signature($abs_tmp) : null;
            }
            $stmt->execute([
                $path,
                $info['hash'],
                (int) $info['size'],
                $eof_sig ?: null,
                $skin_id,
                $now,
            ]);
            $count++;
        }
    } catch (PDOException $e) {
        error_log('SMACKBACK: init_manifest skipped — ' . $e->getMessage());
        return false;
    }

    error_log("SMACKBACK: Manifest loaded — {$count} files from " . basename($zip_path));
    return $count > 0;
}

/**
 * Hash all currently monitored files on disk and upsert into snap_file_manifest.
 * Used on fresh install where no ZIP is retained post-extraction.
 * Files on disk at this point came from an Ed25519-verified package.
 *
 * @return bool
 */
function smackback_init_from_disk(): bool {
    global $pdo;

    $files = smackback_get_monitored_files();
    if (empty($files)) {
        return false;
    }

    $now   = date('Y-m-d H:i:s');
    $count = 0;
    $seen  = [];

    // snap_file_manifest may not exist on a schema-behind spoke (same missing-table
    // footgun guarded in init_manifest / verify_all). Without this, a "Re-initialise
    // baseline from disk" — exactly the in-admin recovery step run on poisoned spokes
    // — would 500 instead of recovering. Treat a missing table as "cannot baseline
    // yet" and bail cleanly; the schema sync creates it.
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO snap_file_manifest
                 (file_path, expected_hash, file_size, eof_signature, skin_id, baseline_set, last_status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')
             ON DUPLICATE KEY UPDATE
                 expected_hash  = VALUES(expected_hash),
                 file_size      = VALUES(file_size),
                 eof_signature  = VALUES(eof_signature),
                 skin_id        = VALUES(skin_id),
                 baseline_set   = VALUES(baseline_set),
                 last_status    = 'pending',
                 last_verified  = NULL,
                 expected_mtime = NULL"
        );

        foreach ($files as $abs) {
            $rel     = smackback_rel($abs);
            $hash    = hash_file('sha256', $abs);
            $size    = filesize($abs);
            $eof_sig = smackback_get_eof_signature($abs);
            if ($hash === false || $size === false) {
                continue;
            }
            // Preserve skin attribution so a re-baseline of a 'skins/<slug>/...' file
            // keeps its skin_id instead of orphaning it under NULL.
            $skin_id = null;
            if (preg_match('#^skins/([^/]+)/#', $rel, $m)) {
                $skin_id = $m[1];
            }
            $stmt->execute([$rel, $hash, $size, $eof_sig ?: null, $skin_id, $now]);
            $seen[$rel] = true;
            $count++;
        }

        // Prune orphaned rows — anything still in the manifest that is no longer a
        // monitored file on disk. A re-baseline declares the current disk authoritative;
        // without this prune a stale/poisoned row (e.g. a skin file an OLD core package
        // shipped but the live, separately-deployed skin no longer has) survives every
        // re-baseline and keeps re-tripping the breach, so the in-admin recovery never
        // sticks. That was the 0.7.262 lockout trap — VAX was the only way out.
        if ($seen) {
            $pruned = 0;
            $del    = $pdo->prepare("DELETE FROM snap_file_manifest WHERE id = ?");
            foreach ($pdo->query("SELECT id, file_path FROM snap_file_manifest")->fetchAll(PDO::FETCH_ASSOC) as $erow) {
                if (!isset($seen[$erow['file_path']])) {
                    $del->execute([(int) $erow['id']]);
                    $pruned++;
                }
            }
            if ($pruned > 0) {
                error_log("SMACKBACK: Disk baseline pruned {$pruned} orphaned manifest row(s)");
            }
        }
    } catch (PDOException $e) {
        error_log('SMACKBACK: init_from_disk skipped — ' . $e->getMessage());
        return false;
    }

    error_log("SMACKBACK: Disk baseline set — {$count} files");
    if ($count > 0) {
        // A fresh disk baseline is authoritative — the site is clean by definition.
        // Lets a re-baseline (or first arm) flip smackback_status pending→clean so the
        // hub dashboard reflects reality without waiting for a breach→resolve cycle.
        smackback_mark_clean();
    }
    return $count > 0;
}

/**
 * Add or refresh hash manifest entries for a specific skin's files.
 * Called by skin-registry.php after skin install or update.
 *
 * @param  string $zip_path  Absolute path to verified skin ZIP.
 * @param  string $skin_id   Skin slug.
 * @return bool
 */
function smackback_init_skin_manifest(string $zip_path, string $skin_id): bool {
    return smackback_init_manifest($zip_path, $skin_id);
}

/**
 * Remove all manifest entries for a skin.
 * Called by skin-registry.php on skin uninstall.
 *
 * @param  string $skin_id  Skin slug.
 * @return void
 */
function smackback_remove_skin_manifest(string $skin_id): void {
    global $pdo;
    $pdo->prepare("DELETE FROM snap_file_manifest WHERE skin_id = ?")
        ->execute([$skin_id]);
}

// ─── VERIFICATION ────────────────────────────────────────────────────────────

/**
 * Full verification pass: hash every file in snap_file_manifest.
 * Compares against expected_hash. Updates last_verified, last_status.
 * Stores mtime in expected_mtime on first successful verification.
 *
 * @return array{
 *   status:    'clean'|'breach',
 *   tampered:  string[],
 *   truncated: string[],
 *   corrupted: string[],
 *   missing:   string[],
 *   ok:        int,
 *   checked:   int,
 *   duration:  float
 * }
 */
function smackback_verify_all(): array {
    global $pdo;

    $t_start = microtime(true);

    // SMACKBACK may never have been armed on this install (no manifest table yet).
    // Treat that as "nothing to verify / clean" rather than fataling the admin
    // dashboard, which calls this on load (smack-admin.php).
    try {
        $rows = $pdo->query(
            "SELECT file_path, expected_hash, expected_mtime, eof_signature FROM snap_file_manifest"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [
            'status'    => 'clean',
            'tampered'  => [],
            'truncated' => [],
            'corrupted' => [],
            'missing'   => [],
            'ok'        => 0,
            'checked'   => 0,
            'duration'  => 0.0,
        ];
    }

    $tampered  = [];
    $truncated = [];
    $corrupted = [];
    $missing   = [];
    $ok_count  = 0;
    $now       = date('Y-m-d H:i:s');

    $stmt_update = $pdo->prepare(
        "UPDATE snap_file_manifest
         SET last_verified  = ?,
             last_status    = ?,
             expected_mtime = COALESCE(expected_mtime, ?)
         WHERE file_path = ?"
    );

    foreach ($rows as $row) {
        $rel = $row['file_path'];
        $abs = smackback_abs($rel);

        if (!file_exists($abs)) {
            $missing[] = $rel;
            $stmt_update->execute([$now, 'missing', null, $rel]);
            continue;
        }

        $hash  = hash_file('sha256', $abs);
        $mtime = filemtime($abs);

        if ($hash === $row['expected_hash']) {
            $ok_count++;
            $stmt_update->execute([
                $now,
                'ok',
                $row['expected_mtime'] ?? $mtime,
                $rel,
            ]);
        } else {
            // Hash mismatch — use EOF Sentinel to classify cause
            $kind = smackback_classify_mismatch($abs, $row['eof_signature'] ?? null);
            switch ($kind) {
                case 'truncated': $truncated[] = $rel; break;
                case 'corrupted': $corrupted[] = $rel; break;
                default:          $tampered[]  = $rel; break;
            }
            $stmt_update->execute([$now, $kind, $row['expected_mtime'], $rel]);
        }
    }

    $duration = round(microtime(true) - $t_start, 3);
    $any_bad  = !empty($tampered) || !empty($truncated) || !empty($corrupted) || !empty($missing);

    // A clean pass over a populated manifest promotes pending/unknown → clean so the
    // hub dashboard stops showing PENDING forever on armed-but-never-breached spokes.
    // Guarded inside mark_clean: an active breach is left for resolve_breach (logged).
    if (!$any_bad && count($rows) > 0) {
        smackback_mark_clean();
    }

    return [
        'status'    => $any_bad ? 'breach' : 'clean',
        'tampered'  => $tampered,
        'truncated' => $truncated,
        'corrupted' => $corrupted,
        'missing'   => $missing,
        'ok'        => $ok_count,
        'checked'   => count($rows),
        'duration'  => $duration,
    ];
}

/**
 * Fast check: stat() all monitored files, compare mtime to expected_mtime.
 * If any mtime differs, triggers smackback_verify_file() on changed files only.
 * Used on public page loads. No file reads — filesystem metadata only.
 * On breach: calls smackback_handle_breach() internally (silent, no public interruption).
 *
 * @return bool  True = clean (or no mtime data yet), false = breach detected.
 */
function smackback_verify_quick(): bool {
    global $pdo;

    // Only run if mtime data exists (after first full verify)
    $rows = $pdo->query(
        "SELECT file_path, expected_mtime FROM snap_file_manifest
         WHERE expected_mtime IS NOT NULL"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return true;  // No mtime data yet — skip
    }

    $changed = [];
    foreach ($rows as $row) {
        $abs = smackback_abs($row['file_path']);
        if (!file_exists($abs)) {
            $changed[] = $row['file_path'];
            continue;
        }
        if (filemtime($abs) !== (int) $row['expected_mtime']) {
            $changed[] = $row['file_path'];
        }
    }

    if (empty($changed)) {
        return true;
    }

    // mtime changed — run full hash on changed files only
    $tampered = [];
    $missing  = [];
    $now      = date('Y-m-d H:i:s');

    $stmt_expected = $pdo->prepare(
        "SELECT expected_hash, eof_signature FROM snap_file_manifest WHERE file_path = ?"
    );
    $stmt_update = $pdo->prepare(
        "UPDATE snap_file_manifest SET last_verified = ?, last_status = ? WHERE file_path = ?"
    );

    $truncated = [];
    $corrupted = [];

    foreach ($changed as $rel) {
        $abs = smackback_abs($rel);
        if (!file_exists($abs)) {
            $missing[] = $rel;
            $stmt_update->execute([$now, 'missing', $rel]);
            continue;
        }
        $stmt_expected->execute([$rel]);
        $mrow = $stmt_expected->fetch(PDO::FETCH_ASSOC);
        if (!$mrow) {
            continue;
        }
        $actual_hash = hash_file('sha256', $abs);
        if ($actual_hash !== $mrow['expected_hash']) {
            $kind = smackback_classify_mismatch($abs, $mrow['eof_signature'] ?? null);
            switch ($kind) {
                case 'truncated': $truncated[] = $rel; break;
                case 'corrupted': $corrupted[] = $rel; break;
                default:          $tampered[]  = $rel; break;
            }
            $stmt_update->execute([$now, $kind, $rel]);
        } else {
            // mtime changed but hash is still good — update mtime baseline
            $new_mtime = filemtime($abs);
            $pdo->prepare(
                "UPDATE snap_file_manifest SET last_verified = ?, last_status = 'ok', expected_mtime = ? WHERE file_path = ?"
            )->execute([$now, $new_mtime, $rel]);
        }
    }

    $any_bad = !empty($tampered) || !empty($truncated) || !empty($corrupted) || !empty($missing);
    if ($any_bad) {
        smackback_handle_breach($tampered, $missing, $truncated, $corrupted);
        return false;
    }

    return true;
}

/**
 * Hash a single file and compare to its expected_hash.
 * Uses EOF Sentinel to classify mismatches.
 *
 * Returns: 'ok' | 'tampered' | 'truncated' | 'corrupted' | 'missing'
 *
 * @param  string $relative_path  Path relative to webroot.
 * @return string
 */
function smackback_verify_file(string $relative_path): string {
    global $pdo;

    $abs = smackback_abs($relative_path);

    if (!file_exists($abs)) {
        return 'missing';
    }

    $row = $pdo->prepare(
        "SELECT expected_hash, eof_signature FROM snap_file_manifest WHERE file_path = ?"
    );
    $row->execute([$relative_path]);
    $mrow = $row->fetch(PDO::FETCH_ASSOC);

    if (!$mrow) {
        return 'missing';  // Not in manifest
    }

    $actual = hash_file('sha256', $abs);
    if ($actual === $mrow['expected_hash']) {
        return 'ok';
    }

    return smackback_classify_mismatch($abs, $mrow['eof_signature'] ?? null);
}

// ─── BREACH HANDLING ────────────────────────────────────────────────────────

/**
 * Quick breach check for admin page gatekeeping.
 * Reads only from snap_settings — no disk access.
 *
 * @return bool
 */
function smackback_is_breach(): bool {
    global $pdo;
    $row = $pdo->prepare(
        "SELECT setting_val FROM snap_settings WHERE setting_key = 'smackback_status'"
    );
    $row->execute();
    return $row->fetchColumn() === 'breach';
}

/**
 * Record a breach: update settings, fire email.
 * In paranoid mode: stubs the hub report hook (Phase 2).
 *
 * @param  string[] $tampered   Relative paths of tampered files.
 * @param  string[] $missing    Relative paths of missing files.
 * @param  string[] $truncated  Relative paths of truncated files (write failure/partial transfer).
 * @param  string[] $corrupted  Relative paths of corrupted files (null bytes/filesystem fault).
 * @return void
 */
function smackback_handle_breach(
    array $tampered,
    array $missing,
    array $truncated = [],
    array $corrupted = []
): void {
    global $pdo;

    $now = date('Y-m-d H:i:s');

    // Build affected file list for settings storage
    $breach_files = json_encode(array_merge(
        array_map(fn($p) => ['path' => $p, 'status' => 'tampered'],  $tampered),
        array_map(fn($p) => ['path' => $p, 'status' => 'missing'],   $missing),
        array_map(fn($p) => ['path' => $p, 'status' => 'truncated'], $truncated),
        array_map(fn($p) => ['path' => $p, 'status' => 'corrupted'], $corrupted)
    ));

    $upsert = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    );

    // Only update breach_at if not already in breach (don't reset the original timestamp)
    $current_status = $pdo->query(
        "SELECT setting_val FROM snap_settings WHERE setting_key = 'smackback_status'"
    )->fetchColumn();

    if ($current_status !== 'breach') {
        $upsert->execute(['smackback_breach_at', $now]);
    }

    $upsert->execute(['smackback_status',       'breach']);
    $upsert->execute(['smackback_breach_files', $breach_files]);

    // Send alert email
    smackback_send_alert($tampered, $missing, $truncated, $corrupted);

    // Phase 2 hook: hub/spoke correlation (no-op in this build)
    $mode = $pdo->query(
        "SELECT setting_val FROM snap_settings WHERE setting_key = 'smackback_mode'"
    )->fetchColumn();

    if ($mode === 'paranoid') {
        smackback_hub_report($tampered, $missing, $truncated, $corrupted);
    }

    // Layer 2: report to Smack Central network alert system if opted in.
    // Entirely independent of hub/spoke mode. Fire-and-forget, never blocks.
    $na_path = __DIR__ . '/network-alert.php';
    if (file_exists($na_path)) {
        require_once $na_path;
        nalert_send_report($tampered, $missing, $truncated, $corrupted);
    }
}

/**
 * Clear breach state and log the incident.
 * Called after all tampered files are resolved or SMACKBACK is re-initialised.
 *
 * @param  string $resolution  How it was resolved: 'restore', 'update', 'manual', 'reinit'
 * @return void
 */
function smackback_resolve_breach(string $resolution = 'restore'): void {
    global $pdo;

    $now = date('Y-m-d H:i:s');

    // Fetch breach data before clearing it
    $stmt = $pdo->query(
        "SELECT setting_key, setting_val FROM snap_settings
         WHERE setting_key IN ('smackback_breach_at', 'smackback_breach_files')"
    );
    $breach = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $breach[$row['setting_key']] = $row['setting_val'];
    }

    $detected_at    = $breach['smackback_breach_at']    ?? $now;
    $affected_json  = $breach['smackback_breach_files'] ?? '[]';
    $affected_files = json_decode($affected_json, true) ?? [];

    // Log the incident
    $pdo->prepare(
        "INSERT INTO snap_smackback_log (detected_at, resolved_at, affected_files, file_count, resolution)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([
        $detected_at,
        $now,
        $affected_json,
        count($affected_files),
        $resolution,
    ]);

    // Clear breach state
    $upsert = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    );
    $upsert->execute(['smackback_status',             'clean']);
    $upsert->execute(['smackback_breach_files',       '']);
    $upsert->execute(['smackback_breach_resolved_at', $now]);
    $upsert->execute(['smackback_last_full_verify',   $now]);
}

/**
 * Promote smackback_status to 'clean'.
 *
 * Previously the ONLY writer of smackback_status='clean' was
 * smackback_resolve_breach(), so a site that was armed but never breached
 * never got a 'clean' write — its heartbeat reported 'pending' ("awaiting
 * first run") forever and the hub dashboard showed PENDING indefinitely.
 *
 * This promotes pending/unknown (or already-clean) → 'clean'. It deliberately
 * REFUSES to override an active 'breach': clearing a breach must go through
 * smackback_resolve_breach() so the incident is logged. Safe to call on every
 * clean verify pass and after a fresh disk baseline.
 */
function smackback_mark_clean(): void {
    global $pdo;
    try {
        $cur = $pdo->query(
            "SELECT setting_val FROM snap_settings WHERE setting_key = 'smackback_status'"
        )->fetchColumn();
    } catch (\Throwable $e) {
        return; // settings table unavailable — nothing to do
    }
    if ($cur === 'breach') {
        return; // never silently clear a breach — resolve_breach owns that path
    }
    $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('smackback_status', 'clean')
         ON DUPLICATE KEY UPDATE setting_val = 'clean'"
    )->execute();
}

// ─── FILE RESTORE ────────────────────────────────────────────────────────────

/**
 * Fetch a single file from the update server, verify hash, write to disk.
 * Downloads the current release ZIP, extracts only the requested file.
 * Verifies SHA-256 against snap_file_manifest before writing.
 * Clears breach flag for that file if clean.
 *
 * @param  string $relative_path  Path relative to webroot (e.g. 'core/db.php').
 * @return array{ok: bool, message: string}
 */
function smackback_restore_file(string $relative_path): array {
    global $pdo;

    // ── Get expected hash from manifest ────────────────────────────────────
    $row = $pdo->prepare(
        "SELECT expected_hash FROM snap_file_manifest WHERE file_path = ?"
    );
    $row->execute([$relative_path]);
    $expected_hash = $row->fetchColumn();

    if ($expected_hash === false) {
        return ['ok' => false, 'message' => "File not in manifest: {$relative_path}"];
    }

    // ── Fetch current release info to get download URL ──────────────────────
    if (!function_exists('updater_fetch_release_info')) {
        require_once __DIR__ . '/updater.php';
    }
    $release = updater_fetch_release_info();
    if (isset($release['error'])) {
        return ['ok' => false, 'message' => 'Could not reach update server: ' . $release['error']];
    }
    if (empty($release['download_url'])) {
        return ['ok' => false, 'message' => 'Update server did not return a download URL.'];
    }

    // ── Download release ZIP ─────────────────────────────────────────────────
    $zip_dest = sys_get_temp_dir() . '/snapsmack_restore_' . md5($relative_path) . '.zip';
    $dl_error  = '';

    if (!function_exists('updater_download')) {
        require_once __DIR__ . '/updater.php';
    }

    // Use updater_download() which handles cURL + fallback with proper timeouts
    // We can't call it directly since it hardcodes the destination filename,
    // so we use the same cURL logic inline for the restore download
    $fetched = _smackback_download_zip($release['download_url'], $zip_dest, $dl_error);
    if (!$fetched) {
        return ['ok' => false, 'message' => "Download failed: {$dl_error}"];
    }

    // ── Extract file from ZIP into memory buffer ─────────────────────────────
    $zip = new ZipArchive();
    if ($zip->open($zip_dest) !== true) {
        @unlink($zip_dest);
        return ['ok' => false, 'message' => 'Could not open downloaded package.'];
    }

    $contents = $zip->getFromName($relative_path);
    $zip->close();
    @unlink($zip_dest);

    if ($contents === false) {
        return ['ok' => false, 'message' => "File not found in release package: {$relative_path}"];
    }

    // ── Verify hash of buffer ─────────────────────────────────────────────────
    $actual_hash = hash('sha256', $contents);
    if ($actual_hash !== $expected_hash) {
        return [
            'ok'      => false,
            'message' => "Downloaded file does not match expected hash. "
                       . "The update server may have a different version. "
                       . "Try running a full update instead.",
        ];
    }

    // ── Write to disk ─────────────────────────────────────────────────────────
    $abs = smackback_abs($relative_path);

    // Ensure directory exists
    $dir = dirname($abs);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $written = file_put_contents($abs, $contents, LOCK_EX);
    if ($written === false) {
        return ['ok' => false, 'message' => "Could not write file to disk: {$relative_path}"];
    }

    // ── Re-verify on disk ─────────────────────────────────────────────────────
    $disk_hash = hash_file('sha256', $abs);
    if ($disk_hash !== $expected_hash) {
        return ['ok' => false, 'message' => "File written but disk hash mismatch — disk may be unreliable."];
    }

    // ── Update manifest ───────────────────────────────────────────────────────
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        "UPDATE snap_file_manifest
         SET last_status = 'ok', last_verified = ?, expected_mtime = ?
         WHERE file_path = ?"
    )->execute([$now, filemtime($abs), $relative_path]);

    return ['ok' => true, 'message' => "Restored: {$relative_path}"];
}

/**
 * Restore all currently breached files in one pass.
 *
 * @return array{
 *   restored: string[],
 *   failed: array<string, string>,
 *   all_clear: bool
 * }
 */
function smackback_restore_all_breached(): array {
    global $pdo;

    $breach_json = $pdo->query(
        "SELECT setting_val FROM snap_settings WHERE setting_key = 'smackback_breach_files'"
    )->fetchColumn();

    $breach_files = json_decode((string) $breach_json, true) ?? [];
    if (empty($breach_files)) {
        return ['restored' => [], 'failed' => [], 'all_clear' => true];
    }

    $restored = [];
    $failed   = [];

    foreach ($breach_files as $entry) {
        $path   = $entry['path']   ?? '';
        $status = $entry['status'] ?? '';

        if (empty($path)) {
            continue;
        }

        if ($status === 'missing') {
            // Missing files can be restored from ZIP just like tampered files
        }

        $result = smackback_restore_file($path);
        if ($result['ok']) {
            $restored[] = $path;
        } else {
            $failed[$path] = $result['message'];
        }
    }

    $all_clear = empty($failed);

    if ($all_clear) {
        smackback_resolve_breach('restore');
    } else {
        // Update breach_files to only those still unresolved
        $still_breached = array_filter(
            $breach_files,
            fn($e) => isset($failed[$e['path'] ?? ''])
        );
        $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('smackback_breach_files', ?)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
        )->execute([json_encode(array_values($still_breached))]);
    }

    return [
        'restored'  => $restored,
        'failed'    => $failed,
        'all_clear' => $all_clear,
    ];
}

/**
 * Internal cURL download helper for restore operations.
 * Returns the destination path on success, false on failure.
 *
 * @param  string $url
 * @param  string $dest
 * @param  string $error  Populated on failure.
 * @return bool
 */
function _smackback_download_zip(string $url, string $dest, string &$error = ''): bool {
    if (!function_exists('curl_init')) {
        // Fallback: file_get_contents
        $ctx  = stream_context_create(['http' => ['timeout' => 120]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            $error = 'file_get_contents failed and cURL not available.';
            return false;
        }
        return file_put_contents($dest, $data) !== false;
    }

    $fp = @fopen($dest, 'wb');
    if (!$fp) {
        $error = 'Cannot write to temp directory.';
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'SnapSmack-SMACKBACK/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0.0'),
    ]);

    $ok        = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $http_code !== 200) {
        @unlink($dest);
        $error = "HTTP {$http_code}. {$curl_err}";
        return false;
    }

    return true;
}

// ─── EMAIL ALERT ─────────────────────────────────────────────────────────────

/**
 * Send tamper alert email.
 *
 * @param  string[] $tampered
 * @param  string[] $missing
 * @param  string[] $truncated
 * @param  string[] $corrupted
 * @return void
 */
function smackback_send_alert(array $tampered, array $missing, array $truncated = [], array $corrupted = []): void {
    global $pdo;

    // Load settings
    $settings = $pdo->query(
        "SELECT setting_key, setting_val FROM snap_settings
         WHERE setting_key IN (
             'smackback_alert_email', 'site_name', 'site_url', 'admin_email'
         )"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $site_name  = $settings['site_name']  ?? 'Your SnapSmack Site';
    $site_url   = $settings['site_url']   ?? '';
    $admin_mail = $settings['admin_email'] ?? '';
    $to         = $settings['smackback_alert_email'] ?: $admin_mail;

    if (empty($to)) {
        error_log('SMACKBACK: No alert email configured — breach alert not sent.');
        return;
    }

    $timestamp = date('Y-m-d H:i:s');
    $host      = parse_url($site_url, PHP_URL_HOST) ?: 'localhost';
    $admin_url = rtrim($site_url, '/');

    // Build file list
    $file_lines = '';
    foreach ($tampered  as $path) { $file_lines .= "  TAMPERED:  {$path}\n"; }
    foreach ($missing   as $path) { $file_lines .= "  MISSING:   {$path}\n"; }
    foreach ($truncated as $path) { $file_lines .= "  TRUNCATED: {$path}\n"; }
    foreach ($corrupted as $path) { $file_lines .= "  CORRUPTED: {$path}\n"; }

    $subject = "[SMACKBACK] File tampering detected on {$site_name}";

    $body = <<<TEXT
SMACKBACK has detected unauthorised file modifications on {$site_name}.

Detected: {$timestamp}

Affected files:
{$file_lines}
Your admin interface is now in lockout mode. Log in to begin recovery:
{$admin_url}/smack-back.php

What to do:
1. Log in to your SnapSmack admin
2. Review the affected files
3. Restore them from the update server or run a full update
4. Investigate how the files were modified (check FTP logs, hosting panel access logs)

If you did not expect any file changes and cannot explain this alert,
treat this as a security incident.

-- SMACKBACK / {$site_name}
TEXT;

    $headers = "From: noreply@{$host}\r\nX-Mailer: SnapSmack-SMACKBACK";
    @mail($to, $subject, $body, $headers);
}

// ─── BREACH RENDER ───────────────────────────────────────────────────────────

/**
 * Render the BREACH lockout page and exit.
 * All CSS is inline — no file reads. Cannot be neutralised by file tampering.
 * Called from smack-admin.php (and all admin pages) when mode = lockout/paranoid.
 *
 * @param  array $settings  Settings array (pre-loaded from DB).
 * @return void  Never returns — calls exit.
 */
function smackback_render_breach(array $settings): void {
    $site_name   = htmlspecialchars($settings['site_name']  ?? 'SnapSmack', ENT_QUOTES);
    $detected_at = htmlspecialchars($settings['smackback_breach_at'] ?? 'Unknown', ENT_QUOTES);
    $base_url    = rtrim($settings['site_url'] ?? '', '/');

    $breach_files = json_decode($settings['smackback_breach_files'] ?? '[]', true) ?? [];

    // Build file rows
    $rows_html = '';
    $status_colours = [
        'TAMPERED'  => '#cc2200',
        'MISSING'   => '#ff6600',
        'TRUNCATED' => '#e07800',
        'CORRUPTED' => '#cc9900',
    ];
    foreach ($breach_files as $entry) {
        $path   = htmlspecialchars($entry['path']   ?? '', ENT_QUOTES);
        $status = strtoupper($entry['status'] ?? 'UNKNOWN');
        $colour = $status_colours[$status] ?? '#cc2200';
        $rows_html .= "<tr>
            <td style=\"padding:8px 16px;font-family:monospace;font-size:0.88rem;color:#eee;\">{$path}</td>
            <td style=\"padding:8px 16px;font-weight:700;color:{$colour};white-space:nowrap;\">{$status}</td>
            <td style=\"padding:8px 16px;\">
                <a href=\"{$base_url}/smack-back.php?action=restore&restore=" . urlencode($entry['path'] ?? '') . "\"
                   style=\"color:#ff9900;font-size:0.8rem;text-decoration:none;border:1px solid #ff9900;padding:4px 10px;\">
                   RESTORE
                </a>
            </td>
        </tr>\n";
    }

    $file_count = count($breach_files);

    // Output — fully self-contained, zero external dependencies
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SMACKBACK BREACH — {$site_name}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0a0000;color:#eee;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh}
.breach-header{background:#180000;border-bottom:3px solid #cc2200;padding:32px 40px}
.breach-flag{font-size:2.4rem;font-weight:900;color:#ff9900;letter-spacing:2px;margin-bottom:8px}
.breach-sub{font-size:1rem;color:#aaa}
.breach-sub strong{color:#eee}
.breach-body{max-width:900px;margin:0 auto;padding:40px}
.breach-count{font-size:1.4rem;font-weight:700;color:#cc2200;margin-bottom:8px}
.breach-explain{color:#999;font-size:0.9rem;margin-bottom:32px;line-height:1.7}
table{width:100%;border-collapse:collapse;margin-bottom:32px;background:#130000;border:1px solid #330000}
thead tr{background:#1e0000}
th{padding:10px 16px;text-align:left;font-size:0.75rem;letter-spacing:1px;color:#cc2200;text-transform:uppercase}
tbody tr+tr{border-top:1px solid #220000}
.breach-actions{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:40px}
.btn-restore-all{background:#cc2200;color:#fff;border:none;padding:12px 28px;font-size:0.9rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;cursor:pointer;text-decoration:none;display:inline-block}
.btn-restore-all:hover{background:#e02600}
.btn-update{background:transparent;color:#ff9900;border:2px solid #ff9900;padding:12px 28px;font-size:0.9rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;cursor:pointer;text-decoration:none;display:inline-block}
.btn-update:hover{background:#1a1000}
.breach-note{background:#100500;border-left:4px solid #ff9900;padding:20px 24px;font-size:0.88rem;line-height:1.8;color:#ccc}
.breach-note strong{color:#ff9900}
</style>
</head>
<body>
<div class="breach-header">
  <div class="breach-flag">⚠ SMACKBACK BREACH</div>
  <div class="breach-sub"><strong>{$site_name}</strong> &nbsp;·&nbsp; Detected: {$detected_at}</div>
</div>
<div class="breach-body">
  <div class="breach-count">{$file_count} file(s) compromised</div>
  <div class="breach-explain">
    SMACKBACK has detected file modifications that do not match the verified baseline.
    Admin access is restricted until the tampered files are resolved.
    This page cannot be bypassed or dismissed.
  </div>
  <table>
    <thead><tr><th>File</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>{$rows_html}</tbody>
  </table>
  <div class="breach-actions">
    <a href="{$base_url}/smack-back.php?restore_all=1" class="btn-restore-all">
      Restore All Tampered Files
    </a>
    <a href="{$base_url}/smack-update.php" class="btn-update">
      Run Full Update Instead
    </a>
  </div>
  <div class="breach-note">
    <strong>What happened?</strong> One or more PHP, CSS, or JavaScript files were modified
    since the last verified baseline. This typically indicates FTP credential compromise or
    shared-hosting lateral movement. Review your FTP access logs and hosting panel
    audit trail immediately. After restoring, change your FTP and hosting credentials.
  </div>
</div>
</body>
</html>
HTML;
    exit;
}

// ===== SNAPSMACK EOF =====
