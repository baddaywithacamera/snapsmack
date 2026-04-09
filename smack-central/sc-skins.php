<?php
/**
 * SMACK CENTRAL - Skin Packager
 * Alpha v0.7.9c
 *
 * Reads skins from SNAPSMACK_REPO_PATH/skins/, lets you pick which ones to
 * package, zips each one, signs the zip with Ed25519, saves it to
 * RELEASES_DIR/skins/, and updates the skin registry JSON at
 * RELEASES_DIR/skins/registry.json.
 *
 * Existing registry entries for skins you didn't select are preserved.
 * Screenshot URLs survive re-packages (pulled from the existing registry).
 */

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-skins.php';
$sc_page_title = 'Skin Packager';

@set_time_limit(300);

// ── Preflight ─────────────────────────────────────────────────────────────────
$preflight = [];

if (!function_exists('sodium_crypto_sign_detached')) {
    $preflight[] = ['err', 'libsodium not available. PHP 7.2+ with sodium extension required.'];
}
if (!class_exists('ZipArchive')) {
    $preflight[] = ['err', 'ZipArchive not available. Install the php-zip extension.'];
}
if (!defined('SMACK_RELEASE_PRIVKEY') || strlen(SMACK_RELEASE_PRIVKEY) !== 128) {
    $preflight[] = ['err', 'SMACK_RELEASE_PRIVKEY not set or wrong length. Check sc-config.php.'];
}
if (!defined('SNAPSMACK_REPO_PATH') || !is_dir(SNAPSMACK_REPO_PATH)) {
    $preflight[] = ['err', 'SNAPSMACK_REPO_PATH is not set or the directory does not exist. Check sc-config.php.'];
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

// ── Load skins from repo ──────────────────────────────────────────────────────
$repo_skins = [];   // slug → manifest data

if ($preflight_ok || !array_filter($preflight, fn($p) => $p[0] === 'err' && str_contains($p[1], 'SNAPSMACK_REPO_PATH'))) {
    $skins_dir = rtrim(SNAPSMACK_REPO_PATH, '/') . '/skins';
    if (is_dir($skins_dir)) {
        foreach (glob($skins_dir . '/*/manifest.php') as $mf) {
            $slug = basename(dirname($mf));
            // Skip hidden dirs or anything that's not a valid slug
            if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) continue;
            $manifest = @include $mf;
            if (!is_array($manifest)) continue;
            $repo_skins[$slug] = [
                'name'        => $manifest['name']        ?? ucfirst($slug),
                'version'     => $manifest['version']     ?? '1.0',
                'status'      => $manifest['status']      ?? 'stable',
                'author'      => $manifest['author']      ?? 'Unknown',
                'description' => $manifest['description'] ?? '',
                'features'    => $manifest['features']    ?? [],
                'requires_php'        => $manifest['requires_php']        ?? '8.0',
                'requires_snapsmack'  => $manifest['requires_snapsmack']  ?? '0.7',
            ];
        }
        ksort($repo_skins);
    }
}

// ── Load existing registry ────────────────────────────────────────────────────
$registry_path = rtrim(RELEASES_DIR, '/') . '/skins/registry.json';
$existing_registry = ['registry_version' => 1, 'generated' => '', 'skins' => []];
if (file_exists($registry_path)) {
    $existing_json = @file_get_contents($registry_path);
    if ($existing_json) {
        $decoded = json_decode($existing_json, true);
        if (is_array($decoded)) $existing_registry = $decoded;
    }
}

// ── Process POST ──────────────────────────────────────────────────────────────
$build_results = [];
$build_errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['package']) && $preflight_ok) {

    $selected = (array)($_POST['skins'] ?? []);
    if (empty($selected)) {
        $build_errors[] = 'No skins selected.';
    } else {

        // Ensure output dir exists
        $skins_out_dir = rtrim(RELEASES_DIR, '/') . '/skins';
        if (!is_dir($skins_out_dir)) {
            @mkdir($skins_out_dir, 0755, true);
        }
        if (!is_dir($skins_out_dir)) {
            $build_errors[] = 'Could not create output directory: ' . $skins_out_dir;
        }
    }

    if (empty($build_errors)) {

        $privkey = sodium_hex2bin(SMACK_RELEASE_PRIVKEY);

        foreach ($selected as $slug) {
            $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
            if (!isset($repo_skins[$slug])) {
                $build_results[] = ['slug' => $slug, 'ok' => false, 'msg' => 'Skin not found in repo.'];
                continue;
            }

            $meta        = $repo_skins[$slug];
            $skin_dir    = rtrim(SNAPSMACK_REPO_PATH, '/') . '/skins/' . $slug;
            $version     = $meta['version'];
            $zip_name    = $slug . '-' . $version . '.zip';
            $zip_path    = rtrim(RELEASES_DIR, '/') . '/skins/' . $zip_name;
            $download_url = rtrim(RELEASES_URL, '/') . '/skins/' . $zip_name;

            // Screenshot: use POSTed value, fall back to existing registry entry
            $screenshot = trim($_POST['screenshot'][$slug] ?? '');
            if ($screenshot === '') {
                $screenshot = $existing_registry['skins'][$slug]['screenshot'] ?? '';
            }

            // --- BUILD ZIP ---
            if (file_exists($zip_path)) @unlink($zip_path);

            $zip = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                $build_results[] = ['slug' => $slug, 'ok' => false, 'msg' => 'Failed to create zip file.'];
                continue;
            }

            // Add all files from the skin directory recursively
            $file_count = 0;
            $rit = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($skin_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($rit as $file) {
                if ($file->isFile()) {
                    $real_path    = $file->getRealPath();
                    $relative     = substr($real_path, strlen($skin_dir) + 1);
                    $relative     = str_replace('\\', '/', $relative);
                    $zip->addFile($real_path, $relative);
                    $file_count++;
                }
            }
            $zip->close();

            if ($file_count === 0) {
                @unlink($zip_path);
                $build_results[] = ['slug' => $slug, 'ok' => false, 'msg' => 'Skin directory is empty.'];
                continue;
            }

            // --- SIGN ---
            $zip_data    = file_get_contents($zip_path);
            $zip_size    = strlen($zip_data);
            $sig_bin     = sodium_crypto_sign_detached($zip_data, $privkey);
            $sig_hex     = sodium_bin2hex($sig_bin);
            unset($zip_data); // free memory

            // --- UPDATE REGISTRY ENTRY ---
            $existing_registry['skins'][$slug] = [
                'name'               => $meta['name'],
                'version'            => $version,
                'status'             => $meta['status'],
                'author'             => $meta['author'],
                'description'        => $meta['description'],
                'screenshot'         => $screenshot,
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

        // --- WRITE REGISTRY ---
        $existing_registry['generated'] = gmdate('Y-m-d\TH:i:s\Z');
        $registry_json = json_encode($existing_registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($registry_path, $registry_json) === false) {
            $build_errors[] = 'Packaged skins successfully, but could not write registry.json to: ' . $registry_path;
        }
    }
}

// ── HTML ──────────────────────────────────────────────────────────────────────
include __DIR__ . '/sc-layout-top.php';
?>

<div class="sc-page-head">
    <h1 class="sc-page-title">Skin Packager</h1>
    <p class="sc-dim">Package skins from the repo, sign them, and update the skin registry.</p>
</div>

<?php // ── Preflight warnings/errors ──────────────────────────────────────── ?>
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

<?php // ── Build results ─────────────────────────────────────────────────── ?>
<?php if (!empty($build_results) || !empty($build_errors)): ?>
    <div class="sc-card" style="margin-bottom:20px;">
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
                Registry URL: <a href="<?php echo htmlspecialchars(rtrim(RELEASES_URL,'/') . '/skins/registry.json'); ?>"
                    target="_blank"><?php echo htmlspecialchars(rtrim(RELEASES_URL,'/') . '/skins/registry.json'); ?></a>
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php // ── Main form ─────────────────────────────────────────────────────── ?>
<?php if ($preflight_ok && !empty($repo_skins)): ?>

<form method="POST" action="sc-skins.php">

    <div class="sc-card">
        <h2 class="sc-card-title">
            Available Skins
            <span class="sc-dim" style="font-weight:400; font-size:0.85rem;">
                — <?php echo count($repo_skins); ?> skins in repo
            </span>
        </h2>
        <p class="sc-dim" style="margin-bottom:20px; font-size:0.875rem;">
            Select skins to package. Each selected skin will be zipped, signed, and added
            (or updated) in the registry. Skins you don't select will keep their existing
            registry entries unchanged.
        </p>

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
                $in_registry   = isset($existing_registry['skins'][$slug]);
                $reg_version   = $existing_registry['skins'][$slug]['version'] ?? null;
                $reg_screenshot = $existing_registry['skins'][$slug]['screenshot'] ?? '';
                $needs_update  = $in_registry && $reg_version !== $meta['version'];
                $status_class  = match($meta['status']) {
                    'stable'      => 'sc-status--ok',
                    'beta'        => 'sc-status--warn',
                    'development' => 'sc-status--dim',
                    default       => '',
                };
            ?>
                <tr>
                    <td style="text-align:center;">
                        <input type="checkbox" name="skins[]" value="<?php echo htmlspecialchars($slug); ?>"
                               id="skin_<?php echo htmlspecialchars($slug); ?>"
                               <?php echo $in_registry ? 'checked' : ''; ?>>
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
                               placeholder="https://snapsmack.ca/skins/screenshots/<?php echo htmlspecialchars($slug); ?>.png"
                               style="width:100%; font-size:0.8rem; padding:4px 6px; background:#111; border:1px solid #333; color:#ccc;">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:20px; display:flex; align-items:center; gap:12px;">
            <button type="submit" name="package" value="1" class="sc-btn sc-btn--primary">
                Package Selected Skins
            </button>
            <span class="sc-dim" style="font-size:0.85rem;">
                Zips will be written to <code><?php echo htmlspecialchars(rtrim(RELEASES_DIR,'/') . '/skins/'); ?></code>
            </span>
        </div>
    </div>

</form>

<?php // ── Current registry contents ──────────────────────────────────────── ?>
<?php if (!empty($existing_registry['skins'])): ?>
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
                    <th>Slug</th>
                    <th>Name</th>
                    <th>Version</th>
                    <th>Status</th>
                    <th>Download</th>
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
                            <a href="<?php echo htmlspecialchars($entry['download_url']); ?>"
                               target="_blank" style="color:#aaa;">
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
            <a href="<?php echo htmlspecialchars(rtrim(RELEASES_URL,'/') . '/skins/registry.json'); ?>"
               target="_blank" class="sc-dim">
                View registry.json ↗
            </a>
        </p>
    </div>
<?php endif; ?>

<?php elseif ($preflight_ok): ?>
    <div class="sc-card">
        <p class="sc-dim">
            No skins found in <code><?php echo htmlspecialchars(rtrim(SNAPSMACK_REPO_PATH,'/') . '/skins/'); ?></code>.
            Make sure the repo path is correct and the skins directory exists.
        </p>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/sc-layout-bottom.php'; ?>
