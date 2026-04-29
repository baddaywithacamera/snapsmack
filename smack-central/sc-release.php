<?php
/**
 * SMACK CENTRAL - Release Packager
 *
 * Fetches tags from GitHub, downloads the repo zip for the selected tag,
 * repackages it as a clean distributable zip (no local git or shell access
 * required), SHA-256 checksums it, signs the checksum with the Ed25519
 * private key, drops the zip in the releases directory, and publishes
 * releases/latest.json in the format the SnapSmack updater expects.
 */

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-release.php';
$sc_page_title = 'Release Packager';

// Shared hosts enforce short max_execution_time (often 30s). Release builds
// need to download a zip from GitHub; the tag list fetch needs a few seconds.
// Override here so the process isn't killed mid-flight.
@set_time_limit(300);

// ── GitHub config ─────────────────────────────────────────────────────────────
// Define SNAPSMACK_GITHUB_REPO / SNAPSMACK_GITHUB_TOKEN in sc-config.php to
// override these defaults. Token is optional but raises the rate limit from
// 60 → 5000 requests/hour.
if (!defined('SNAPSMACK_GITHUB_REPO'))  define('SNAPSMACK_GITHUB_REPO',  'baddaywithacamera/snapsmack');
if (!defined('SNAPSMACK_GITHUB_TOKEN')) define('SNAPSMACK_GITHUB_TOKEN', '');

// ── Preflight checks ──────────────────────────────────────────────────────────
$preflight = [];

if (!function_exists('sodium_crypto_sign_detached')) {
    $preflight[] = ['err', 'libsodium is not available. PHP 7.2+ with sodium extension required.'];
}
if (!class_exists('ZipArchive')) {
    $preflight[] = ['err', 'ZipArchive is not available. Install the php-zip extension.'];
}
if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
    $preflight[] = ['err', 'No HTTP fetch method available. Enable curl or allow_url_fopen in php.ini.'];
}
if (!defined('SMACK_RELEASE_PRIVKEY') || strlen(SMACK_RELEASE_PRIVKEY) !== 128) {
    $preflight[] = ['err', 'SMACK_RELEASE_PRIVKEY is not set or is the wrong length. Check sc-config.php.'];
}
if (!defined('RELEASES_DIR') || RELEASES_DIR === '' || RELEASES_DIR === '/') {
    $preflight[] = ['err', 'RELEASES_DIR is not configured. Set it to the absolute server path of your releases folder in sc-config.php (e.g. /home/youruser/public_html/releases/).'];
} elseif (!is_dir(RELEASES_DIR)) {
    $preflight[] = ['warn', 'Releases directory does not exist: ' . RELEASES_DIR . '. Create it and make it web-accessible.'];
}

$preflight_ok = !array_filter($preflight, fn($p) => $p[0] === 'err');

// ── Derive the Ed25519 public key from the private key ────────────────────────
// The sodium secret key is 64 bytes: [32-byte seed][32-byte public key].
// We extract the last 32 bytes to get the matching public key for release-pubkey.php.
$sc_derived_pubkey = '';
if (defined('SMACK_RELEASE_PRIVKEY') && strlen(SMACK_RELEASE_PRIVKEY) === 128) {
    try {
        $sk = sodium_hex2bin(SMACK_RELEASE_PRIVKEY);
        $sc_derived_pubkey = function_exists('sodium_crypto_sign_publickey_from_secretkey')
            ? sodium_bin2hex(sodium_crypto_sign_publickey_from_secretkey($sk))
            : sodium_bin2hex(substr($sk, 32, 32));
    } catch (Exception $e) {
        $sc_derived_pubkey = '';
    }
}

// ── Helper: raw HTTP GET (curl preferred, file_get_contents fallback) ─────────
function sc_http_raw(string $url, array $extra_headers = [], int $timeout = 120): string|false {
    if (function_exists('curl_init')) {
        $ch   = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SnapSmack-SC/0.7.3',
        ];
        if ($extra_headers) $opts[CURLOPT_HTTPHEADER] = $extra_headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? $body : false;
    }
    $ctx_opts = ['http' => ['timeout' => 120, 'user_agent' => 'SnapSmack-SC/0.7.3']];
    if ($extra_headers) $ctx_opts['http']['header'] = implode("\r\n", $extra_headers);
    return @file_get_contents($url, false, stream_context_create($ctx_opts));
}

// ── Helper: GitHub API GET → decoded array ────────────────────────────────────
function sc_github_get(string $endpoint): array|false {
    $headers = ['Accept: application/vnd.github.v3+json', 'User-Agent: SnapSmack-SC/0.7.3'];
    if (SNAPSMACK_GITHUB_TOKEN) {
        $headers[] = 'Authorization: token ' . SNAPSMACK_GITHUB_TOKEN;
    }
    // 15s timeout for API calls — short enough to fail fast on page load,
    // long enough to handle a slow response. Zip downloads use the default 120s.
    $body = sc_http_raw('https://api.github.com/' . ltrim($endpoint, '/'), $headers, 15);
    if ($body === false) return false;
    $data = json_decode($body, true);
    return is_array($data) ? $data : false;
}

// ── Helper: list tags from GitHub (sorted newest-first by version) ────────────
// Only returns tags in the current three-segment numeric semver format
// (e.g. v0.7.17). Old letter-suffix tags (v0.7.9P) and companion-tool
// tags (vSYBU-*, vSUYB-*) are silently excluded. Returns at most 3 tags.
function sc_list_tags(): array {
    $data = sc_github_get('repos/' . SNAPSMACK_GITHUB_REPO . '/tags?per_page=50');
    if (!is_array($data)) return [];
    $tags = array_column($data, 'name');

    // Keep only clean X.Y.Z numeric tags — no letter suffix, no tool prefixes.
    $tags = array_values(array_filter($tags, function (string $t): bool {
        return (bool) preg_match('/^v?\d+\.\d+\.\d+$/i', $t);
    }));

    // Sort descending by version_compare (plain semver — no normalisation needed).
    usort($tags, function ($a, $b): int {
        return version_compare(ltrim($b, 'vV'), ltrim($a, 'vV'));
    });

    // Expose only the three most recent releases.
    return array_slice($tags, 0, 3);
}

// ── Helper: file changes between two tags via GitHub compare API ──────────────
function sc_file_changes(string $from_tag, string $to_tag): array {
    $from = urlencode($from_tag);
    $to   = urlencode($to_tag);
    $data = sc_github_get('repos/' . SNAPSMACK_GITHUB_REPO . "/compare/{$from}...{$to}");
    $added = $modified = $removed = [];
    if (!is_array($data) || empty($data['files'])) {
        return ['added' => [], 'modified' => [], 'removed' => []];
    }
    foreach ($data['files'] as $f) {
        $name   = $f['filename'] ?? '';
        $status = $f['status']   ?? '';
        match ($status) {
            'added'    => $added[]    = $name,
            'modified' => $modified[] = $name,
            'removed'  => $removed[]  = $name,
            'renamed'  => $modified[] = $name, // treat renames as modified (new path)
            default    => null,
        };
    }
    return ['added' => $added, 'modified' => $modified, 'removed' => $removed];
}

// ── Helper: download GitHub tag zip and repackage as clean release zip ────────
// GitHub zips include a wrapper directory (e.g. "snapsmack-0.7.1/"). This
// function strips that prefix so the release zip contains files at their
// actual paths, matching what git archive would have produced.
/**
 * Download the GitHub tag zip and repackage it as a clean release zip.
 *
 * @param string $tag           Git tag to download (e.g. "v0.7.2")
 * @param string $zip_dest      Where to write the release zip
 * @param array  $include_files Differential file list (added + modified paths,
 *                              relative to repo root). When non-empty, ONLY
 *                              these files are included — differential release.
 *                              When empty, all files are included (full release,
 *                              first-release fallback).
 */
function sc_build_release_zip(string $tag, string $zip_dest, array $include_files = []): array {
    $url  = 'https://github.com/' . SNAPSMACK_GITHUB_REPO . '/archive/refs/tags/' . urlencode($tag) . '.zip';
    $data = sc_http_raw($url);
    if ($data === false) {
        return ['ok' => false, 'msg' => 'Could not download zip from GitHub. Check outbound HTTPS access.'];
    }

    $tmp_src = sys_get_temp_dir() . '/sc_gh_' . bin2hex(random_bytes(16)) . '.zip';
    file_put_contents($tmp_src, $data);
    $dl_kb = round(strlen($data) / 1024);
    unset($data);

    $src = new ZipArchive();
    if ($src->open($tmp_src) !== true) {
        @unlink($tmp_src);
        return ['ok' => false, 'msg' => 'Could not open downloaded zip — file may be corrupt.'];
    }

    // Detect the wrapper prefix e.g. "snapsmack-0.7.2/"
    $prefix = '';
    for ($i = 0; $i < $src->numFiles; $i++) {
        $n = $src->getNameIndex($i);
        if (str_ends_with($n, '/') && substr_count(rtrim($n, '/'), '/') === 0) {
            $prefix = $n;
            break;
        }
    }

    $dst = new ZipArchive();
    if ($dst->open($zip_dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $src->close();
        @unlink($tmp_src);
        return ['ok' => false, 'msg' => 'Could not create release zip at ' . $zip_dest . ' — check directory permissions.'];
    }

    // Safety exclusions — always skipped regardless of diff mode.
    // Base release ships ONLY 50-shades-of-noah-grey and new-horizon.
    // All other skins are tracked in git but distributed via the skin gallery.
    // Fonts are pre-installed and ship separately — never in the release zip.
    // Tools (suyb, sybu, etc.) are desktop companion apps, not web deployables.
    // To change what ships in the base release, edit this list and update
    // the skin registry table in CLAUDE.md.
    $always_exclude = [
        'assets/fonts/',
        // Skins NOT included in base release package
        'skins/52-card-pickup/',
        'skins/a-grey-reckoning/',
        'skins/hip-to-be-square/',
        'skins/impact-printer/',
        'skins/in-stereo-where-available/',
        'skins/kiosk/',
        'skins/pocket-rocket/',
        'skins/show-n-tell/',
        'skins/the-grid/',
        'skins/true-grit/',
        // Non-web directories
        'tools/',
        'projects/',
        'smack-central/',
        '_spec/',
        'docs/',
        'screenshots/',
        'media_assets/',
        'secaudits/',
        'migrations/',
        'database/',
        'data/',
        // Dev/meta files
        'CLAUDE.md',
        'CHANGELOG.md',
        'README.md',
        '.gitignore',
        // One-off utility scripts — not part of a normal install
        'backfill-checksums.php',
        'backfill-thumbs.php',
        // Build artifacts at root
        'smack-central-current.zip',
        // ACME / Let's Encrypt challenge — server-specific
        '.well-known/',
    ];

    $differential = !empty($include_files);
    $include_set  = $differential ? array_flip($include_files) : [];

    // Files that must ship even when their parent directory is in $always_exclude.
    // The canonical schema SQL is needed on live servers so updater_canonical_diff()
    // can fall back to the on-disk copy when the remote URL is unavailable.
    $always_include = [
        'database/schema/snapsmack_canonical.sql',
    ];

    $count   = 0;
    $skipped = 0;
    for ($i = 0; $i < $src->numFiles; $i++) {
        $name = $src->getNameIndex($i);
        $rel  = $prefix ? substr($name, strlen($prefix)) : $name;
        if ($rel === '' || str_ends_with($rel, '/')) continue;
        if (str_contains($rel, '..') || str_starts_with($rel, '/')) continue;

        // Differential mode: only include files that changed
        if ($differential && !isset($include_set[$rel])) { $skipped++; continue; }

        // Force-include overrides the always_exclude list
        $force_include = in_array($rel, $always_include, true);

        // Safety exclusions — prefix match (skipped for force-included files)
        if (!$force_include) {
            foreach ($always_exclude as $excl) {
                if (str_starts_with($rel, $excl)) { $skipped++; continue 2; }
            }
        }

        // Filename-pattern exclusions
        $basename = basename($rel);
        // Strip key JSON files (service account keys etc.) from anywhere in the tree
        if (str_ends_with($basename, '.json') && str_contains($basename, 'key')) { $skipped++; continue; }
        // Strip screenshot PNGs from all skins — gallery previews are served from
        // SC assets, not from the install directory. Keeping them bloats the package.
        if (str_starts_with($basename, 'screenshot') && str_ends_with($basename, '.png') && str_starts_with($rel, 'skins/')) {
            $skipped++;
            continue;
        }

        $content = $src->getFromIndex($i);
        if ($content === false) continue;
        $dst->addFromString($rel, $content);
        $count++;
    }

    $src->close();
    $dst->close();
    @unlink($tmp_src);

    $mode = $differential ? "differential ({$count} changed files)" : "full ({$count} files)";
    return [
        'ok'  => true,
        'msg' => "Downloaded {$dl_kb} KB from GitHub, packaged {$mode}, skipped {$skipped}",
    ];
}

// ── POST: Delete release ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_release') {
    $del_tag = trim($_POST['del_tag'] ?? '');
    if ($del_tag) {
        $db  = sc_db();
        $rel = $db->prepare("SELECT download_url, is_latest FROM sc_releases WHERE git_tag = ? LIMIT 1");
        $rel->execute([$del_tag]);
        $row = $rel->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Remove the zip file from disk
            $zip_file = rtrim(RELEASES_DIR, '/') . '/' . basename($row['download_url']);
            if (file_exists($zip_file)) @unlink($zip_file);

            // Remove DB record
            $db->prepare("DELETE FROM sc_releases WHERE git_tag = ?")->execute([$del_tag]);

            // If we just deleted the latest, promote the next most recent
            if ($row['is_latest']) {
                $next = $db->query("SELECT git_tag FROM sc_releases ORDER BY id DESC LIMIT 1")->fetch();
                if ($next) {
                    $db->prepare("UPDATE sc_releases SET is_latest = 1 WHERE git_tag = ?")->execute([$next['git_tag']]);
                    // Rewrite latest.json
                    $latest = $db->prepare("SELECT * FROM sc_releases WHERE git_tag = ? LIMIT 1");
                    $latest->execute([$next['git_tag']]);
                    $lr = $latest->fetch(PDO::FETCH_ASSOC);
                    if ($lr) {
                        $json = json_encode([
                            'version'         => $lr['version'],
                            'version_full'    => $lr['version_full'],
                            'released'        => $lr['released_at'],
                            'download_url'    => $lr['download_url'],
                            'checksum_sha256' => $lr['checksum_sha256'],
                            'signature'       => $lr['signature'],
                            'changelog'       => array_filter(array_map('trim', explode("\n", $lr['changelog']))),
                            'requires_php'    => $lr['requires_php'],
                            'requires_mysql'  => $lr['requires_mysql'],
                            'schema_changes'  => (bool)$lr['schema_changes'],
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        file_put_contents(rtrim(RELEASES_DIR, '/') . '/latest.json', $json);
                    }
                } else {
                    // No releases left — remove latest.json
                    @unlink(rtrim(RELEASES_DIR, '/') . '/latest.json');
                }
            }
        }
    }
    header('Location: sc-release.php');
    exit;
}

// ── POST: Build release ───────────────────────────────────────────────────────
// ── AJAX: fetch and parse CHANGELOG.md for a given tag ───────────────────────
// Called by the packager JS instead of hitting raw.githubusercontent.com directly.
// Fetches by commit SHA (not tag ref) to bypass GitHub CDN tag-ref caching.
if (($_GET['action'] ?? '') === 'fetch_changelog') {
    header('Content-Type: application/json');
    $tag = preg_replace('/[^a-zA-Z0-9._\-]/', '', $_GET['tag'] ?? '');
    if ($tag === '') { echo json_encode(['ok' => false, 'error' => 'No tag']); exit; }

    // Resolve tag → commit SHA via API so we fetch by SHA, not tag ref.
    // Use git/refs/tags/{tag} (plural refs) — the correct GitHub REST API path.
    $ref_data = sc_github_get('repos/' . SNAPSMACK_GITHUB_REPO . '/git/refs/tags/' . urlencode($tag));
    $sha = '';
    if (is_array($ref_data)) {
        // git/refs/tags/{tag} returns a single object for an exact match,
        // but may return a numeric array if multiple refs match. Normalise.
        $ref = isset($ref_data[0]) ? $ref_data[0] : $ref_data;
        // Lightweight tag → commit SHA directly; annotated tag → dereference one more hop.
        if (($ref['object']['type'] ?? '') === 'tag') {
            $tag_data = sc_github_get('repos/' . SNAPSMACK_GITHUB_REPO . '/git/tags/' . ($ref['object']['sha'] ?? ''));
            $sha = $tag_data['object']['sha'] ?? '';
        } else {
            $sha = $ref['object']['sha'] ?? '';
        }
    }

    if ($sha === '') { echo json_encode(['ok' => false, 'error' => 'Could not resolve tag SHA']); exit; }

    $url  = 'https://raw.githubusercontent.com/' . SNAPSMACK_GITHUB_REPO . '/' . $sha . '/CHANGELOG.md';
    $body = sc_http_raw($url);
    if ($body === false) { echo json_encode(['ok' => false, 'error' => 'Could not fetch CHANGELOG.md']); exit; }

    echo json_encode(['ok' => true, 'content' => $body]);
    exit;
}

$action       = $_POST['action'] ?? '';
$build_error  = '';
$build_log    = [];
$build_result = null;

// Retrieve flash result from session after redirect.
if (isset($_SESSION['sc_release_built'])) {
    $build_result = $_SESSION['sc_release_built'];
    unset($_SESSION['sc_release_built']);
}

if ($action === 'build' && $preflight_ok) {

    $tag            = trim($_POST['tag']            ?? '');
    $version        = trim($_POST['version']        ?? '');
    $version_full   = trim($_POST['version_full']   ?? '');
    $released       = trim($_POST['released']       ?? date('Y-m-d'));
    $requires_php   = trim($_POST['requires_php']   ?? '8.0');
    $requires_mysql = trim($_POST['requires_mysql'] ?? '5.7');
    $schema_changes = !empty($_POST['schema_changes']);
    $codename       = trim($_POST['codename']       ?? '');
    $changelog_raw  = trim($_POST['changelog'] ?? '');
    $changelog      = array_values(array_filter(array_map('trim', explode("\n", $changelog_raw))));

    // Validate
    if (!preg_match('/^[a-zA-Z0-9._\-]+$/', $tag)) {
        $build_error = 'Invalid tag format.';
    } elseif ($version === '' || $version_full === '') {
        $build_error = 'Version and Version Full are required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $released)) {
        $build_error = 'Invalid release date.';
    }

    if (!$build_error) {
        $zip_name = 'snapsmack-' . preg_replace('/[^a-zA-Z0-9._\-]/', '', $version) . '.zip';
        $zip_dest = rtrim(RELEASES_DIR, '/') . '/' . $zip_name;

        // Step 1: Diff — informational only. Always builds a full zip so installs
        // that skipped intermediate releases get every file. Diff is kept for
        // the build log, schema_changes auto-detection, and the DB file_changes record.
        $file_changes = ['added' => [], 'modified' => [], 'removed' => []];
        try {
            $prev = sc_db()->query("SELECT git_tag FROM sc_releases ORDER BY id DESC LIMIT 1")->fetch();
            if ($prev && $prev['git_tag'] !== $tag) {
                $build_log[]  = "→ Diffing {$prev['git_tag']}…{$tag} via GitHub API (informational)…";
                $file_changes = sc_file_changes($prev['git_tag'], $tag);
                $total        = count($file_changes['added']) + count($file_changes['modified']) + count($file_changes['removed']);
                $build_log[]  = "→ {$total} file(s) changed vs {$prev['git_tag']}";
            }
        } catch (Exception $e) {
            $build_log[] = "→ Diff skipped (" . $e->getMessage() . ")";
        }

        // Step 2: Download from GitHub + repackage as FULL zip (no file filter).
        // Full zips ensure every install — regardless of which versions were
        // skipped — receives a complete and consistent set of files.
        $build_log[] = "→ Downloading tag {$tag} from GitHub…";
        $zip_result  = sc_build_release_zip($tag, $zip_dest, []);
        $build_log[] = '→ ' . $zip_result['msg'];

        if (!$zip_result['ok']) {
            $build_error = $zip_result['msg'];
        } else {
            // Step 3: SHA-256
            $checksum    = hash_file('sha256', $zip_dest);
            $build_log[] = "→ SHA-256: {$checksum}";

            // Step 4: Sign
            try {
                $privkey     = sodium_hex2bin(SMACK_RELEASE_PRIVKEY);
                $sig         = sodium_crypto_sign_detached($checksum, $privkey);
                $sig_hex     = sodium_bin2hex($sig);
                $build_log[] = "→ Signed OK";
            } catch (SodiumException $e) {
                $build_error = 'Signing failed: ' . $e->getMessage();
            }

            if (!$build_error) {
                $file_size    = filesize($zip_dest);
                $download_url = rtrim(RELEASES_URL, '/') . '/' . $zip_name;
                $build_log[]  = "→ Zip saved: {$zip_dest} (" . number_format($file_size / 1024, 1) . " KB)";

                // Step 4b: Auto-detect schema_changes from diff
                $diff_all = array_merge($file_changes['added'] ?? [], $file_changes['modified'] ?? []);
                foreach ($diff_all as $_df) {
                    if (str_starts_with($_df, 'migrations/')) {
                        $schema_changes = true;
                        $build_log[]    = "→ schema_changes: true (migration file detected in diff)";
                        break;
                    }
                }

                // Step 4c: Publish canonical schema SQL + detached Ed25519 signature.
                // Installed instances fetch these to diff against their live DB,
                // even when an update failed mid-extraction and the on-disk copy
                // is stale. The signature lets the updater verify the SQL is
                // authentic before running any DDL against the database.
                //
                // We read the canonical SQL from inside the release zip that was
                // just built rather than from a disk path — Smack Central is itself
                // a SnapSmack install and does not have a local checkout of the repo,
                // so there is no database/schema/ folder on this server.
                $canonical_dst     = rtrim(RELEASES_DIR, '/') . '/snapsmack_canonical.sql';
                $canonical_sig_dst = rtrim(RELEASES_DIR, '/') . '/snapsmack_canonical.sql.sig';
                $canonical_url     = '';
                $canonical_sig     = '';
                $schema_content    = false;
                $zip_reader = new ZipArchive();
                if ($zip_reader->open($zip_dest) === true) {
                    // GitHub zips nest everything under a directory named {repo}-{tag}/
                    // sc_build_release_zip() strips that prefix, so the entry is at
                    // database/schema/snapsmack_canonical.sql directly.
                    $schema_content = $zip_reader->getFromName('database/schema/snapsmack_canonical.sql');
                    $zip_reader->close();
                }
                if ($schema_content !== false && $schema_content !== '') {
                    if (file_put_contents($canonical_dst, $schema_content) !== false) {
                        $canonical_url = rtrim(RELEASES_URL, '/') . '/snapsmack_canonical.sql';
                        // Sign the canonical SQL with the same private key as the zip
                        try {
                            $schema_checksum = hash('sha256', $schema_content);
                            $schema_sig_bin  = sodium_crypto_sign_detached($schema_checksum, sodium_hex2bin(SMACK_RELEASE_PRIVKEY));
                            $schema_sig_hex  = sodium_bin2hex($schema_sig_bin);
                            file_put_contents($canonical_sig_dst, $schema_sig_hex);
                            $canonical_sig  = rtrim(RELEASES_URL, '/') . '/snapsmack_canonical.sql.sig';
                            $build_log[]    = "→ snapsmack_canonical.sql published + signed (from zip)";
                        } catch (SodiumException $e) {
                            $build_log[] = "→ WARNING: canonical SQL published but signing failed: " . $e->getMessage();
                        }
                    } else {
                        $build_log[] = "→ WARNING: could not write snapsmack_canonical.sql to releases dir";
                    }
                } else {
                    $build_log[] = "→ WARNING: database/schema/snapsmack_canonical.sql not found in release zip";
                }

                // Step 5: Write latest.json
                $manifest = [
                    'version'            => $version,
                    'version_full'       => $version_full,
                    'codename'           => $codename,
                    'released'           => $released,
                    'download_url'       => $download_url,
                    'checksum_sha256'    => $checksum,
                    'signature'          => $sig_hex,
                    'changelog'          => $changelog,
                    'file_changes'       => $file_changes,
                    'schema_changes'     => $schema_changes,
                    'requires_php'       => $requires_php,
                    'requires_mysql'     => $requires_mysql,
                    'download_size'      => $file_size,
                    'canonical_schema_url' => $canonical_url,
                    'canonical_schema_sig' => $canonical_sig,
                ];
                $json_path = rtrim(RELEASES_DIR, '/') . '/latest.json';
                file_put_contents($json_path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $build_log[] = "→ latest.json written";

                // Step 6: Persist to DB
                try {
                    $db = sc_db();
                    $db->exec("UPDATE sc_releases SET is_latest = 0");
                    $stmt = $db->prepare(
                        "INSERT INTO sc_releases
                            (version, version_full, git_tag, checksum_sha256, signature,
                             download_url, download_size, schema_changes, requires_php,
                             requires_mysql, changelog, file_changes, released_at, is_latest)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)"
                    );
                    $stmt->execute([
                        $version, $version_full, $tag, $checksum, $sig_hex,
                        $download_url, $file_size, $schema_changes ? 1 : 0,
                        $requires_php, $requires_mysql,
                        $changelog_raw,
                        json_encode($file_changes),
                        $released,
                    ]);
                    $build_log[] = "→ Release record saved (ID " . $db->lastInsertId() . ")";
                } catch (Exception $e) {
                    $build_log[] = "→ DB write failed: " . $e->getMessage();
                }

                // Store result and redirect to avoid form resubmission
                $_SESSION['sc_release_built'] = [
                    'version'      => $version_full,
                    'tag'          => $tag,
                    'checksum'     => $checksum,
                    'signature'    => $sig_hex,
                    'download_url' => $download_url,
                    'file_size'    => $file_size,
                    'log'          => $build_log,
                ];
                header('Location: sc-release.php?built=1');
                exit;
            }
        }
    }
}

// ── Fetch data for the form ───────────────────────────────────────────────────
$tags = [];
if ($preflight_ok) {
    $tags = sc_list_tags();
}

// Previous releases for the history table
$releases = [];
try {
    $releases = sc_db()->query(
        "SELECT version_full, git_tag, released_at, download_url, download_size,
                schema_changes, is_latest, created_at
         FROM sc_releases ORDER BY id DESC LIMIT 3"
    )->fetchAll();
} catch (Exception $e) {}

// Auto-fill version from latest tag if possible
$latest_tag      = $tags[0] ?? '';
$default_version = ltrim(preg_replace('/^v/i', '', $latest_tag), '');

require __DIR__ . '/sc-layout-top.php';
?>

<div class="sc-page-header">
  <span class="sc-page-title">Release Packager</span>
  <span class="sc-dim">github → zip → sha256 → sign → publish</span>
</div>

<?php foreach ($preflight as [$level, $msg]): ?>
<div class="sc-alert sc-alert--<?php echo $level === 'err' ? 'error' : 'warn'; ?>">
  <?php echo htmlspecialchars($msg); ?>
</div>
<?php endforeach; ?>

<?php if ($build_result): ?>
<!-- ── Build success result ─────────────────────────────────────────────── -->
<div class="sc-alert sc-alert--success">
  Release <?php echo htmlspecialchars($build_result['version']); ?> packaged and published successfully.
</div>
<div class="sc-release-result">
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">Version</span>
    <span class="sc-release-result__val"><?php echo htmlspecialchars($build_result['version']); ?></span>
  </div>
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">Tag</span>
    <span class="sc-release-result__val"><?php echo htmlspecialchars($build_result['tag']); ?></span>
  </div>
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">Download</span>
    <span class="sc-release-result__val">
      <a href="<?php echo htmlspecialchars($build_result['download_url']); ?>" target="_blank">
        <?php echo htmlspecialchars($build_result['download_url']); ?>
      </a>
      &nbsp;(<?php echo number_format($build_result['file_size'] / 1024, 1); ?> KB)
    </span>
  </div>
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">SHA-256</span>
    <span class="sc-release-result__val"><?php echo htmlspecialchars($build_result['checksum']); ?></span>
  </div>
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">Signature</span>
    <span class="sc-release-result__val"><?php echo htmlspecialchars($build_result['signature']); ?></span>
  </div>
  <?php if (!empty($build_result['log'])): ?>
  <details style="margin-top:12px;">
    <summary style="cursor:pointer; font-size:var(--sc-size-label); text-transform:uppercase; letter-spacing:.8px; color:var(--sc-text-dim);">Build Log</summary>
    <div class="sc-build-log"><?php echo htmlspecialchars(implode("\n", $build_result['log'])); ?></div>
  </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="sc-grid-2">

  <!-- ── Left: Build form ──────────────────────────────────────────────────── -->
  <div>
    <div class="sc-box">
      <div class="sc-box-header">
        <span class="sc-box-title">Build New Release</span>
        <span class="sc-dim" style="font-size:var(--sc-size-label);">
          <?php echo htmlspecialchars(SNAPSMACK_GITHUB_REPO); ?>
        </span>
      </div>
      <div class="sc-box-body">

        <?php if ($build_error): ?>
        <div class="sc-alert sc-alert--error"><?php echo htmlspecialchars($build_error); ?></div>
        <?php if (!empty($build_log)): ?>
        <div class="sc-build-log"><?php echo htmlspecialchars(implode("\n", $build_log)); ?></div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (!$preflight_ok): ?>
        <p class="sc-dim">Fix the errors above before building a release.</p>

        <?php elseif (empty($tags)): ?>
        <div class="sc-alert sc-alert--warn">
          Could not fetch tags from GitHub. Check that this server has outbound HTTPS access
          and that <code><?php echo htmlspecialchars(SNAPSMACK_GITHUB_REPO); ?></code> is public.
          <?php if (!SNAPSMACK_GITHUB_TOKEN): ?>
          If you're hitting the rate limit (60 req/hr), add
          <code>define('SNAPSMACK_GITHUB_TOKEN', 'your-token');</code> to sc-config.php.
          <?php endif; ?>
        </div>

        <?php else: ?>
        <form method="post" action="sc-release.php">
          <input type="hidden" name="action" value="build">

          <div class="sc-field">
            <label>Git Tag</label>
            <select name="tag" class="sc-select" id="tag-select">
              <?php foreach ($tags as $t): ?>
              <option value="<?php echo htmlspecialchars($t); ?>"
                      data-version="<?php echo htmlspecialchars(ltrim(preg_replace('/^v/i', '', $t), '')); ?>">
                <?php echo htmlspecialchars($t); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="sc-grid-2" style="gap:12px;">
            <div class="sc-field">
              <label>Version</label>
              <input type="text" name="version" id="version-input"
                     placeholder="0.8" value="<?php echo htmlspecialchars($default_version); ?>">
              <span class="sc-hint">Short string used in filename and update check.</span>
            </div>
            <div class="sc-field">
              <label>Version Full</label>
              <input type="text" name="version_full" id="version-full-input"
                     placeholder="Alpha 0.8" value="Alpha <?php echo htmlspecialchars($default_version); ?>">
            </div>
            <div class="sc-field">
              <label>Codename <span class="sc-dim" style="font-weight:400;text-transform:none;">(optional)</span></label>
              <input type="text" name="codename" id="codename-input" placeholder="e.g. Lawn Chair">
            </div>
          </div>

          <div class="sc-grid-2" style="gap:12px;">
            <div class="sc-field">
              <label>Release Date</label>
              <input type="date" name="released" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div style="display:flex; gap:12px;">
              <div class="sc-field" style="flex:1;">
                <label>Min PHP</label>
                <input type="text" name="requires_php" value="8.0" style="width:100%;">
              </div>
              <div class="sc-field" style="flex:1;">
                <label>Min MySQL</label>
                <input type="text" name="requires_mysql" value="5.7" style="width:100%;">
              </div>
            </div>
          </div>

          <div class="sc-checkbox-row">
            <input type="checkbox" name="schema_changes" id="schema_changes" value="1">
            <label for="schema_changes">Schema changes in this release</label>
          </div>
          <p class="sc-hint" style="margin-top:-8px; margin-bottom:12px;">
            Auto-detected if any <code>migrations/</code> file is in the diff — override here if needed.
          </p>

          <div class="sc-field">
            <label>
              Changelog
              <span id="changelog-status" style="font-weight:400; font-size:var(--sc-size-label); margin-left:8px;"></span>
            </label>
            <textarea name="changelog" id="changelog-textarea" rows="8"
                      placeholder="One changelog entry per line. Plain text — will be presented to admins in the update notification."
                      style="font-family:var(--sc-font-mono); font-size:0.78rem;"></textarea>
            <span class="sc-hint">Auto-populated from CHANGELOG.md when a tag is selected. Edit freely — one item per line, no bullets or markdown.</span>
          </div>

          <div class="sc-btn-row" style="justify-content:flex-end; margin-top:8px;">
            <button type="submit" class="sc-btn sc-btn--primary"
                    onclick="return confirm('Package and publish release from tag: ' + document.getElementById('tag-select').value + '?');">
              BUILD &amp; PUBLISH
            </button>
          </div>
        </form>
        <?php endif; ?>

      </div>
    </div><!-- /.sc-box -->
  </div>

  <!-- ── Right: Release history ────────────────────────────────────────────── -->
  <div>
    <div class="sc-box">
      <div class="sc-box-header"><span class="sc-box-title">Release History</span></div>
      <div class="sc-box-body">
        <?php if (empty($releases)): ?>
        <p class="sc-dim">No releases shipped yet.</p>
        <?php else: ?>
        <table class="sc-table">
          <thead>
            <tr>
              <th>Version</th>
              <th>Tag</th>
              <th>Date</th>
              <th>Size</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($releases as $rel): ?>
          <tr>
            <td>
              <?php if ($rel['is_latest']): ?>
                <span class="sc-dot sc-dot--live" title="Current latest"></span>
              <?php endif; ?>
              <a href="<?php echo htmlspecialchars($rel['download_url']); ?>" target="_blank">
                <?php echo htmlspecialchars($rel['version_full']); ?>
              </a>
              <?php if ($rel['schema_changes']): ?>
                <span style="font-size:0.65rem; color:var(--sc-warn); margin-left:4px; text-transform:uppercase; letter-spacing:.5px;">DB</span>
              <?php endif; ?>
            </td>
            <td class="sc-dim sc-mono" style="font-size:0.72rem;"><?php echo htmlspecialchars($rel['git_tag']); ?></td>
            <td class="sc-dim"><?php echo htmlspecialchars($rel['released_at']); ?></td>
            <td class="sc-dim" style="white-space:nowrap;">
              <?php echo $rel['download_size'] ? number_format($rel['download_size'] / 1024, 0) . ' KB' : '—'; ?>
            </td>
            <td>
              <form method="post" onsubmit="return confirm('Delete <?php echo htmlspecialchars($rel['version_full']); ?>? This removes the zip and DB record.')">
                <input type="hidden" name="action"  value="delete_release">
                <input type="hidden" name="del_tag" value="<?php echo htmlspecialchars($rel['git_tag']); ?>">
                <button class="sc-btn sc-btn--sm sc-btn--danger">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <?php
    // Show current latest.json if it exists
    $json_path = rtrim(RELEASES_DIR, '/') . '/latest.json';
    if (file_exists($json_path)):
        $json_preview = file_get_contents($json_path);
    ?>
    <div class="sc-box">
      <div class="sc-box-header">
        <span class="sc-box-title">Current latest.json</span>
        <a href="<?php echo rtrim(RELEASES_URL, '/'); ?>/latest.json" target="_blank" class="sc-btn sc-btn--sm">View Live</a>
      </div>
      <div class="sc-box-body">
        <div class="sc-code-block" style="max-height:280px; overflow-y:auto;"><?php echo htmlspecialchars($json_preview); ?></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Signing key info ──────────────────────────────────────────────── -->
    <div class="sc-box" style="margin-top:20px;">
      <div class="sc-box-header">
        <span class="sc-box-title">Signing Key</span>
        <span class="sc-dim" style="font-size:var(--sc-size-label);">Ed25519</span>
      </div>
      <div class="sc-box-body">
        <?php if ($sc_derived_pubkey): ?>
        <p class="sc-dim" style="margin-bottom:10px;font-size:var(--sc-size-label);">
          Public key for <code>core/release-pubkey.php</code> on all installs.
          Copy this value into <code>define('SNAPSMACK_RELEASE_PUBKEY', '…')</code>.
        </p>
        <div class="sc-code-block" style="word-break:break-all;user-select:all;cursor:text;"><?php echo htmlspecialchars($sc_derived_pubkey); ?></div>
        <?php else: ?>
        <p class="sc-dim">Public key unavailable — check SMACK_RELEASE_PRIVKEY in sc-config.php.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /.sc-grid-2 -->

<script>
// Auto-fill version, codename, and changelog fields when tag selection changes.
(function () {
    var repo    = <?php echo json_encode(SNAPSMACK_GITHUB_REPO); ?>;
    var sel     = document.getElementById('tag-select');
    var vinp    = document.getElementById('version-input');
    var vfull   = document.getElementById('version-full-input');
    var codeinp = document.getElementById('codename-input');
    var clta    = document.getElementById('changelog-textarea');
    var clstat  = document.getElementById('changelog-status');
    if (!sel) return;

    // Mark fields as user-edited so auto-fill stops overwriting them.
    [vinp, vfull, codeinp].forEach(function (el) {
        if (el) el.addEventListener('input', function () { el.dataset.userEdited = '1'; });
    });
    if (clta) clta.addEventListener('input', function () { clta.dataset.userEdited = '1'; });

    // Parse CHANGELOG.md text for a specific version string.
    // Returns an array of plain-text entries (no bullets, no markdown).
    function parseChangelog(text, version) {
        var lines   = text.replace(/\r/g, '').split('\n');
        var entries = [];
        var inVer   = false;
        var section = '';
        var re      = new RegExp('^## ' + version.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(\\s|$)');

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            if (re.test(line)) {
                // Extract codename from heading: ## 0.7.8e — "Codename" (date)
                var cnMatch = line.match(/—\s+"([^"]+)"/);
                if (cnMatch && codeinp && !codeinp.dataset.userEdited) {
                    codeinp.value = cnMatch[1];
                }
                inVer = true;
                continue;
            }
            if (inVer && /^## /.test(line)) break;
            if (!inVer) continue;

            // Sub-section heading (### Added, ### Fixed, etc.)
            var secMatch = line.match(/^### (.+)$/);
            if (secMatch) { section = secMatch[1].trim(); continue; }

            // Bullet point
            var bulletMatch = line.match(/^[-*] (.+)$/);
            if (bulletMatch) {
                // Strip markdown bold (**text**) — leave plain text
                var text = bulletMatch[1].replace(/\*\*([^*]+)\*\*/g, '$1').trim();
                entries.push(section ? section + ': ' + text : text);
            }
        }
        return entries;
    }

    function fetchChangelog(tag, version) {
        if (!clta) return;
        if (clta.dataset.userEdited) return;
        // Proxy through sc-release.php to fetch by commit SHA (avoids GitHub CDN
        // tag-ref caching that causes stale results after a force-push).
        var url = 'sc-release.php?action=fetch_changelog&tag=' + encodeURIComponent(tag);
        if (clstat) { clstat.textContent = '⟳ loading…'; clstat.style.color = 'var(--sc-color-dim, #888)'; }
        fetch(url)
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                console.log('[changelog proxy] response ok:', data.ok, 'error:', data.error || 'none');
                console.log('[changelog proxy] content length:', data.content ? data.content.length : 0);
                console.log('[changelog proxy] content preview:', data.content ? data.content.substring(0, 300) : '(empty)');
                if (!data.ok) throw new Error(data.error || 'proxy error');
                var entries = parseChangelog(data.content, version);
                console.log('[changelog proxy] version sought:', version, 'entries found:', entries.length);
                if (!clta.dataset.userEdited) {
                    if (entries.length) {
                        clta.value = entries.join('\n');
                        if (clstat) { clstat.textContent = '✓ ' + entries.length + ' entries from CHANGELOG.md'; clstat.style.color = 'var(--sc-color-ok, #4caf50)'; }
                    } else {
                        clta.value = '';
                        if (clstat) { clstat.textContent = '⚠ No entry found for ' + version + ' in CHANGELOG.md'; clstat.style.color = 'var(--sc-color-warn, #e6a817)'; }
                    }
                }
            })
            .catch(function (err) {
                if (clstat) { clstat.textContent = '✗ Could not fetch CHANGELOG.md — ' + err.message + ' (GitHub rate limit? Add a token to sc-config.php)'; clstat.style.color = 'var(--sc-color-err, #e05252)'; }
            });
    }

    sel.addEventListener('change', function () {
        var opt = sel.options[sel.selectedIndex];
        var v   = opt.dataset.version || '';
        var tag = opt.value;
        if (vinp  && !vinp.dataset.userEdited)  vinp.value  = v;
        if (vfull && !vfull.dataset.userEdited)  vfull.value = 'Alpha ' + v;
        if (v && tag) fetchChangelog(tag, v);
    });

    // Fire on page load for the default-selected tag.
    if (sel.options.length) {
        var opt = sel.options[sel.selectedIndex];
        var v   = opt.dataset.version || '';
        var tag = opt.value;
        if (vinp  && !vinp.value) vinp.value  = v;
        if (vfull && !vfull.value) vfull.value = 'Alpha ' + v;
        if (v && tag) fetchChangelog(tag, v);
    }
}());
</script>

<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
        if (vinp  && !vinp.value) vinp.value  = v;
        if (vfull && !vfull.value) vfull.value = 'Alpha ' + v;
        if (v && tag) fetchChangelog(ttag.
    if (sel.options.length) {
        var opt = sel.options[sel.selectedIndex];
        var v   = opt.dataset.version || '';
        var tag = opt.value;
        if (vinp  && !vinp.value) vinp.value  = v;
        if (vfull && !vfull.value) vfull.value = 'Alpha ' + v;
        if (v && tag) fetchChangelog(tag, v);
    }
}());
</script>

<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
