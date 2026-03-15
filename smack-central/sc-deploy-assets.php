<?php
/**
 * SMACK CENTRAL - Asset Deploy Helper
 * Alpha v0.7.4
 *
 * One-shot script: downloads the SnapSmack repo zip from GitHub,
 * extracts all font families and ss-engine JS/CSS files into SC_ASSETS_DIR,
 * registers them in the DB, and regenerates asset-manifest.json.
 *
 * Upload to smack-central/, run it once, then DELETE IT.
 */

require_once __DIR__ . '/sc-auth.php';

@set_time_limit(300);

$sc_active_nav = '';
$sc_page_title = 'Asset Deploy';

// ── Helpers ────────────────────────────────────────────────────────────────

function sda_http_get(string $url): string|false {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SnapSmack-AssetDeploy/0.7.3',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? $body : false;
    }
    $ctx = stream_context_create(['http' => [
        'timeout'    => 120,
        'user_agent' => 'SnapSmack-AssetDeploy/0.7.3',
    ]]);
    return @file_get_contents($url, false, $ctx);
}

function sda_resolve_tag(): array {
    $fallback = 'v0.7.3';
    $ctx  = stream_context_create(['http' => [
        'timeout'    => 10,
        'user_agent' => 'SnapSmack-AssetDeploy/0.7.3',
        'header'     => 'Accept: application/vnd.github.v3+json',
    ]]);
    $body = @file_get_contents('https://api.github.com/repos/baddaywithacamera/snapsmack/tags?per_page=1', false, $ctx);
    $tag  = $fallback;
    if ($body !== false) {
        $data = json_decode($body, true);
        if (!empty($data[0]['name'])) $tag = $data[0]['name'];
    }
    $slug   = ltrim(preg_replace('/^v/i', '', $tag), '');
    $prefix = 'snapsmack-' . $slug . '/';
    $url    = 'https://github.com/baddaywithacamera/snapsmack/archive/refs/tags/' . urlencode($tag) . '.zip';
    return [$url, $prefix, $tag];
}

// ── Preflight ──────────────────────────────────────────────────────────────

$preflight_errors = [];
if (!defined('SC_ASSETS_DIR')) $preflight_errors[] = 'SC_ASSETS_DIR not defined in sc-config.php.';
if (!defined('SC_ASSETS_URL')) $preflight_errors[] = 'SC_ASSETS_URL not defined in sc-config.php.';
if (!class_exists('ZipArchive')) $preflight_errors[] = 'ZipArchive extension not available.';
if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) $preflight_errors[] = 'No HTTP fetch available (need curl or allow_url_fopen).';
if (defined('SC_ASSETS_DIR') && !is_dir(SC_ASSETS_DIR) && !@mkdir(SC_ASSETS_DIR, 0755, true)) {
    $preflight_errors[] = 'SC_ASSETS_DIR does not exist and could not be created: ' . SC_ASSETS_DIR;
}

// ── Process ────────────────────────────────────────────────────────────────

$steps = [];
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($preflight_errors)) {

    // Download zip
    [$zip_url, $prefix, $tag] = sda_resolve_tag();
    $steps[] = ['info', 'Tag: ' . $tag . ' — downloading…'];

    $zip_data = sda_http_get($zip_url);
    if ($zip_data === false) {
        $steps[] = ['fail', 'Could not download zip from GitHub. Check outbound HTTPS on this server.'];
        goto render;
    }

    $zip_tmp = sys_get_temp_dir() . '/sda_' . time() . '.zip';
    file_put_contents($zip_tmp, $zip_data);
    $steps[] = ['ok', 'Downloaded ' . number_format(strlen($zip_data) / 1024, 0) . ' KB'];

    $zip = new ZipArchive();
    if ($zip->open($zip_tmp) !== true) {
        $steps[] = ['fail', 'Could not open downloaded zip.'];
        @unlink($zip_tmp);
        goto render;
    }

    $font_prefix = $prefix . 'assets/fonts/';
    $js_prefix   = $prefix . 'assets/js/';
    $css_prefix  = $prefix . 'assets/css/';
    $fonts_n = $js_n = $css_n = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (str_ends_with($name, '/')) continue;

        // Fonts — all files inside assets/fonts/{family}/
        if (str_starts_with($name, $font_prefix)) {
            $rel = substr($name, strlen($font_prefix));
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (!in_array($ext, ['ttf', 'otf', 'woff', 'woff2'])) continue;
            $dest = rtrim(SC_ASSETS_DIR, '/') . '/fonts/' . $rel;
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
            file_put_contents($dest, $zip->getFromIndex($i));
            $fonts_n++;
            continue;
        }

        // Engine JS — ss-engine-*.js only
        if (str_starts_with($name, $js_prefix)) {
            $fname = basename($name);
            if (!str_starts_with($fname, 'ss-engine-') || !str_ends_with($fname, '.js')) continue;
            $dest = rtrim(SC_ASSETS_DIR, '/') . '/js/' . $fname;
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
            file_put_contents($dest, $zip->getFromIndex($i));
            $js_n++;
            continue;
        }

        // Engine CSS — ss-engine-*.css only
        if (str_starts_with($name, $css_prefix)) {
            $fname = basename($name);
            if (!str_starts_with($fname, 'ss-engine-') || !str_ends_with($fname, '.css')) continue;
            $dest = rtrim(SC_ASSETS_DIR, '/') . '/css/' . $fname;
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
            file_put_contents($dest, $zip->getFromIndex($i));
            $css_n++;
            continue;
        }
    }

    $zip->close();
    @unlink($zip_tmp);

    $steps[] = ['ok', "Fonts: {$fonts_n} file(s)"];
    $steps[] = ['ok', "Engine JS: {$js_n} file(s)"];
    $steps[] = ['ok', "Engine CSS: {$css_n} file(s)"];

    // Register in DB + regenerate manifest
    $imported = 0;
    try {
        sc_db()->exec("CREATE TABLE IF NOT EXISTS sc_assets (
            id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            asset_type   ENUM('font','script','css') NOT NULL,
            family       VARCHAR(100)    NOT NULL DEFAULT '',
            filename     VARCHAR(200)    NOT NULL,
            rel_path     VARCHAR(300)    NOT NULL,
            file_path    VARCHAR(500)    NOT NULL,
            download_url VARCHAR(500)    NOT NULL,
            file_size    INT UNSIGNED    NOT NULL DEFAULT 0,
            sha256       CHAR(64)        NOT NULL DEFAULT '',
            created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_rel_path (rel_path)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Fonts
        $font_base = rtrim(SC_ASSETS_DIR, '/') . '/fonts/';
        if (is_dir($font_base)) {
            foreach (scandir($font_base) as $fam) {
                if (str_starts_with($fam, '.')) continue;
                $fam_path = $font_base . $fam . '/';
                if (!is_dir($fam_path)) continue;
                foreach (scandir($fam_path) as $fname) {
                    if (str_starts_with($fname, '.')) continue;
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['ttf','otf','woff','woff2'])) continue;
                    $fpath = $fam_path . $fname;
                    $rel   = 'assets/fonts/' . $fam . '/' . $fname;
                    $url   = rtrim(SC_ASSETS_URL, '/') . '/fonts/' . rawurlencode($fam) . '/' . rawurlencode($fname);
                    sc_db()->prepare("INSERT INTO sc_assets (asset_type,family,filename,rel_path,file_path,download_url,file_size,sha256)
                        VALUES ('font',?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE file_path=VALUES(file_path),download_url=VALUES(download_url),
                            file_size=VALUES(file_size),sha256=VALUES(sha256)")
                        ->execute([$fam,$fname,$rel,$fpath,$url,filesize($fpath),hash_file('sha256',$fpath)]);
                    $imported++;
                }
            }
        }

        // JS + CSS
        foreach (['js' => 'script', 'css' => 'css'] as $sub => $type) {
            $dir = rtrim(SC_ASSETS_DIR, '/') . '/' . $sub . '/';
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) as $fname) {
                if (str_starts_with($fname, '.') || !is_file($dir . $fname)) continue;
                $fpath = $dir . $fname;
                $rel   = 'assets/' . $sub . '/' . $fname;
                $url   = rtrim(SC_ASSETS_URL, '/') . '/' . $sub . '/' . rawurlencode($fname);
                sc_db()->prepare("INSERT INTO sc_assets (asset_type,family,filename,rel_path,file_path,download_url,file_size,sha256)
                    VALUES (?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE file_path=VALUES(file_path),download_url=VALUES(download_url),
                        file_size=VALUES(file_size),sha256=VALUES(sha256)")
                    ->execute([$type,'',$fname,$rel,$fpath,$url,filesize($fpath),hash_file('sha256',$fpath)]);
                $imported++;
            }
        }

        $steps[] = ['ok', "{$imported} asset(s) registered in DB"];

        // Manifest
        if (defined('RELEASES_DIR')) {
            $rows   = sc_db()->query("SELECT rel_path,download_url,file_size,sha256 FROM sc_assets ORDER BY asset_type,rel_path")->fetchAll(PDO::FETCH_ASSOC);
            $assets = [];
            foreach ($rows as $r) {
                $assets[$r['rel_path']] = ['url'=>$r['download_url'],'size'=>(int)$r['file_size'],'sha256'=>$r['sha256']];
            }
            $dest = rtrim(RELEASES_DIR, '/') . '/asset-manifest.json';
            if (file_put_contents($dest, json_encode(['generated'=>date('c'),'assets'=>$assets], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) !== false) {
                $steps[] = ['ok', 'asset-manifest.json written'];
            } else {
                $steps[] = ['warn', 'Could not write asset-manifest.json — check RELEASES_DIR permissions.'];
            }
        }

        $done = true;

    } catch (Exception $e) {
        $steps[] = ['fail', 'DB error: ' . $e->getMessage()];
    }
}

// Self-delete
if (isset($_POST['self_delete']) && $done) {
    @unlink(__FILE__);
}

render:
require __DIR__ . '/sc-layout-top.php';
?>

<?php foreach ($preflight_errors as $e): ?>
<div class="sc-alert sc-alert--error"><?php echo htmlspecialchars($e); ?></div>
<?php endforeach; ?>

<?php if ($done): ?>

<div class="sc-alert sc-alert--success">Done. Delete this file from the server.</div>
<ul class="sc-step-log">
<?php foreach ($steps as [$s, $msg]): ?>
  <li class="s-<?php echo $s; ?>"><?php echo match($s){'ok'=>'✓','warn'=>'▲','fail'=>'✗',default=>'·'}; ?> <?php echo htmlspecialchars($msg); ?></li>
<?php endforeach; ?>
</ul>
<div style="margin-top:24px;display:flex;gap:12px;align-items:center">
  <a href="sc-assets.php" class="sc-btn">Go to Asset Repository →</a>
  <form method="post" onsubmit="return confirm('Delete sc-deploy-assets.php from the server now?')">
    <input type="hidden" name="self_delete" value="1">
    <button class="sc-btn sc-btn--danger" type="submit">Delete This File</button>
  </form>
</div>

<?php else: ?>

<div class="sc-box" style="margin-bottom:24px">
  <div class="sc-box-head">What this does</div>
  <div class="sc-box-body sc-pad" style="font-size:0.8rem;line-height:1.8;color:var(--sc-text-dim)">
    Downloads the latest SnapSmack release zip from GitHub and extracts into
    <code style="color:var(--sc-accent)"><?php echo defined('SC_ASSETS_DIR') ? htmlspecialchars(SC_ASSETS_DIR) : 'SC_ASSETS_DIR'; ?></code>:<br>
    &nbsp;· All font families → <code>fonts/</code><br>
    &nbsp;· All <code>ss-engine-*.js</code> → <code>js/</code><br>
    &nbsp;· All <code>ss-engine-*.css</code> → <code>css/</code><br><br>
    Then registers everything in the DB and writes <code>asset-manifest.json</code>. Run once, then delete.
  </div>
</div>

<?php if (!empty($steps)): ?>
<ul class="sc-step-log" style="margin-bottom:16px">
<?php foreach ($steps as [$s, $msg]): ?>
  <li class="s-<?php echo $s; ?>"><?php echo match($s){'ok'=>'✓','warn'=>'▲','fail'=>'✗',default=>'·'}; ?> <?php echo htmlspecialchars($msg); ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if (empty($preflight_errors)): ?>
<form method="post">
  <button class="sc-btn" type="submit">Deploy Assets from GitHub →</button>
</form>
<?php endif; ?>

<?php endif; ?>

<style>
.sc-step-log { list-style:none; margin:12px 0; }
.sc-step-log li { padding:3px 0; font-size:0.78rem; font-family:var(--sc-font-mono); }
.s-ok { color:var(--sc-accent); } .s-warn { color:var(--sc-warn); }
.s-fail { color:var(--sc-danger); } .s-info { color:var(--sc-text-dim); }
</style>

<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
