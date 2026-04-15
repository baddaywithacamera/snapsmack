<?php
/**
 * SMACK CENTRAL - Skin Packager
 *
 * Downloads the SnapSmack repo zip from GitHub for a given branch or tag,
 * extracts the skins/ directory, lets you pick which skins to package,
 * zips each one, signs with Ed25519, saves to RELEASES_DIR/skins/, and
 * updates the skin registry JSON at RELEASES_DIR/skins/registry.json.
 *
 * No local repo clone required — pulls directly from GitHub.
 */

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-skins.php';
$sc_page_title = 'Skin Packager';

@set_time_limit(300);

// ── GitHub config ─────────────────────────────────────────────────────────────
if (!defined('SNAPSMACK_GITHUB_REPO'))  define('SNAPSMACK_GITHUB_REPO',  'baddaywithacamera/snapsmack');
if (!defined('SNAPSMACK_GITHUB_TOKEN')) define('SNAPSMACK_GITHUB_TOKEN', '');

// ── Preflight ─────────────────────────────────────────────────────────────────
$preflight = [];

if (!function_exists('sodium_crypto_sign_detached')) {
    $preflight[] = ['err', 'libsodium not available. PHP 7.2+ with sodium extension required.'];
}
if (!class_exists('ZipArchive')) {
    $preflight[] = ['err', 'ZipArchive not available. Install the php-zip extension.'];
}
if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
    $preflight[] = ['err', 'No HTTP fetch method available. Enable curl or allow_url_fopen in php.ini.'];
}
if (!defined('SMACK_RELEASE_PRIVKEY') || strlen(SMACK_RELEASE_PRIVKEY) !== 128) {
    $preflight[] = ['err', 'SMACK_RELEASE_PRIVKEY not set or wrong length. Check sc-config.php.'];
}
if (!defined('RELEASES_DIR') || RELEASES_DIR === '' || RELEASES_DIR === '/') {
    $preflight[] = ['err', 'RELEASES_DIR is not configured. Check sc-config.php.'];
} elseif (!is_dir(RELEASES_DIR)) {
    $preflight[] = ['warn', 'RELEASES_DIR does not exist: ' . RELEASES_DIR . '. Create it before packaging.'];
}
if (!defined('RELEASES_URL')) {
    $preflight[] = ['err', 'RELEASES_URL is not configured. Check sc-config.php.'];
}

$preflight_ok = !array_filter($preflight, fn($p) => $p[0] === 'err');

// ── HTTP helpers (guarded so sc-release.php can coexist if ever included) ─────

if (!function_exists('sc_http_raw')) {
    function sc_http_raw(string $url, array $extra_headers = [], int $timeout = 120): string|false {
        if (function_exists('curl_init')) {
            $ch   = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'SnapSmack-SC/0.7.9c',
            ];
            if ($extra_headers) $opts[CURLOPT_HTTPHEADER] = $extra_headers;
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($body !== false && $code === 200) ? $body : false;
        }
        $ctx_opts = ['http' => ['timeout' => 120, 'user_agent' => 'SnapSmack-SC/0.7.9c']];
        if ($extra_headers) $ctx_opts['http']['header'] = implode("\r\n", $extra_headers);
        return @file_get_contents($url, false, stream_context_create($ctx_opts));
    }
}

if (!function_exists('sc_github_get')) {
    function sc_github_get(string $endpoint): array|false {
        $headers = ['Accept: application/vnd.github.v3+json', 'User-Agent: SnapSmack-SC/0.7.9c'];
        if (SNAPSMACK_GITHUB_TOKEN) $headers[] = 'Authorization: token ' . SNAPSMACK_GITHUB_TOKEN;
        $body = sc_http_raw('https://api.github.com/' . ltrim($endpoint, '/'), $headers, 15);
        if ($body === false) return false;
        $data = json_decode($body, true);
        return is_array($data) ? $data : false;
    }
}

// ── Fetch branches and tags from GitHub for the ref picker ───────────────────

function sc_skins_get_refs(): array {
    $branches = sc_github_get('repos/' . SNAPSMACK_GITHUB_REPO . '/branches?per_page=30');
    $tags     = sc_github_get('repos/' . SNAPSMACK_GITHUB_REPO . '/tags?per_page=30');
    $result   = ['branches' => [], 'tags' => []];
    if (is_array($branches)) $result['branches'] = array_column($branches, 'name');
    if (is_array($tags))     $result['tags']     = array_column($tags, 'name');
    return $result;
}

// ── Download repo zip and extract skins/ to a temp directory ─────────────────

function sc_extract_skins(string $ref): array {
    // Works for both branches and tags
    $url  = 'https://github.com/' . SNAPSMACK_GITHUB_REPO . '/archive/' . urlencode($ref) . '.zip';
    $data = sc_http_raw($url);
    if ($data === false) {
        return ['ok' => false, 'msg' => 'Could not download repo zip from GitHub. Check outbound HTTPS and verify the ref exists.'];
    }

    $tmp_zip = sys_get_temp_dir() . '/sc_skins_' . md5($ref . microtime()) . '.zip';
    file_put_contents($tmp_zip, $data);
    unset($data);

    $src = new ZipArchive();
    if ($src->open($tmp_zip) !== true) {
        @unlink($tmp_zip);
        return ['ok' => false, 'msg' => 'Downloaded zip could not be opened.'];
    }

    // GitHub wraps everything in a "{repo}-{ref}/" prefix — find it
    $prefix = '';
    for ($i = 0; $i < $src->numFiles; $i++) {
        $name = $src->getNameIndex($i);
        if (preg_match('#^([^/]+/)skins/#', $name, $m)) {
            $prefix = $m[1];
            break;
        }
    }

    if ($prefix === '') {
        $src->close();
        @unlink($tmp_zip);
        return ['ok' => false, 'msg' => 'Could not locate skins/ directory in the downloaded zip.'];
    }

    // Extract only the skins/ subtree into a temp directory
    $tmp_key  = md5($ref . uniqid('', true));
    $tmp_dir  = sys_get_temp_dir() . '/sc_skins_' . $tmp_key . '/';
    @mkdir($tmp_dir, 0755, true);

    $skin_prefix = $prefix . 'skins/';
    for ($i = 0; $i < $src->numFiles; $i++) {
        $name = $src->getNameIndex($i);
        if (!str_starts_with($name, $skin_prefix)) continue;

        // Path relative to the skins/ dir: e.g. "galleria/style.css"
        $relative = substr($name, strlen($skin_prefix));
        if ($relative === '' || str_ends_with($relative, '/')) continue; // directory entry

        $dest = $tmp_dir . $relative;
        $dest_dir = dirname($dest);
        if (!is_dir($dest_dir)) @mkdir($dest_dir, 0755, true);

        $content = $src->getFromIndex($i);
        if ($content !== false) file_put_contents($dest, $content);
    }

    $src->close();
    @unlink($tmp_zip);

    return ['ok' => true, 'tmp_dir' => $tmp_dir, 'tmp_key' => $tmp_key];
}

// ── Read manifests from an extracted skins directory ─────────────────────────

function sc_read_manifests(string $skins_dir): array {
    $skins = [];
    foreach (glob(rtrim($skins_dir, '/') . '/*/manifest.php') as $mf) {
        $slug = basename(dirname($mf));
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) continue;
        $manifest = @include $mf;
        if (!is_array($manifest)) continue;
        $skins[$slug] = [
            'name'               => $manifest['name']               ?? ucfirst($slug),
            'version'            => $manifest['version']            ?? '1.0',
            'status'             => $manifest['status']             ?? 'stable',
            'author'             => $manifest['author']             ?? 'Unknown',
            'description'        => $manifest['description']        ?? '',
            'features'           => $manifest['features']           ?? [],
            'requires_php'       => $manifest['requires_php']       ?? '8.0',
            'requires_snapsmack' => $manifest['requires_snapsmack'] ?? '0.7',
        ];
    }
    ksort($skins);
    return $skins;
}

// ── Load existing registry ────────────────────────────────────────────────────

$registry_path    = rtrim(RELEASES_DIR, '/') . '/skins/registry.json';
$existing_registry = ['registry_version' => 1, 'generated' => '', 'skins' => []];
if (file_exists($registry_path)) {
    $j = @file_get_contents($registry_path);
    if ($j) { $d = json_decode($j, true); if (is_array($d)) $existing_registry = $d; }
}

// ── State machine ─────────────────────────────────────────────────────────────
// phase 1 (GET):          show ref picker
// phase 2 (POST fetch):   download + extract, show skin selection
// phase 3 (POST package): package selected skins, show results

$phase        = 'select_ref';
$ref          = trim($_POST['ref'] ?? 'master');
$tmp_key      = preg_replace('/[^a-f0-9]/', '', $_POST['tmp_key'] ?? '');
$tmp_dir      = $tmp_key ? sys_get_temp_dir() . '/sc_skins_' . $tmp_key . '/' : '';
$repo_skins   = [];
$build_results = [];
$build_errors  = [];
$fetch_error  = '';

$refs = [];
if ($preflight_ok && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $refs = sc_skins_get_refs();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $preflight_ok) {

    // ── Phase 2: fetch skins from GitHub ─────────────────────────────────────
    if (isset($_POST['fetch_skins'])) {
        $result = sc_extract_skins($ref);
        if (!$result['ok']) {
            $fetch_error = $result['msg'];
            $phase = 'select_ref';
            $refs  = sc_skins_get_refs();
        } else {
            $tmp_dir    = $result['tmp_dir'];
            $tmp_key    = $result['tmp_key'];
            $repo_skins = sc_read_manifests($tmp_dir);
            $phase = empty($repo_skins) ? 'select_ref' : 'select_skins';
            if (empty($repo_skins)) {
                $fetch_error = 'No valid skins found in skins/ for ref: ' . htmlspecialchars($ref);
                $refs = sc_skins_get_refs();
                // Clean up empty tmp dir
                @rmdir($tmp_dir);
            }
        }
    }

    // ── Phase 3: package selected skins ──────────────────────────────────────
    elseif (isset($_POST['package'])) {
        $phase = 'results';

        // Use cached temp dir if it still exists; re-download if not
        if (!$tmp_key || !is_dir($tmp_dir)) {
            $result = sc_extract_skins($ref);
            if (!$result['ok']) {
                $build_errors[] = 'Re-download failed: ' . $result['msg'];
                $phase = 'results';
                goto render;
            }
            $tmp_dir = $result['tmp_dir'];
            $tmp_key = $result['tmp_key'];
        }

        $repo_skins = sc_read_manifests($tmp_dir);
        $selected   = (array)($_POST['skins'] ?? []);

        if (empty($selected)) {
            $build_errors[] = 'No skins selected.';
        } else {
            $skins_out_dir = rtrim(RELEASES_DIR, '/') . '/skins';
            if (!is_dir($skins_out_dir)) @mkdir($skins_out_dir, 0755, true);
            if (!is_dir($skins_out_dir)) {
                $build_errors[] = 'Could not create output directory: ' . $skins_out_dir;
            }
        }

        if (empty($build_errors)) {
            $privkey = sodium_hex2bin(SMACK_RELEASE_PRIVKEY);

            foreach ($selected as $slug) {
                $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
                if (!isset($repo_skins[$slug])) {
                    $build_results[] = ['slug' => $slug, 'ok' => false, 'msg' => 'Skin not found.'];
                    continue;
                }

                $meta         = $repo_skins[$slug];
                $skin_dir     = rtrim($tmp_dir, '/') . '/' . $slug;
                $version      = $meta['version'];
                $zip_name     = $slug . '-' . $version . '.zip';
                $zip_path     = rtrim(RELEASES_DIR, '/') . '/skins/' . $zip_name;
                $download_url = rtrim(RELEASES_URL, '/') . '/skins/' . $zip_name;

                $screenshot = trim($_POST['screenshot'][$slug] ?? '');
                if ($screenshot === '') {
                    $screenshot = $existing_registry['skins'][$slug]['screenshot'] ?? '';
                }

                // Auto-detect screenshot files and copy them to the releases screenshots directory.
                // The gallery uses these for multi-screenshot previews of non-installed skins.
                $screenshot_names  = [
                    'screenshot-landing.png' => 'Landing',
                    'screenshot-archive.png' => 'Archive',
                    'screenshot-page.png'    => 'Text Page',
                    'screenshot.png'         => 'Preview',
                ];
                $ss_dest_dir = rtrim(RELEASES_DIR, '/') . '/skins/screenshots/' . $slug;
                $ss_dest_url = rtrim(RELEASES_URL, '/') . '/skins/screenshots/' . $slug;
                $detected_screenshots = [];
                foreach ($screenshot_names as $filename => $label) {
                    $src_file = rtrim($skin_dir, '/') . '/' . $filename;
                    if (file_exists($src_file)) {
                        if (!is_dir($ss_dest_dir)) @mkdir($ss_dest_dir, 0755, true);
                        @copy($src_file, $ss_dest_dir . '/' . $filename);
                        $detected_screenshots[] = ['src' => $ss_dest_url . '/' . $filename, 'label' => $label];
                        // Use the landing shot (or first found) as the legacy single-screenshot field
                        if ($screenshot === '') {
                            $screenshot = $ss_dest_url . '/' . $filename;
                        }
                    }
                }

                if (file_exists($zip_path)) @unlink($zip_path);

                $zip = new ZipArchive();
                if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    $build_results[] = ['slug' => $slug, 'ok' => false, 'msg' => 'Failed to create zip.'];
                    continue;
                }

                $file_count = 0;
                $rit = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($skin_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($rit as $file) {
                    if ($file->isFile()) {
                        $real_path = $file->getRealPath();
                        $relative  = str_replace('\\', '/', substr($real_path, strlen($skin_dir) + 1));
                        $zip->addFile($real_path, $relative);
                        $file_count++;
                    }
                }
                $zip->close();

                if ($file_count === 0) {
                    @unlink($zip_path);
                    $build_results[] = ['slug' => $slug, 'ok' => false, 'msg' => 'Skin directory was empty in zip.'];
                    continue;
                }

                $zip_data = file_get_contents($zip_path);
                $zip_size = strlen($zip_data);
                $sig_hex  = sodium_bin2hex(sodium_crypto_sign_detached($zip_data, $privkey));
                unset($zip_data);

                $existing_registry['skins'][$slug] = [
                    'name'               => $meta['name'],
                    'version'            => $version,
                    'status'             => $meta['status'],
                    'author'             => $meta['author'],
                    'description'        => $meta['description'],
                    'screenshot'         => $screenshot,
                    'screenshots'        => $detected_screenshots,
                    'download_url'       => $download_url,
                    'download_size'      => $zip_size,
                    'signature'          => $sig_hex,
                    'requires_php'       => $meta['requires_php'],
                    'requires_snapsmack' => $meta['requires_snapsmack'],
                    'features'           => $meta['features'],
                ];

                $build_results[] = [
                    'slug'      => $slug,
                    'name'      => $meta['name'],
                    'ok'        => true,
                    'zip_name'  => $zip_name,
                    'zip_size'  => $zip_size,
                    'signature' => $sig_hex,
                    'files'     => $file_count,
                ];
            }

            // Write registry
            $existing_registry['generated'] = gmdate('Y-m-d\TH:i:s\Z');
            $registry_json = json_encode($existing_registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($registry_path, $registry_json) === false) {
                $build_errors[] = 'Packaged skins successfully, but could not write registry.json to: ' . $registry_path;
            }
        }

        // Clean up temp dir
        if ($tmp_dir && is_dir($tmp_dir)) {
            $rit = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($rit as $f) {
                $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
            }
            @rmdir($tmp_dir);
        }
    }
}

render:

// ── HTML ──────────────────────────────────────────────────────────────────────
include __DIR__ . '/sc-layout-top.php';
?>

<div class="sc-page-head">
    <h1 class="sc-page-title">Skin Packager</h1>
    <p class="sc-dim">Pull skins from GitHub, package, sign, and publish to the registry.</p>
</div>

<?php if (!empty($preflight)): ?>
    <div class="sc-card" style="margin-bottom:20px;">
        <h2 class="sc-card-title">Preflight</h2>
        <?php foreach ($preflight as [$level, $msg]): ?>
            <div class="sc-alert sc-alert--<?php echo $level === 'err' ? 'error' : 'warn'; ?>">
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php // ── Phase 1: ref picker ───────────────────────────────────────────────── ?>
<?php if ($phase === 'select_ref' && $preflight_ok): ?>

    <?php if ($fetch_error): ?>
        <div class="sc-alert sc-alert--error" style="margin-bottom:16px;">
            <?php echo htmlspecialchars($fetch_error); ?>
        </div>
    <?php endif; ?>

    <div class="sc-card">
        <h2 class="sc-card-title">Select Branch or Tag</h2>
        <p class="sc-dim" style="margin-bottom:20px; font-size:0.875rem;">
            SnapSmack will download the repo zip from
            <strong><?php echo htmlspecialchars(SNAPSMACK_GITHUB_REPO); ?></strong>
            and extract the skins directory for packaging.
        </p>
        <form method="POST" action="sc-skins.php">
            <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                <div>
                    <label style="display:block; font-size:0.8rem; letter-spacing:1px; text-transform:uppercase; margin-bottom:6px; color:#aaa;">
                        Branch or Tag
                    </label>
                    <?php if (!empty($refs['branches']) || !empty($refs['tags'])): ?>
                        <select name="ref" style="min-width:220px; padding:8px 10px; background:#111; border:1px solid #333; color:#ccc; font-size:0.9rem;">
                            <?php if (!empty($refs['branches'])): ?>
                                <optgroup label="Branches">
                                    <?php foreach ($refs['branches'] as $b): ?>
                                        <option value="<?php echo htmlspecialchars($b); ?>" <?php echo $b === 'master' ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($b); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($refs['tags'])): ?>
                                <optgroup label="Tags">
                                    <?php foreach ($refs['tags'] as $t): ?>
                                        <option value="<?php echo htmlspecialchars($t); ?>">
                                            <?php echo htmlspecialchars($t); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" name="ref" value="master"
                               style="min-width:220px; padding:8px 10px; background:#111; border:1px solid #333; color:#ccc; font-size:0.9rem;"
                               placeholder="branch or tag name">
                        <div class="sc-dim" style="font-size:0.78rem; margin-top:4px;">
                            Could not fetch ref list from GitHub — enter manually.
                        </div>
                    <?php endif; ?>
                </div>
                <button type="submit" name="fetch_skins" value="1" class="sc-btn sc-btn--primary">
                    Fetch Skins
                </button>
            </div>
        </form>
    </div>

<?php // ── Phase 2: skin selection ────────────────────────────────────────── ?>
<?php elseif ($phase === 'select_skins'): ?>

    <div class="sc-card">
        <h2 class="sc-card-title">
            Available Skins
            <span class="sc-dim" style="font-weight:400; font-size:0.85rem;">
                — <?php echo count($repo_skins); ?> found in
                <code><?php echo htmlspecialchars($ref); ?></code>
            </span>
        </h2>
        <p class="sc-dim" style="margin-bottom:20px; font-size:0.875rem;">
            Select skins to package. Each will be zipped, signed, and added (or updated)
            in the registry. Unselected skins keep their existing registry entries.
        </p>

        <form method="POST" action="sc-skins.php">
            <input type="hidden" name="ref"     value="<?php echo htmlspecialchars($ref); ?>">
            <input type="hidden" name="tmp_key" value="<?php echo htmlspecialchars($tmp_key); ?>">

            <table class="sc-table" style="width:100%;">
                <thead>
                    <tr>
                        <th style="width:36px;"></th>
                        <th>Skin</th>
                        <th>Version</th>
                        <th>Status</th>
                        <th>In Registry</th>
                        <th>Screenshot URL</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($repo_skins as $slug => $meta):
                    $in_registry    = isset($existing_registry['skins'][$slug]);
                    $reg_version    = $existing_registry['skins'][$slug]['version'] ?? null;
                    $reg_screenshot = $existing_registry['skins'][$slug]['screenshot'] ?? '';
                    $needs_update   = $in_registry && $reg_version !== $meta['version'];
                    $status_class   = match($meta['status']) {
                        'stable'      => 'sc-status--ok',
                        'beta'        => 'sc-status--warn',
                        'development' => 'sc-status--dim',
                        default       => '',
                    };
                ?>
                    <tr>
                        <td style="text-align:center;">
                            <input type="checkbox" name="skins[]"
                                   value="<?php echo htmlspecialchars($slug); ?>"
                                   id="skin_<?php echo htmlspecialchars($slug); ?>"
                                   <?php echo ($in_registry || $meta['status'] === 'stable') ? 'checked' : ''; ?>>
                        </td>
                        <td>
                            <label for="skin_<?php echo htmlspecialchars($slug); ?>" style="cursor:pointer;">
                                <strong><?php echo htmlspecialchars($meta['name']); ?></strong><br>
                                <span class="sc-dim" style="font-size:0.8rem;"><?php echo htmlspecialchars($slug); ?></span>
                            </label>
                            <?php if (!empty($meta['description'])): ?>
                                <div style="font-size:0.78rem; color:#666; margin-top:3px; max-width:300px;">
                                    <?php echo htmlspecialchars($meta['description']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php echo htmlspecialchars($meta['version']); ?>
                            <?php if ($needs_update): ?>
                                <span class="sc-status--warn" style="font-size:0.75rem; margin-left:4px;">
                                    (was <?php echo htmlspecialchars($reg_version); ?>)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?php echo $status_class; ?>" style="font-size:0.82rem; text-transform:uppercase; letter-spacing:1px;">
                                <?php echo htmlspecialchars($meta['status']); ?>
                            </span>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($in_registry): ?>
                                <span class="sc-status--ok">✓</span>
                            <?php else: ?>
                                <span class="sc-dim">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="text" name="screenshot[<?php echo htmlspecialchars($slug); ?>]"
                                   value="<?php echo htmlspecialchars($reg_screenshot); ?>"
                                   placeholder="https://snapsmack.ca/releases/skins/screenshots/<?php echo htmlspecialchars($slug); ?>.png"
                                   style="width:100%; font-size:0.8rem; padding:4px 6px; background:#111; border:1px solid #333; color:#ccc;">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top:20px; display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                <button type="submit" name="package" value="1" class="sc-btn sc-btn--primary">
                    Package Selected Skins
                </button>
                <a href="sc-skins.php" class="sc-btn">← Change Ref</a>
                <span class="sc-dim" style="font-size:0.85rem;">
                    Output: <code><?php echo htmlspecialchars(rtrim(RELEASES_DIR, '/') . '/skins/'); ?></code>
                </span>
            </div>
        </form>
    </div>

<?php // ── Phase 3: results ──────────────────────────────────────────────── ?>
<?php elseif ($phase === 'results'): ?>

    <div class="sc-card">
        <h2 class="sc-card-title">Build Results</h2>

        <?php foreach ($build_errors as $err): ?>
            <div class="sc-alert sc-alert--error"><?php echo htmlspecialchars($err); ?></div>
        <?php endforeach; ?>

        <?php foreach ($build_results as $r): ?>
            <?php if ($r['ok']): ?>
                <div class="sc-alert sc-alert--ok" style="margin-bottom:8px;">
                    <strong><?php echo htmlspecialchars($r['name']); ?></strong>
                    — <?php echo htmlspecialchars($r['zip_name']); ?>
                    (<?php echo round($r['zip_size'] / 1024, 1); ?> KB,
                     <?php echo $r['files']; ?> files)<br>
                    <span class="sc-dim" style="font-size:0.8rem; word-break:break-all;">
                        sig: <?php echo htmlspecialchars($r['signature']); ?>
                    </span>
                </div>
            <?php else: ?>
                <div class="sc-alert sc-alert--error" style="margin-bottom:8px;">
                    <strong><?php echo htmlspecialchars($r['slug']); ?></strong>
                    — <?php echo htmlspecialchars($r['msg']); ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!empty($build_results) && empty($build_errors)): ?>
            <p style="margin-top:12px; color:#6f9; font-size:0.85rem;">
                ✓ Registry updated: <code><?php echo htmlspecialchars($registry_path); ?></code>
            </p>
            <p class="sc-dim" style="font-size:0.82rem; margin-top:4px;">
                Registry URL: <a href="<?php echo htmlspecialchars(rtrim(RELEASES_URL, '/') . '/skins/registry.json'); ?>"
                    target="_blank"><?php echo htmlspecialchars(rtrim(RELEASES_URL, '/') . '/skins/registry.json'); ?></a>
            </p>
        <?php endif; ?>

        <div style="margin-top:20px;">
            <a href="sc-skins.php" class="sc-btn">← Package More Skins</a>
        </div>
    </div>

<?php endif; ?>

<?php // ── Current registry ───────────────────────────────────────────────── ?>
<?php if ($preflight_ok && !empty($existing_registry['skins']) && $phase !== 'results'): ?>
    <div class="sc-card" style="margin-top:20px;">
        <h2 class="sc-card-title">
            Current Registry
            <?php if ($existing_registry['generated']): ?>
                <span class="sc-dim" style="font-weight:400; font-size:0.82rem;">
                    — generated <?php echo htmlspecialchars($existing_registry['generated']); ?>
                </span>
            <?php endif; ?>
        </h2>
        <table class="sc-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Slug</th><th>Name</th><th>Version</th><th>Status</th><th>Download</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($existing_registry['skins'] as $slug => $entry): ?>
                <tr>
                    <td class="sc-dim"><?php echo htmlspecialchars($slug); ?></td>
                    <td><?php echo htmlspecialchars($entry['name'] ?? $slug); ?></td>
                    <td><?php echo htmlspecialchars($entry['version'] ?? '—'); ?></td>
                    <td style="text-transform:uppercase; font-size:0.8rem; letter-spacing:1px;">
                        <?php echo htmlspecialchars($entry['status'] ?? '—'); ?>
                    </td>
                    <td style="font-size:0.8rem;">
                        <?php if (!empty($entry['download_url'])): ?>
                            <a href="<?php echo htmlspecialchars($entry['download_url']); ?>" target="_blank" style="color:#aaa;">
                                <?php echo htmlspecialchars(basename($entry['download_url'])); ?>
                            </a>
                            <?php if (!empty($entry['download_size'])): ?>
                                <span class="sc-dim">(<?php echo round($entry['download_size'] / 1024, 1); ?> KB)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="sc-dim">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:12px; font-size:0.82rem;">
            <a href="<?php echo htmlspecialchars(rtrim(RELEASES_URL, '/') . '/skins/registry.json'); ?>"
               target="_blank" class="sc-dim">View registry.json ↗</a>
        </p>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/sc-layout-bottom.php'; ?>
