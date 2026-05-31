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

// ── Key sync check: derived pubkey must match core/release-pubkey.php ─────────
// If these differ, any package built here will be signed with a key that installs
// don't recognise — the update will fail with a signature mismatch. Block the build.
if ($sc_derived_pubkey) {
    $sc_release_pubkey_path = dirname(__DIR__) . '/core/release-pubkey.php';
    if (!file_exists($sc_release_pubkey_path)) {
        $preflight[] = ['warn', 'core/release-pubkey.php not found — cannot verify key sync. Expected at: ' . $sc_release_pubkey_path];
    } else {
        $sc_pubkey_src = file_get_contents($sc_release_pubkey_path);
        if (preg_match("/define\s*\(\s*'SNAPSMACK_RELEASE_PUBKEY'\s*,\s*'([0-9a-f]{64})'\s*\)/", $sc_pubkey_src, $_km)) {
            if ($_km[1] !== $sc_derived_pubkey) {
                $preflight[] = ['err',
                    'KEY MISMATCH — core/release-pubkey.php has ' . $_km[1] . ' ' .
                    'but sc-config.php derives ' . $sc_derived_pubkey . '. ' .
                    'Update core/release-pubkey.php to match before building a release, ' .
                    'or every install will reject the signature.'
                ];
            }
        } else {
            $preflight[] = ['warn', 'Could not parse SNAPSMACK_RELEASE_PUBKEY from core/release-pubkey.php — verify the file is not corrupted.'];
        }
    }
}

$preflight_ok = !array_filter($preflight, fn($p) => $p[0] === 'err');

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

// ── Helper: list BITCHIN' (D-suffix) dev tags from GitHub ────────────────────
// Returns vX.Y.ZD tags sorted newest-first. At most 3.
function sc_list_dev_tags(): array {
    $data = sc_github_get('repos/' . SNAPSMACK_GITHUB_REPO . '/tags?per_page=50');
    if (!is_array($data)) return [];
    $tags = array_column($data, 'name');

    // Keep only D-suffix dev tags (e.g. v0.7.184D).
    $tags = array_values(array_filter($tags, function (string $t): bool {
        return (bool) preg_match('/^v?\d+\.\d+\.\d+D$/i', $t);
    }));

    // Sort descending — strip D suffix before numeric version_compare.
    usort($tags, function ($a, $b): int {
        $na = preg_replace('/D$/i', '', ltrim($a, 'vV'));
        $nb = preg_replace('/D$/i', '', ltrim($b, 'vV'));
        return version_compare($nb, $na);
    });

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
        // Skins no longer bundled — installer fetches mode-appropriate skins
        // from snapsmack.ca at install time via releases/skins/install-manifest.php
        'skins/50-shades-of-noah-grey/',
        'skins/new-horizon/',
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
        // Skins distributed via Skin Packager only — NOT in base release
        'skins/chaplin/',
        'skins/galleria/',
        'skins/photogram/',
        'skins/rational-geo/',
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
        'reference/',
        // Dev/meta files
        'CLAUDE.md',
        'CHANGELOG.md',
        'README.md',
        '.gitignore',
        // Server-specific signing key — each server has its own copy via FTP.
        // release-pubkey-sample.php ships instead so operators know what to create.
        'core/release-pubkey.php',
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

// ── POST: Delete dev build ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_dev_build') {
    $del_id = (int)($_POST['del_id'] ?? 0);
    if ($del_id > 0) {
        $db  = sc_db();
        $row = $db->prepare("SELECT download_url FROM sc_dev_builds WHERE id = ? LIMIT 1");
        $row->execute([$del_id]);
        $build = $row->fetch(PDO::FETCH_ASSOC);
        if ($build) {
            $zip_file = rtrim(RELEASES_DIR, '/') . '/' . basename($build['download_url']);
            if (file_exists($zip_file)) @unlink($zip_file);
            $db->prepare("DELETE FROM sc_dev_builds WHERE id = ?")->execute([$del_id]);
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
    // ── Publish key rotation ─────────────────────────────────────────────────
    if (isset($_POST['publish_rotation'])) {
        $new_pub   = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $_POST['rotation_new_pubkey'] ?? ''));
        $old_pub   = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $_POST['rotation_old_pubkey'] ?? ''));
        $reason    = trim($_POST['rotation_reason'] ?? '');
        $sig_input = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $_POST['rotation_sig'] ?? ''));
        $rot_error = '';
        $rot_ok    = false;

        if (strlen($new_pub) !== 64) {
            $rot_error = 'New pubkey must be exactly 64 hex characters.';
        } elseif (strlen($sig_input) !== 128) {
            $rot_error = 'Signature must be exactly 128 hex characters.';
        } else {
            // Build the canonical blob the admin signed locally
            $rot_blob = json_encode([
                'new_pubkey' => $new_pub,
                'old_pubkey' => $old_pub,
                'issued_at'  => gmdate('Y-m-d\TH:i:s\Z'),
                'reason'     => $reason,
            ], JSON_UNESCAPED_SLASHES);

            // Verify against the hardcoded root public key
            $root_pub_hex = 'd4c4256853fc046160f0f0028f3b48548eac50defdbd0803ef545d36d100eae5';
            try {
                $valid = function_exists('sodium_crypto_sign_verify_detached')
                    && sodium_crypto_sign_verify_detached(
                        sodium_hex2bin($sig_input),
                        $rot_blob,
                        sodium_hex2bin($root_pub_hex)
                    );
            } catch (Exception $e) {
                $valid = false;
            }

            if (!$valid) {
                $rot_error = 'Signature did not verify against the root public key. Re-sign the exact blob shown and try again.';
            } else {
                $json_dst = rtrim(RELEASES_DIR, '/') . '/key-rotation.json';
                $sig_dst  = rtrim(RELEASES_DIR, '/') . '/key-rotation.sig';
                if (file_put_contents($json_dst, $rot_blob) !== false
                    && file_put_contents($sig_dst, $sig_input) !== false) {
                    $rot_ok = true;
                } else {
                    $rot_error = 'Failed to write rotation files to ' . RELEASES_DIR . '. Check server permissions.';
                }
            }
        }
    }

    // ── Delete key rotation file ─────────────────────────────────────────────
    if (isset($_POST['delete_rotation'])) {
        @unlink(rtrim(RELEASES_DIR, '/') . '/key-rotation.json');
        @unlink(rtrim(RELEASES_DIR, '/') . '/key-rotation.sig');
    }

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

    // Extract only the section for the requested version — the full CHANGELOG is
    // ~200KB and returning it as a JSON blob truncates the response. Split on ##
    // headings, find the one that starts with the version number, return just that block.
    $version_bare = ltrim($tag, 'v'); // e.g. "0.7.93"
    $sections = preg_split('/^(?=## )/m', $body);
    $matched  = '';
    foreach ($sections as $section) {
        // Match "## 0.7.93" or "## 0.7.93 —" etc.
        if (preg_match('/^## ' . preg_quote($version_bare, '/') . '(\s|$)/m', $section)) {
            $matched = trim($section);
            break;
        }
    }
    if ($matched === '') {
        // Dev tags (e.g. 0.7.184D) won't have their own CHANGELOG entry — strip
        // the D suffix and fall back to the base version's section.
        $version_stripped = preg_replace('/D$/i', '', $version_bare);
        if ($version_stripped !== $version_bare) {
            foreach ($sections as $section) {
                if (preg_match('/^## ' . preg_quote($version_stripped, '/') . '(\s|$)/m', $section)) {
                    $matched = trim($section);
                    break;
                }
            }
        }
    }
    if ($matched === '') {
        // Version heading not found — return a short diagnostic rather than the full file
        echo json_encode(['ok' => false, 'error' => 'Version ' . $version_bare . ' not found in CHANGELOG.md']);
        exit;
    }

    echo json_encode(['ok' => true, 'content' => $matched]);
    exit;
}

$action           = $_POST['action'] ?? '';
$build_error      = '';
$build_log        = [];
$build_result     = null;
$dev_build_error  = '';
$dev_build_log    = [];
$dev_build_result = null;

// Retrieve flash results from session after redirect.
if (isset($_SESSION['sc_release_built'])) {
    $build_result = $_SESSION['sc_release_built'];
    unset($_SESSION['sc_release_built']);
}
if (isset($_SESSION['sc_dev_built'])) {
    $dev_build_result = $_SESSION['sc_dev_built'];
    unset($_SESSION['sc_dev_built']);
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
            // Step 2b: Inject current Ed25519 pubkey into setup.php inside the zip.
            // setup.php hardcodes SETUP_RELEASE_PUBKEY — if this is not refreshed at
            // build time it will go stale whenever the signing key changes, causing
            // every fresh install to fail signature verification.
            if ($sc_derived_pubkey) {
                $patcher = new ZipArchive();
                if ($patcher->open($zip_dest) === true) {
                    $setup_src = $patcher->getFromName('setup.php');
                    if ($setup_src !== false) {
                        $setup_patched = preg_replace(
                            "/define\s*\(\s*'SETUP_RELEASE_PUBKEY'\s*,\s*'[0-9a-fA-F]{64}'\s*\)/",
                            "define('SETUP_RELEASE_PUBKEY', '{$sc_derived_pubkey}')",
                            $setup_src
                        );
                        if ($setup_patched !== $setup_src) {
                            $patcher->deleteName('setup.php');
                            $patcher->addFromString('setup.php', $setup_patched);
                            $build_log[] = "→ setup.php pubkey injected ({$sc_derived_pubkey})";
                        } else {
                            $build_log[] = "→ setup.php pubkey already current — no patch needed";
                        }
                    } else {
                        $build_log[] = "→ WARNING: setup.php not found in zip — pubkey not injected";
                    }
                    $patcher->close();
                } else {
                    $build_log[] = "→ WARNING: could not open zip to patch setup.php — pubkey not injected";
                }
            } else {
                $build_log[] = "→ WARNING: sc_derived_pubkey not available — setup.php pubkey not injected";
            }

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
                    'signing_pubkey'     => $sc_derived_pubkey,
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

                // Write site-version.php for snapsmack.ca promo site
                $site_version_path = '/var/www/snapsmack.ca/includes/site-version.php';
                $site_version_content = "<?php\n" .
                    "define('SS_PROMO_VERSION',     '" . addslashes($version) . "');   // BORING track — stable\n" .
                    "define('SS_PROMO_DEV_VERSION', '');  // BITCHIN' track — updated by dev build\n" .
                    "define('SS_PROMO_CODENAME',    '" . addslashes($codename) . "');\n" .
                    "// ===== SNAPSMACK EOF =====\n";
                if (file_put_contents($site_version_path, $site_version_content) !== false) {
                    $build_log[] = "→ site-version.php written (v{$version})";
                } else {
                    $build_log[] = "→ site-version.php write failed (check path/permissions)";
                }

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

                // Step 7: Prune old release zips — keep only the 3 most recent on disk.
                // The DB already shows only 3 in the history table; this keeps disk tidy too.
                try {
                    $old_rows = sc_db()->query(
                        "SELECT download_url FROM sc_releases ORDER BY id DESC LIMIT 100 OFFSET 3"
                    )->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($old_rows as $old_url) {
                        $old_file = rtrim(RELEASES_DIR, '/') . '/' . basename($old_url);
                        if (file_exists($old_file)) {
                            @unlink($old_file);
                            $build_log[] = "→ Pruned old release zip: " . basename($old_file);
                        }
                    }
                } catch (Exception $e) {
                    $build_log[] = "→ Zip prune skipped: " . $e->getMessage();
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

// ── POST: Build dev release (BITCHIN' track) ──────────────────────────────────
// Same pipeline as stable but:
//   • Writes latest-dev.json — does NOT overwrite latest.json
//   • Does NOT update sc_releases DB or site-version.php
//   • Does NOT publish canonical SQL (stable build owns that)
if ($action === 'build_dev' && $preflight_ok) {

    // Defensive table creation — sc_schema_parse() silently drops this table;
    // create it here so the INSERT below never fails on first run.
    try {
        sc_db()->exec("CREATE TABLE IF NOT EXISTS `sc_dev_builds` (
            `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `version`         VARCHAR(20)   NOT NULL,
            `version_full`    VARCHAR(50)   NOT NULL,
            `git_tag`         VARCHAR(100)  NOT NULL,
            `checksum_sha256` VARCHAR(64)   NOT NULL,
            `download_url`    VARCHAR(500)  NOT NULL,
            `download_size`   INT UNSIGNED  NOT NULL DEFAULT 0,
            `built_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_built_at` (`built_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {}

    $tag            = trim($_POST['tag']            ?? '');
    $version        = trim($_POST['version']        ?? '');
    $version_full   = trim($_POST['version_full']   ?? '');
    $released       = trim($_POST['released']       ?? date('Y-m-d'));
    $requires_php   = trim($_POST['requires_php']   ?? '8.0');
    $requires_mysql = trim($_POST['requires_mysql'] ?? '5.7');
    $changelog_raw  = trim($_POST['changelog'] ?? '');
    $changelog      = array_values(array_filter(array_map('trim', explode("\n", $changelog_raw))));

    if (!preg_match('/^[a-zA-Z0-9._\-]+$/', $tag)) {
        $dev_build_error = 'Invalid tag format.';
    } elseif ($version === '' || $version_full === '') {
        $dev_build_error = 'Version and Version Full are required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $released)) {
        $dev_build_error = 'Invalid release date.';
    }

    if (!$dev_build_error) {
        $zip_name = 'snapsmack-' . preg_replace('/[^a-zA-Z0-9._\-]/', '', $version) . '.zip';
        $zip_dest = rtrim(RELEASES_DIR, '/') . '/' . $zip_name;

        $dev_build_log[] = "→ DEV BUILD — downloading tag {$tag} from GitHub…";
        $zip_result      = sc_build_release_zip($tag, $zip_dest, []);
        $dev_build_log[] = '→ ' . $zip_result['msg'];

        if (!$zip_result['ok']) {
            $dev_build_error = $zip_result['msg'];
        } else {
            // Inject current pubkey into setup.php
            if ($sc_derived_pubkey) {
                $patcher = new ZipArchive();
                if ($patcher->open($zip_dest) === true) {
                    $setup_src = $patcher->getFromName('setup.php');
                    if ($setup_src !== false) {
                        $setup_patched = preg_replace(
                            "/define\s*\(\s*'SETUP_RELEASE_PUBKEY'\s*,\s*'[0-9a-fA-F]{64}'\s*\)/",
                            "define('SETUP_RELEASE_PUBKEY', '{$sc_derived_pubkey}')",
                            $setup_src
                        );
                        if ($setup_patched !== $setup_src) {
                            $patcher->deleteName('setup.php');
                            $patcher->addFromString('setup.php', $setup_patched);
                            $dev_build_log[] = "→ setup.php pubkey injected ({$sc_derived_pubkey})";
                        } else {
                            $dev_build_log[] = "→ setup.php pubkey already current — no patch needed";
                        }
                    } else {
                        $dev_build_log[] = "→ WARNING: setup.php not found in zip — pubkey not injected";
                    }
                    $patcher->close();
                }
            }

            // SHA-256 + sign
            $dev_checksum    = hash_file('sha256', $zip_dest);
            $dev_build_log[] = "→ SHA-256: {$dev_checksum}";
            $dev_sig_hex     = '';
            try {
                $privkey         = sodium_hex2bin(SMACK_RELEASE_PRIVKEY);
                $sig             = sodium_crypto_sign_detached($dev_checksum, $privkey);
                $dev_sig_hex     = sodium_bin2hex($sig);
                $dev_build_log[] = "→ Signed OK";
            } catch (SodiumException $e) {
                $dev_build_error = 'Signing failed: ' . $e->getMessage();
            }

            if (!$dev_build_error) {
                $dev_file_size    = filesize($zip_dest);
                $dev_download_url = rtrim(RELEASES_URL, '/') . '/' . $zip_name;
                $dev_build_log[]  = "→ Zip saved: {$zip_dest} (" . number_format($dev_file_size / 1024, 1) . " KB)";

                // Write latest-dev.json — does NOT touch latest.json or canonical SQL
                $manifest = [
                    'version'         => $version,
                    'version_full'    => $version_full,
                    'released'        => $released,
                    'download_url'    => $dev_download_url,
                    'checksum_sha256' => $dev_checksum,
                    'signature'       => $dev_sig_hex,
                    'signing_pubkey'  => $sc_derived_pubkey,
                    'changelog'       => $changelog,
                    'schema_changes'  => false,
                    'requires_php'    => $requires_php,
                    'requires_mysql'  => $requires_mysql,
                    'download_size'   => $dev_file_size,
                    'track'           => 'dev',
                ];
                $dev_json_path = rtrim(RELEASES_DIR, '/') . '/latest-dev.json';
                file_put_contents($dev_json_path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $dev_build_log[] = "→ latest-dev.json written";

                // Update SS_PROMO_DEV_VERSION in site-version.php
                $sv_path = '/var/www/snapsmack.ca/includes/site-version.php';
                if (file_exists($sv_path)) {
                    $sv = file_get_contents($sv_path);
                    $sv = preg_replace(
                        "/define\('SS_PROMO_DEV_VERSION',\s*'[^']*'\)/",
                        "define('SS_PROMO_DEV_VERSION', '" . addslashes($version_full) . "')",
                        $sv
                    );
                    file_put_contents($sv_path, $sv);
                    $dev_build_log[] = "→ site-version.php dev version updated ({$version_full})";
                }

                // Write dev build history record
                try {
                    $db = sc_db();
                    $db->prepare(
                        "INSERT INTO sc_dev_builds
                            (version, version_full, git_tag, checksum_sha256, download_url, download_size)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    )->execute([$version, $version_full, $tag, $dev_checksum, $dev_download_url, $dev_file_size]);
                    // Keep last 10 dev builds only
                    $db->exec("DELETE FROM sc_dev_builds WHERE id NOT IN (SELECT id FROM (SELECT id FROM sc_dev_builds ORDER BY id DESC LIMIT 10) t)");
                    $dev_build_log[] = "→ Dev build history recorded";
                } catch (Exception $e) {
                    $dev_build_log[] = "→ WARNING: Could not write dev build history: " . $e->getMessage();
                }

                $_SESSION['sc_dev_built'] = [
                    'version'      => $version_full,
                    'tag'          => $tag,
                    'checksum'     => $dev_checksum,
                    'signature'    => $dev_sig_hex,
                    'download_url' => $dev_download_url,
                    'file_size'    => $dev_file_size,
                    'log'          => $dev_build_log,
                ];
                header('Location: sc-release.php?dev_built=1');
                exit;
            }
        }
    }
}

// ── Fetch data for the form ───────────────────────────────────────────────────
$tags = [];
$dev_tags = [];
if ($preflight_ok) {
    $tags     = sc_list_tags();
    $dev_tags = sc_list_dev_tags();
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

// Dev build history
$dev_builds = [];
try {
    $dev_builds = sc_db()->query(
        "SELECT id, version_full, git_tag, download_url, download_size, checksum_sha256, built_at
         FROM sc_dev_builds ORDER BY id DESC LIMIT 10"
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
<!-- ── Stable build success result ──────────────────────────────────────── -->
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

<?php if ($dev_build_result): ?>
<!-- ── Dev build success result ─────────────────────────────────────────── -->
<div class="sc-alert sc-alert--success" style="border-left-color:#e6a817;">
  Dev build <?php echo htmlspecialchars($dev_build_result['version']); ?> packaged — <code>latest-dev.json</code> updated.
</div>
<div class="sc-release-result">
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">Version</span>
    <span class="sc-release-result__val"><?php echo htmlspecialchars($dev_build_result['version']); ?> <span style="font-size:0.72rem;color:var(--sc-warn);text-transform:uppercase;letter-spacing:.5px;margin-left:6px;">BITCHIN'</span></span>
  </div>
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">Tag</span>
    <span class="sc-release-result__val"><?php echo htmlspecialchars($dev_build_result['tag']); ?></span>
  </div>
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">Download</span>
    <span class="sc-release-result__val">
      <a href="<?php echo htmlspecialchars($dev_build_result['download_url']); ?>" target="_blank">
        <?php echo htmlspecialchars($dev_build_result['download_url']); ?>
      </a>
      &nbsp;(<?php echo number_format($dev_build_result['file_size'] / 1024, 1); ?> KB)
    </span>
  </div>
  <div class="sc-release-result__row">
    <span class="sc-release-result__key">SHA-256</span>
    <span class="sc-release-result__val"><?php echo htmlspecialchars($dev_build_result['checksum']); ?></span>
  </div>
  <?php if (!empty($dev_build_result['log'])): ?>
  <details style="margin-top:12px;">
    <summary style="cursor:pointer; font-size:var(--sc-size-label); text-transform:uppercase; letter-spacing:.8px; color:var(--sc-text-dim);">Build Log</summary>
    <div class="sc-build-log"><?php echo htmlspecialchars(implode("\n", $dev_build_result['log'])); ?></div>
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

    <!-- ── Dev build (BITCHIN' track) ─────────────────────────────────────── -->
    <div class="sc-box" style="margin-top:20px; border-color:#806000;">
      <div class="sc-box-header" style="background:rgba(230,168,23,0.06);">
        <span class="sc-box-title">Dev Build</span>
        <span style="font-size:0.7rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#e6a817; margin-left:8px;">BITCHIN' TRACK</span>
        <span class="sc-dim" style="font-size:var(--sc-size-label); margin-left:auto;">writes latest-dev.json only</span>
      </div>
      <div class="sc-box-body">

        <?php if ($dev_build_error): ?>
        <div class="sc-alert sc-alert--error"><?php echo htmlspecialchars($dev_build_error); ?></div>
        <?php if (!empty($dev_build_log)): ?>
        <div class="sc-build-log"><?php echo htmlspecialchars(implode("\n", $dev_build_log)); ?></div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (!$preflight_ok): ?>
        <p class="sc-dim">Fix the errors above before building.</p>

        <?php elseif (empty($dev_tags)): ?>
        <div class="sc-alert sc-alert--warn">
          No D-suffix tags found (e.g. <code>v0.7.184D</code>). Tag the dev branch and push:<br>
          <code style="display:block;margin-top:6px;">git tag v0.7.184D &amp;&amp; git push Github v0.7.184D</code>
        </div>

        <?php else: ?>
        <form method="post" action="sc-release.php">
          <input type="hidden" name="action" value="build_dev">

          <div class="sc-field">
            <label>Git Tag <span style="color:#e6a817; font-size:0.7rem; margin-left:4px;">D-suffix only</span></label>
            <select name="tag" class="sc-select" id="dev-tag-select">
              <?php foreach ($dev_tags as $t): ?>
              <?php $dv = preg_replace('/D$/i', '', ltrim(preg_replace('/^v/i', '', $t), '')); ?>
              <option value="<?php echo htmlspecialchars($t); ?>"
                      data-version="<?php echo htmlspecialchars(ltrim(preg_replace('/^v/i', '', $t), '')); ?>"
                      data-version-bare="<?php echo htmlspecialchars($dv); ?>">
                <?php echo htmlspecialchars($t); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="sc-grid-2" style="gap:12px;">
            <div class="sc-field">
              <label>Version</label>
              <input type="text" name="version" id="dev-version-input"
                     value="<?php echo htmlspecialchars(ltrim(preg_replace('/^v/i', '', $dev_tags[0] ?? ''), '')); ?>">
              <span class="sc-hint">e.g. 0.7.184D</span>
            </div>
            <div class="sc-field">
              <label>Version Full</label>
              <input type="text" name="version_full" id="dev-version-full-input"
                     value="Alpha <?php echo htmlspecialchars(ltrim(preg_replace('/^v/i', '', $dev_tags[0] ?? ''), '')); ?>">
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

          <div class="sc-field">
            <label>
              Changelog
              <span id="dev-changelog-status" style="font-weight:400; font-size:var(--sc-size-label); margin-left:8px;"></span>
            </label>
            <textarea name="changelog" id="dev-changelog-textarea" rows="5"
                      placeholder="One entry per line. Auto-populated from CHANGELOG.md if a matching entry exists."
                      style="font-family:var(--sc-font-mono); font-size:0.78rem;"></textarea>
          </div>

          <div class="sc-btn-row" style="justify-content:flex-end; margin-top:8px;">
            <button type="submit" class="sc-btn" style="background:#806000; color:#fff;"
                    onclick="return confirm('Package dev build from tag: ' + document.getElementById('dev-tag-select').value + '?\n\nThis writes latest-dev.json only — latest.json is unchanged.');">
              BUILD DEV &amp; PUBLISH
            </button>
          </div>
        </form>
        <?php endif; ?>

      </div>
    </div><!-- /.sc-box dev -->

  </div>

  <!-- ── Right: Release history ────────────────────────────────────────────── -->
  <div>
    <div class="sc-box">
      <div class="sc-box-header">
        <span class="sc-box-title">Release History</span>
        <span style="font-size:var(--sc-size-label);color:var(--sc-text-dim);text-transform:uppercase;letter-spacing:.5px;">BORING TRACK</span>
      </div>
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

    <!-- ── Dev Build History ────────────────────────────────────────────── -->
    <div class="sc-box" style="margin-top:20px;">
      <div class="sc-box-header">
        <span class="sc-box-title">Dev Build History</span>
        <span style="font-size:var(--sc-size-label);color:var(--sc-warn);text-transform:uppercase;letter-spacing:.5px;">BITCHIN' track</span>
      </div>
      <div class="sc-box-body">
        <?php if (empty($dev_builds)): ?>
        <p class="sc-dim">No dev builds yet.</p>
        <?php else: ?>
        <table class="sc-table">
          <thead>
            <tr>
              <th>Version</th>
              <th>Tag</th>
              <th>Size</th>
              <th>Built</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($dev_builds as $b): ?>
          <tr>
            <td>
              <a href="<?php echo htmlspecialchars($b['download_url']); ?>" target="_blank">
                <?php echo htmlspecialchars($b['version_full']); ?>
              </a>
            </td>
            <td class="sc-dim sc-mono" style="font-size:0.72rem;"><?php echo htmlspecialchars($b['git_tag']); ?></td>
            <td class="sc-dim" style="white-space:nowrap;">
              <?php echo $b['download_size'] ? number_format($b['download_size'] / 1024, 0) . ' KB' : '—'; ?>
            </td>
            <td class="sc-dim" style="white-space:nowrap;"><?php echo htmlspecialchars(substr($b['built_at'], 0, 16)); ?></td>
            <td>
              <form method="post" onsubmit="return confirm('Delete <?php echo htmlspecialchars($b['version_full']); ?>? This removes the zip and DB record.')">
                <input type="hidden" name="action" value="delete_dev_build">
                <input type="hidden" name="del_id" value="<?php echo (int)$b['id']; ?>">
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

    <!-- ── Key Rotation panel ─────────────────────────────────────────────── -->
    <div class="sc-box" style="margin-top:20px;border-color:#c66;">
      <div class="sc-box-header">
        <span class="sc-box-title">Key Rotation</span>
        <span class="sc-dim" style="font-size:var(--sc-size-label);">Root-key-signed</span>
      </div>
      <div class="sc-box-body">

        <?php if (!empty($rot_ok)): ?>
        <div style="color:#0f0;margin-bottom:14px;">&#10003; Rotation files published to <code><?php echo rtrim(RELEASES_URL,'/'); ?>/key-rotation.json</code></div>
        <?php endif; ?>
        <?php if (!empty($rot_error)): ?>
        <div style="color:#f44;margin-bottom:14px;">&#9888; <?php echo htmlspecialchars($rot_error); ?></div>
        <?php endif; ?>

        <p class="sc-dim" style="margin-bottom:14px;">
          Use this when you need to rotate the release signing key. Installs that encounter
          a signature mismatch will fetch this file, verify it against the hardcoded root key,
          and automatically accept the new release key without manual intervention.
        </p>

        <p class="sc-dim" style="margin-bottom:6px;font-size:var(--sc-size-label);">STEP 1 — Fill in the new pubkey and an optional reason, then copy the blob below.</p>
        <form method="POST" id="rotation-form">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
            <div>
              <label class="sc-label">New pubkey (64 hex)</label>
              <input type="text" name="rotation_new_pubkey" id="rot-new-pub"
                     value="<?php echo htmlspecialchars($sc_derived_pubkey); ?>"
                     style="width:100%;font-family:monospace;font-size:0.78rem;"
                     maxlength="64" autocomplete="off" spellcheck="false">
            </div>
            <div>
              <label class="sc-label">Old pubkey (64 hex)</label>
              <input type="text" name="rotation_old_pubkey" id="rot-old-pub"
                     placeholder="previous public key"
                     style="width:100%;font-family:monospace;font-size:0.78rem;"
                     maxlength="64" autocomplete="off" spellcheck="false">
            </div>
          </div>
          <div style="margin-bottom:10px;">
            <label class="sc-label">Reason (optional)</label>
            <input type="text" name="rotation_reason" id="rot-reason"
                   placeholder="e.g. Key compromise, routine rotation"
                   style="width:100%;">
          </div>

          <label class="sc-label">Blob to sign (copy this, sign offline with root private key)</label>
          <div class="sc-code-block" id="rot-blob-preview" style="word-break:break-all;user-select:all;cursor:text;margin-bottom:10px;font-size:0.78rem;min-height:3em;">
            <em style="opacity:0.4;">Fill in new pubkey above to generate blob.</em>
          </div>

          <p class="sc-dim" style="margin-bottom:6px;font-size:var(--sc-size-label);">STEP 2 — On your local machine, run (requires PHP + sodium):</p>
          <div class="sc-code-block" style="font-size:0.75rem;word-break:break-all;margin-bottom:10px;">
            php -r "$blob = file_get_contents('rotation.json'); echo sodium_bin2hex(sodium_crypto_sign_detached($blob, sodium_hex2bin('YOUR_ROOT_PRIVKEY')));"
          </div>
          <p class="sc-dim" style="margin-bottom:6px;font-size:var(--sc-size-label);">STEP 3 — Paste the resulting hex signature here and publish.</p>
          <input type="text" name="rotation_sig" id="rot-sig"
                 placeholder="128-char hex Ed25519 signature from root key"
                 style="width:100%;font-family:monospace;font-size:0.78rem;margin-bottom:10px;"
                 maxlength="128" autocomplete="off" spellcheck="false">

          <button type="submit" name="publish_rotation" value="1" class="sc-btn"
                  style="background:#c66;"
                  onclick="return confirm('Publish this key rotation? Installs will pick it up automatically on next signature failure.');">
            PUBLISH ROTATION
          </button>
        </form>

        <?php
        $rot_json_path = rtrim(RELEASES_DIR, '/') . '/key-rotation.json';
        if (file_exists($rot_json_path)):
            $rot_live = json_decode(file_get_contents($rot_json_path), true);
        ?>
        <hr style="margin:18px 0;border-color:var(--sc-border);">
        <p class="sc-dim" style="font-size:var(--sc-size-label);">ACTIVE ROTATION FILE</p>
        <table style="font-size:0.8rem;margin-top:8px;border-collapse:collapse;">
          <tr><td style="opacity:0.5;padding:2px 12px 2px 0;">Issued</td><td style="font-family:monospace;"><?php echo htmlspecialchars($rot_live['issued_at'] ?? ''); ?></td></tr>
          <tr><td style="opacity:0.5;padding:2px 12px 2px 0;">Reason</td><td><?php echo htmlspecialchars($rot_live['reason'] ?? '—'); ?></td></tr>
          <tr><td style="opacity:0.5;padding:2px 12px 2px 0;">New key</td><td style="font-family:monospace;word-break:break-all;color:#0f0;"><?php echo htmlspecialchars($rot_live['new_pubkey'] ?? ''); ?></td></tr>
          <tr><td style="opacity:0.5;padding:2px 12px 2px 0;">Old key</td><td style="font-family:monospace;word-break:break-all;opacity:0.5;"><?php echo htmlspecialchars($rot_live['old_pubkey'] ?? ''); ?></td></tr>
        </table>
        <form method="POST" style="margin-top:12px;">
          <button type="submit" name="delete_rotation" value="1" class="sc-btn sc-btn--sm"
                  style="background:#600;font-size:0.7rem;"
                  onclick="return confirm('Delete the rotation file? Installs that have not yet updated their key will fall back to manual repair.');">
            DELETE ROTATION FILE
          </button>
        </form>
        <?php endif; ?>

      </div>
    </div>
  </div>

</div><!-- /.sc-grid-2 -->

<script>
// Auto-fill version, codename, and changelog fields when tag selection changes.
(function () {
    var repo    = <?php echo json_encode(SNAPSMACK_GITHUB_REPO); ?>;

    // Key rotation blob preview
    (function () {
        var newPub  = document.getElementById('rot-new-pub');
        var oldPub  = document.getElementById('rot-old-pub');
        var reason  = document.getElementById('rot-reason');
        var preview = document.getElementById('rot-blob-preview');
        if (!newPub || !preview) return;
        function updateBlob() {
            var blob = JSON.stringify({
                new_pubkey: newPub.value.trim().toLowerCase(),
                old_pubkey: (oldPub ? oldPub.value.trim().toLowerCase() : ''),
                issued_at:  new Date().toISOString().replace(/\.\d+Z$/, 'Z'),
                reason:     (reason ? reason.value.trim() : ''),
            });
            preview.textContent = blob;
        }
        [newPub, oldPub, reason].forEach(function(el) {
            if (el) el.addEventListener('input', updateBlob);
        });
        updateBlob();
    }());
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

    // ── Dev tag select auto-fill ──────────────────────────────────────────
    var devSel   = document.getElementById('dev-tag-select');
    var devVinp  = document.getElementById('dev-version-input');
    var devVfull = document.getElementById('dev-version-full-input');
    var devClta  = document.getElementById('dev-changelog-textarea');
    var devClst  = document.getElementById('dev-changelog-status');
    if (devSel) {
        [devVinp, devVfull].forEach(function (el) {
            if (el) el.addEventListener('input', function () { el.dataset.userEdited = '1'; });
        });
        if (devClta) devClta.addEventListener('input', function () { devClta.dataset.userEdited = '1'; });

        function devFetchChangelog(tag, versionBare) {
            if (!devClta || devClta.dataset.userEdited) return;
            var url = 'sc-release.php?action=fetch_changelog&tag=' + encodeURIComponent(tag);
            if (devClst) { devClst.textContent = '⟳ loading…'; devClst.style.color = 'var(--sc-color-dim, #888)'; }
            fetch(url)
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function (data) {
                    if (!data.ok) throw new Error(data.error || 'proxy error');
                    // Try with D suffix first, then bare version
                    var entries = parseChangelog(data.content, versionBare + 'D');
                    if (!entries.length) entries = parseChangelog(data.content, versionBare);
                    if (!devClta.dataset.userEdited) {
                        if (entries && entries.length) {
                            devClta.value = entries.join('\n');
                            if (devClst) { devClst.textContent = '✓ ' + entries.length + ' entries'; devClst.style.color = 'var(--sc-color-ok, #4caf50)'; }
                        } else {
                            devClta.value = '';
                            if (devClst) { devClst.textContent = '⚠ No entry found in CHANGELOG.md — enter manually'; devClst.style.color = 'var(--sc-color-warn, #e6a817)'; }
                        }
                    }
                })
                .catch(function (err) {
                    if (devClst) { devClst.textContent = '✗ ' + err.message; devClst.style.color = 'var(--sc-color-err, #e05252)'; }
                });
        }

        devSel.addEventListener('change', function () {
            var opt  = devSel.options[devSel.selectedIndex];
            var v    = opt.dataset.version || '';
            var vb   = opt.dataset.versionBare || v.replace(/D$/i, '');
            var tag  = opt.value;
            if (devVinp  && !devVinp.dataset.userEdited)  devVinp.value  = v;
            if (devVfull && !devVfull.dataset.userEdited)  devVfull.value = 'Alpha ' + v;
            if (tag) devFetchChangelog(tag, vb);
        });

        if (devSel.options.length) {
            var dopt = devSel.options[devSel.selectedIndex];
            var dv   = dopt.dataset.version || '';
            var dvb  = dopt.dataset.versionBare || dv.replace(/D$/i, '');
            var dtag = dopt.value;
            if (dtag) devFetchChangelog(dtag, dvb);
        }
    }
}());
</script>

<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
<?php // ===== SNAPSMACK EOF =====
