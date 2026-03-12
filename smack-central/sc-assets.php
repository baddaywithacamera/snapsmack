<?php
/**
 * SMACK CENTRAL - Asset Repository
 * Alpha v0.7.3
 *
 * Hosts the font families and JS engine files that SnapSmack installs pull
 * from on demand via core/asset-sync.php. Manages file uploads, generates
 * the signed asset-manifest.json that installs use to discover and verify
 * remote assets, and keeps the sc_assets DB table in sync with what is
 * actually on disk.
 *
 * Assets are served statically from SC_ASSETS_DIR/SC_ASSETS_URL; this panel
 * only handles management (upload, delete, manifest regeneration).
 */

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-assets.php';
$sc_page_title = 'Asset Repository';

// ── Preflight ──────────────────────────────────────────────────────────────

$preflight = [];
if (!defined('SC_ASSETS_DIR') || !defined('SC_ASSETS_URL')) {
    $preflight[] = ['err', 'SC_ASSETS_DIR and SC_ASSETS_URL are not defined. Add them to sc-config.php.'];
}
if (!defined('RELEASES_DIR')) {
    $preflight[] = ['err', 'RELEASES_DIR is not defined. Check sc-config.php.'];
}
$preflight_ok = !defined('SC_ASSETS_DIR') ? false : is_dir(SC_ASSETS_DIR);
if (defined('SC_ASSETS_DIR') && !is_dir(SC_ASSETS_DIR)) {
    $preflight[] = ['warn', 'SC_ASSETS_DIR does not exist: ' . SC_ASSETS_DIR . '. Create it and make it web-accessible.'];
}

// ── Ensure DB table ────────────────────────────────────────────────────────

try {
    sc_db()->exec("
        CREATE TABLE IF NOT EXISTS sc_assets (
            id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            asset_type   ENUM('font','script','css') NOT NULL,
            family       VARCHAR(100)    NOT NULL DEFAULT '' COMMENT 'Font family folder name; empty for scripts',
            filename     VARCHAR(200)    NOT NULL,
            rel_path     VARCHAR(300)    NOT NULL COMMENT 'Path relative to CMS root e.g. assets/fonts/Foo/foo.ttf',
            file_path    VARCHAR(500)    NOT NULL COMMENT 'Absolute path on this server',
            download_url VARCHAR(500)    NOT NULL,
            file_size    INT UNSIGNED    NOT NULL DEFAULT 0,
            sha256       CHAR(64)        NOT NULL DEFAULT '',
            created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_rel_path (rel_path)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
    $preflight[] = ['err', 'DB error: ' . $e->getMessage()];
}

// ── Helper: regenerate asset-manifest.json from DB ─────────────────────────

function sc_assets_write_manifest(): bool {
    try {
        $rows  = sc_db()->query("SELECT rel_path, download_url, file_size, sha256 FROM sc_assets ORDER BY asset_type, rel_path")->fetchAll(PDO::FETCH_ASSOC);
        $assets = [];
        foreach ($rows as $r) {
            $assets[$r['rel_path']] = [
                'url'    => $r['download_url'],
                'size'   => (int)$r['file_size'],
                'sha256' => $r['sha256'],
            ];
        }
        $manifest = [
            'generated' => date('c'),
            'assets'    => $assets,
        ];
        $dest = rtrim(RELEASES_DIR, '/') . '/asset-manifest.json';
        return file_put_contents($dest, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    } catch (Exception $e) {
        return false;
    }
}

// ── Helper: build the expected rel_path from asset type + family + filename ─

function sc_asset_rel_path(string $type, string $family, string $filename): string {
    return match($type) {
        'font'   => 'assets/fonts/' . $family . '/' . $filename,
        'script' => 'assets/js/' . $filename,
        'css'    => 'assets/css/' . $filename,
        default  => ''
    };
}

// ── Action handlers ────────────────────────────────────────────────────────

$flash      = '';
$flash_type = '';
$action     = $_POST['action'] ?? '';

// ── Upload font family (ZIP) ───────────────────────────────────────────────
if ($action === 'upload_font' && $preflight_ok) {
    if (empty($_FILES['font_zip']['tmp_name'])) {
        $flash = 'No file uploaded.'; $flash_type = 'error';
    } elseif (!class_exists('ZipArchive')) {
        $flash = 'ZipArchive not available.'; $flash_type = 'error';
    } else {
        $family = trim($_POST['family_name'] ?? '');
        if ($family === '') {
            // Derive family name from zip filename (strip extension)
            $family = pathinfo($_FILES['font_zip']['name'], PATHINFO_FILENAME);
        }
        // Sanitise: only letters, digits, spaces, hyphens, underscores, dots
        $family = preg_replace('/[^a-zA-Z0-9 ._\-]/', '', $family);

        if ($family === '') {
            $flash = 'Invalid family name.'; $flash_type = 'error';
        } else {
            $dest_dir = rtrim(SC_ASSETS_DIR, '/') . '/fonts/' . $family . '/';
            if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);

            $zip = new ZipArchive();
            if ($zip->open($_FILES['font_zip']['tmp_name']) !== true) {
                $flash = 'Could not open zip.'; $flash_type = 'error';
            } else {
                $installed = 0;
                $skipped   = 0;
                $wrapper   = ''; // detect common top-level folder in zip
                if ($zip->numFiles > 0) {
                    $first = $zip->getNameIndex(0);
                    $sl = strpos($first, '/');
                    if ($sl !== false) {
                        $candidate = substr($first, 0, $sl + 1);
                        $all_match = true;
                        for ($i = 1; $i < $zip->numFiles; $i++) {
                            if (!str_starts_with($zip->getNameIndex($i), $candidate)) { $all_match = false; break; }
                        }
                        if ($all_match) $wrapper = $candidate;
                    }
                }

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    $rel   = $wrapper ? substr($entry, strlen($wrapper)) : $entry;
                    if ($rel === '' || str_ends_with($rel, '/')) continue;

                    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['ttf', 'otf', 'woff', 'woff2'])) { $skipped++; continue; }

                    $filename = basename($rel);
                    $content  = $zip->getFromIndex($i);
                    if ($content === false) { $skipped++; continue; }

                    $dest_file = $dest_dir . $filename;
                    file_put_contents($dest_file, $content);

                    $sha256      = hash_file('sha256', $dest_file);
                    $size        = filesize($dest_file);
                    $rel_path    = sc_asset_rel_path('font', $family, $filename);
                    $download_url = rtrim(SC_ASSETS_URL, '/') . '/fonts/' . rawurlencode($family) . '/' . rawurlencode($filename);

                    try {
                        sc_db()->prepare("
                            INSERT INTO sc_assets (asset_type, family, filename, rel_path, file_path, download_url, file_size, sha256)
                            VALUES ('font', ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE file_path=VALUES(file_path), download_url=VALUES(download_url),
                                                    file_size=VALUES(file_size), sha256=VALUES(sha256)
                        ")->execute([$family, $filename, $rel_path, $dest_file, $download_url, $size, $sha256]);
                        $installed++;
                    } catch (Exception $e) {
                        $skipped++;
                    }
                }
                $zip->close();

                sc_assets_write_manifest();
                $flash = "Font family "{$family}" — {$installed} file(s) installed" . ($skipped ? ", {$skipped} skipped." : '.');
                $flash_type = 'success';
            }
        }
    }
}

// ── Upload script (JS + optional CSS) ─────────────────────────────────────
if ($action === 'upload_script' && $preflight_ok) {
    $uploaded = 0;
    foreach (['script_js' => ['js'], 'script_css' => ['css']] as $field => $allowed_exts) {
        if (empty($_FILES[$field]['tmp_name'])) continue;

        $orig_name = $_FILES[$field]['name'];
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts)) {
            $flash = "Invalid file type for {$orig_name}."; $flash_type = 'error'; continue;
        }

        // Sanitise filename: only alphanumerics, hyphens, underscores, dots
        $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '', basename($orig_name));
        if ($filename === '') { $flash = 'Invalid filename.'; $flash_type = 'error'; continue; }

        $asset_type  = ($ext === 'css') ? 'css' : 'script';
        $sub_dir     = ($ext === 'css') ? 'css' : 'js';
        $dest_dir    = rtrim(SC_ASSETS_DIR, '/') . '/' . $sub_dir . '/';
        if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);

        $dest_file    = $dest_dir . $filename;
        $content      = file_get_contents($_FILES[$field]['tmp_name']);
        file_put_contents($dest_file, $content);

        $sha256       = hash_file('sha256', $dest_file);
        $size         = filesize($dest_file);
        $rel_path     = sc_asset_rel_path($asset_type, '', $filename);
        $download_url = rtrim(SC_ASSETS_URL, '/') . '/' . $sub_dir . '/' . rawurlencode($filename);

        try {
            sc_db()->prepare("
                INSERT INTO sc_assets (asset_type, family, filename, rel_path, file_path, download_url, file_size, sha256)
                VALUES (?, '', ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE file_path=VALUES(file_path), download_url=VALUES(download_url),
                                        file_size=VALUES(file_size), sha256=VALUES(sha256)
            ")->execute([$asset_type, $filename, $rel_path, $dest_file, $download_url, $size, $sha256]);
            $uploaded++;
        } catch (Exception $e) {
            $flash = 'DB error: ' . $e->getMessage(); $flash_type = 'error';
        }
    }
    if ($uploaded > 0) {
        sc_assets_write_manifest();
        $flash = "{$uploaded} script file(s) uploaded and manifest updated.";
        $flash_type = 'success';
    }
}

// ── Delete asset ───────────────────────────────────────────────────────────
if ($action === 'delete_asset' && $preflight_ok) {
    $del_id = (int)($_POST['asset_id'] ?? 0);
    if ($del_id > 0) {
        $row = sc_db()->prepare("SELECT * FROM sc_assets WHERE id = ?")->execute([$del_id]) ? sc_db()->query("SELECT * FROM sc_assets WHERE id = {$del_id}")->fetch() : null;
        // Re-query cleanly
        $stmt = sc_db()->prepare("SELECT * FROM sc_assets WHERE id = ?");
        $stmt->execute([$del_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            @unlink($row['file_path']);
            sc_db()->prepare("DELETE FROM sc_assets WHERE id = ?")->execute([$del_id]);
            sc_assets_write_manifest();
            $flash = "Deleted: {$row['filename']}"; $flash_type = 'success';
        }
    }
}

// ── Regenerate manifest manually ──────────────────────────────────────────
if ($action === 'regen_manifest') {
    $ok = sc_assets_write_manifest();
    $flash = $ok ? 'asset-manifest.json regenerated.' : 'Failed to write manifest — check RELEASES_DIR permissions.';
    $flash_type = $ok ? 'success' : 'error';
}

// ── Scan disk and import any unregistered files ────────────────────────────
// (recovery tool — re-registers files present on disk but missing from DB)
if ($action === 'rescan_disk' && $preflight_ok) {
    $imported = 0;

    // Fonts
    $font_base = rtrim(SC_ASSETS_DIR, '/') . '/fonts/';
    if (is_dir($font_base)) {
        foreach (scandir($font_base) as $family_dir) {
            if (str_starts_with($family_dir, '.')) continue;
            $family_path = $font_base . $family_dir . '/';
            if (!is_dir($family_path)) continue;
            foreach (scandir($family_path) as $fname) {
                if (str_starts_with($fname, '.')) continue;
                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                if (!in_array($ext, ['ttf', 'otf', 'woff', 'woff2'])) continue;
                $fpath    = $family_path . $fname;
                $rel_path = 'assets/fonts/' . $family_dir . '/' . $fname;
                $url      = rtrim(SC_ASSETS_URL, '/') . '/fonts/' . rawurlencode($family_dir) . '/' . rawurlencode($fname);
                $sha256   = hash_file('sha256', $fpath);
                $size     = filesize($fpath);
                try {
                    sc_db()->prepare("
                        INSERT IGNORE INTO sc_assets (asset_type, family, filename, rel_path, file_path, download_url, file_size, sha256)
                        VALUES ('font', ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$family_dir, $fname, $rel_path, $fpath, $url, $size, $sha256]);
                    $imported++;
                } catch (Exception $e) {}
            }
        }
    }

    // Scripts
    foreach (['js' => 'script', 'css' => 'css'] as $sub => $type) {
        $dir = rtrim(SC_ASSETS_DIR, '/') . '/' . $sub . '/';
        if (!is_dir($dir)) continue;
        foreach (scandir($dir) as $fname) {
            if (str_starts_with($fname, '.')) continue;
            $fpath    = $dir . $fname;
            if (!is_file($fpath)) continue;
            $rel_path = 'assets/' . $sub . '/' . $fname;
            $url      = rtrim(SC_ASSETS_URL, '/') . '/' . $sub . '/' . rawurlencode($fname);
            $sha256   = hash_file('sha256', $fpath);
            $size     = filesize($fpath);
            try {
                sc_db()->prepare("
                    INSERT IGNORE INTO sc_assets (asset_type, family, filename, rel_path, file_path, download_url, file_size, sha256)
                    VALUES (?, '', ?, ?, ?, ?, ?, ?)
                ")->execute([$type, $fname, $rel_path, $fpath, $url, $size, $sha256]);
                $imported++;
            } catch (Exception $e) {}
        }
    }

    sc_assets_write_manifest();
    $flash = "Disk rescan complete — {$imported} file(s) imported.";
    $flash_type = 'success';
}

// ── Fetch current asset records ────────────────────────────────────────────
$fonts   = [];
$scripts = [];
try {
    $rows = sc_db()->query("SELECT * FROM sc_assets ORDER BY asset_type, family, filename")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($r['asset_type'] === 'font')  $fonts[$r['family']][] = $r;
        else                              $scripts[]              = $r;
    }
} catch (Exception $e) {}

// Manifest file info
$manifest_path  = defined('RELEASES_DIR') ? rtrim(RELEASES_DIR, '/') . '/asset-manifest.json' : '';
$manifest_mtime = ($manifest_path && file_exists($manifest_path)) ? date('Y-m-d H:i:s', filemtime($manifest_path)) : 'not generated yet';
$manifest_url   = defined('RELEASES_URL') ? rtrim(RELEASES_URL, '/') . '/asset-manifest.json' : '';

require __DIR__ . '/sc-layout-top.php';
?>

<?php if (!empty($preflight)): ?>
<div style="margin-bottom:20px">
    <?php foreach ($preflight as [$lvl, $msg]): ?>
    <div class="sc-alert sc-alert--<?php echo $lvl === 'err' ? 'error' : 'warn'; ?>"><?php echo htmlspecialchars($msg); ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($flash): ?>
<div class="sc-alert sc-alert--<?php echo $flash_type === 'success' ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($flash); ?></div>
<?php endif; ?>

<!-- ── Manifest status bar ──────────────────────────────────────────────── -->
<div class="sc-box" style="margin-bottom:24px">
  <div class="sc-box-head">
    <span>Manifest Status</span>
    <form method="post" style="display:inline">
      <input type="hidden" name="action" value="regen_manifest">
      <button class="sc-btn sc-btn--sm" type="submit">Regenerate</button>
    </form>
    <form method="post" style="display:inline; margin-left:8px">
      <input type="hidden" name="action" value="rescan_disk">
      <button class="sc-btn sc-btn--sm" type="submit">Rescan Disk</button>
    </form>
    <?php if ($manifest_url): ?>
    <a href="<?php echo htmlspecialchars($manifest_url); ?>" target="_blank" class="sc-btn sc-btn--sm" style="margin-left:8px">View JSON</a>
    <?php endif; ?>
  </div>
  <div class="sc-box-body sc-pad">
    <span class="sc-dim">Last generated: </span><?php echo htmlspecialchars($manifest_mtime); ?>
    &nbsp;&nbsp;<span class="sc-dim">Total assets:</span> <?php echo count($fonts) + count($scripts); ?> families / files
  </div>
</div>

<!-- ── Tab bar ──────────────────────────────────────────────────────────── -->
<div class="sc-tab-bar" style="margin-bottom:24px">
  <button class="sc-tab sc-tab--active" onclick="scTab(this,'tab-fonts')">Fonts (<?php echo count($fonts); ?> families)</button>
  <button class="sc-tab" onclick="scTab(this,'tab-scripts')">Scripts (<?php echo count($scripts); ?> files)</button>
  <button class="sc-tab" onclick="scTab(this,'tab-upload')">Upload</button>
</div>

<!-- ═══════════════════════════════════════════════════════ FONTS TAB ═══ -->
<div id="tab-fonts">
<?php if (empty($fonts)): ?>
<p class="sc-dim">No fonts uploaded yet. Use the Upload tab to add font families.</p>
<?php else: foreach ($fonts as $family => $files): ?>
<div class="sc-box" style="margin-bottom:16px">
  <div class="sc-box-head"><?php echo htmlspecialchars($family); ?> <span class="sc-dim" style="font-weight:400">(<?php echo count($files); ?> file<?php echo count($files) !== 1 ? 's' : ''; ?>)</span></div>
  <div class="sc-box-body sc-box-body--flush">
    <table class="sc-table" style="width:100%">
      <thead><tr>
        <th>Filename</th>
        <th>Size</th>
        <th>SHA-256</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($files as $f): ?>
      <tr>
        <td><?php echo htmlspecialchars($f['filename']); ?></td>
        <td class="sc-dim"><?php echo number_format($f['file_size'] / 1024, 1); ?> KB</td>
        <td><code style="font-size:0.72rem"><?php echo substr($f['sha256'], 0, 16); ?>…</code></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete <?php echo htmlspecialchars(addslashes($f['filename'])); ?>?')">
            <input type="hidden" name="action"   value="delete_asset">
            <input type="hidden" name="asset_id" value="<?php echo $f['id']; ?>">
            <button class="sc-btn sc-btn--sm sc-btn--danger" type="submit">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- ═════════════════════════════════════════════════════ SCRIPTS TAB ═══ -->
<div id="tab-scripts" style="display:none">
<?php if (empty($scripts)): ?>
<p class="sc-dim">No scripts uploaded yet. Use the Upload tab to add JS engine files.</p>
<?php else: ?>
<div class="sc-box">
  <div class="sc-box-body sc-box-body--flush">
    <table class="sc-table" style="width:100%">
      <thead><tr>
        <th>Filename</th>
        <th>Type</th>
        <th>Size</th>
        <th>SHA-256</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($scripts as $f): ?>
      <tr>
        <td><?php echo htmlspecialchars($f['filename']); ?></td>
        <td><span class="sc-badge sc-badge--<?php echo $f['asset_type'] === 'script' ? 'ok' : 'warn'; ?>"><?php echo htmlspecialchars($f['asset_type']); ?></span></td>
        <td class="sc-dim"><?php echo number_format($f['file_size'] / 1024, 1); ?> KB</td>
        <td><code style="font-size:0.72rem"><?php echo substr($f['sha256'], 0, 16); ?>…</code></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete <?php echo htmlspecialchars(addslashes($f['filename'])); ?>?')">
            <input type="hidden" name="action"   value="delete_asset">
            <input type="hidden" name="asset_id" value="<?php echo $f['id']; ?>">
            <button class="sc-btn sc-btn--sm sc-btn--danger" type="submit">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════ UPLOAD TAB ═══ -->
<div id="tab-upload" style="display:none">

  <!-- Font family upload -->
  <div class="sc-box" style="margin-bottom:24px">
    <div class="sc-box-head">Upload Font Family</div>
    <div class="sc-box-body sc-pad">
      <p class="sc-dim" style="margin-top:0">Upload a ZIP of a font family. All TTF / OTF / WOFF / WOFF2 files found inside will be extracted and registered. The family name defaults to the zip filename — override below if needed.</p>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_font">
        <div class="sc-field">
          <label>Font Family ZIP</label>
          <input type="file" name="font_zip" accept=".zip" required>
        </div>
        <div class="sc-field">
          <label>Family Name <span class="sc-dim">(optional — overrides zip filename)</span></label>
          <input type="text" name="family_name" placeholder="e.g. BlackCasper">
          <span class="sc-hint">Must match the folder name used in manifest-inventory.php exactly, including capitalisation and spaces.</span>
        </div>
        <button class="sc-btn" type="submit">Upload Font Family</button>
      </form>
    </div>
  </div>

  <!-- Script upload -->
  <div class="sc-box">
    <div class="sc-box-head">Upload JS Engine / CSS</div>
    <div class="sc-box-body sc-pad">
      <p class="sc-dim" style="margin-top:0">Upload a JS engine file and its optional companion CSS. Filenames must match exactly what manifest-inventory.php declares in the <code>path</code> and <code>css</code> fields.</p>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_script">
        <div class="sc-field">
          <label>JS Engine File <span class="sc-dim">(.js)</span></label>
          <input type="file" name="script_js" accept=".js">
        </div>
        <div class="sc-field">
          <label>Companion CSS <span class="sc-dim">(.css — optional)</span></label>
          <input type="file" name="script_css" accept=".css">
        </div>
        <button class="sc-btn" type="submit">Upload Script</button>
      </form>
    </div>
  </div>

</div>

<script>
function scTab(btn, id) {
    document.querySelectorAll('.sc-tab').forEach(b => b.classList.remove('sc-tab--active'));
    ['tab-fonts','tab-scripts','tab-upload'].forEach(t => {
        var el = document.getElementById(t);
        if (el) el.style.display = (t === id) ? '' : 'none';
    });
    btn.classList.add('sc-tab--active');
}
</script>

<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
