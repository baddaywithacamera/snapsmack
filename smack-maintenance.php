<?php
/**
 * SNAPSMACK - System maintenance
 * Alpha v0.7.3a
 *
 * Performs database optimizations, taxonomy cleanup, and asset synchronization.
 * Clears orphaned mappings and defragments core tables to maintain performance.
 */

require_once 'core/auth.php';

$log = [];

// --- ACTION HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    // REGISTRY SYNC
    // Removes ghost entries in the mapping table for images that have been deleted.
    if ($action === 'sync_cats') {
        $stmt = $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id NOT IN (SELECT id FROM snap_images)");
        $stmt->execute();
        $deleted = $stmt->rowCount();
        $log[] = "SUCCESS: Purged $deleted orphaned category mappings.";
    }

    // DB OPTIMIZATION
    // Forces MySQL to defragment and optimize core operational tables.
    if ($action === 'optimize') {
        $pdo->query("OPTIMIZE TABLE snap_images, snap_categories, snap_image_cat_map");
        $log[] = "SUCCESS: Database tables optimized and defragmented.";
    }

    // ASSET SYNC
    // Regenerates missing thumbnails and deletes physical files not found in the DB.
    // Batched at 25 images per run to avoid flattening shared hosting.
    if ($action === 'sync_assets') {
        set_time_limit(120);
        ini_set('memory_limit', '256M');

        $batch_size = 25;
        $offset = max(0, (int)($_POST['batch_offset'] ?? 0));

        $total_images = (int)$pdo->query("SELECT COUNT(*) FROM snap_images")->fetchColumn();
        $images = $pdo->prepare("SELECT id, img_title, img_file FROM snap_images ORDER BY id LIMIT ? OFFSET ?");
        $images->bindValue(1, $batch_size, PDO::PARAM_INT);
        $images->bindValue(2, $offset, PDO::PARAM_INT);
        $images->execute();
        $batch = $images->fetchAll(PDO::FETCH_ASSOC);

        $registered_paths = [];
        $fixed_square = 0;
        $fixed_aspect = 0;
        $db_backfilled = 0;

        // Prepared statement for backfilling recovery metadata
        $backfill_stmt = $pdo->prepare("
            UPDATE snap_images
            SET img_thumb_square = ?, img_thumb_aspect = ?, img_checksum = ?
            WHERE id = ?
        ");

        foreach ($batch as $img) {
            $file = $img['img_file'];
            if (!file_exists($file)) continue;

            $registered_paths[] = realpath($file);
            $path_info = pathinfo($file);
            $thumb_dir = $path_info['dirname'] . '/thumbs';

            // Ensure thumbs directory exists
            if (!is_dir($thumb_dir)) {
                mkdir($thumb_dir, 0755, true);
            }

            // Expected thumbnail locations
            $sq_thumb = $thumb_dir . '/t_' . $path_info['basename'];
            $aspect_thumb = $thumb_dir . '/a_' . $path_info['basename'];

            // Register existing thumbs as valid
            if (file_exists($sq_thumb)) $registered_paths[] = realpath($sq_thumb);
            if (file_exists($aspect_thumb)) $registered_paths[] = realpath($aspect_thumb);

            // Rebuild missing thumbnails
            $need_square = !file_exists($sq_thumb);
            $need_aspect = !file_exists($aspect_thumb);

            if ($need_square || $need_aspect) {
                list($orig_w, $orig_h) = getimagesize($file);
                $mime = mime_content_type($file);
                $src = null;

                if ($mime == 'image/jpeg') { $src = @imagecreatefromjpeg($file); }
                elseif ($mime == 'image/png') { $src = @imagecreatefrompng($file); }
                elseif ($mime == 'image/webp') { $src = @imagecreatefromwebp($file); }

                if ($src) {
                    // --- SQUARE THUMB (t_) — 400x400 center-cropped ---
                    if ($need_square) {
                        $sq_size = 400;
                        $min_dim = min($orig_w, $orig_h);
                        $off_x = ($orig_w - $min_dim) / 2;
                        $off_y = ($orig_h - $min_dim) / 2;

                        $sq_dst = imagecreatetruecolor($sq_size, $sq_size);
                        if ($mime != 'image/jpeg') { imagealphablending($sq_dst, false); imagesavealpha($sq_dst, true); }
                        imagecopyresampled($sq_dst, $src, 0, 0, $off_x, $off_y, $sq_size, $sq_size, $min_dim, $min_dim);

                        if ($mime == 'image/png') imagepng($sq_dst, $sq_thumb, 8);
                        elseif ($mime == 'image/webp') imagewebp($sq_dst, $sq_thumb, 78);
                        else imagejpeg($sq_dst, $sq_thumb, 82);

                        imagedestroy($sq_dst);
                        $registered_paths[] = realpath($sq_thumb);
                        $fixed_square++;
                    }

                    // --- ASPECT THUMB (a_) — 400px on the long side ---
                    if ($need_aspect) {
                        $aspect_long = 400;

                        if ($orig_w >= $orig_h) {
                            $a_w = $aspect_long;
                            $a_h = round($orig_h * ($aspect_long / $orig_w));
                        } else {
                            $a_h = $aspect_long;
                            $a_w = round($orig_w * ($aspect_long / $orig_h));
                        }

                        // Don't upscale tiny images
                        if ($orig_w < $aspect_long && $orig_h < $aspect_long) {
                            $a_w = $orig_w;
                            $a_h = $orig_h;
                        }

                        $a_dst = imagecreatetruecolor($a_w, $a_h);
                        if ($mime != 'image/jpeg') { imagealphablending($a_dst, false); imagesavealpha($a_dst, true); }
                        imagecopyresampled($a_dst, $src, 0, 0, 0, 0, $a_w, $a_h, $orig_w, $orig_h);

                        if ($mime == 'image/png') imagepng($a_dst, $aspect_thumb, 8);
                        elseif ($mime == 'image/webp') imagewebp($a_dst, $aspect_thumb, 78);
                        else imagejpeg($a_dst, $aspect_thumb, 82);

                        imagedestroy($a_dst);
                        $registered_paths[] = realpath($aspect_thumb);
                        $fixed_aspect++;
                    }

                    imagedestroy($src);
                }
            }

            // --- BACKFILL RECOVERY METADATA ---
            // Always ensure DB has thumb paths and checksum, even if thumbs already existed.
            $rel_sq  = ltrim(str_replace('\\', '/', $sq_thumb), './');
            $rel_asp = ltrim(str_replace('\\', '/', $aspect_thumb), './');
            $checksum = hash_file('sha256', $file);

            $backfill_stmt->execute([$rel_sq, $rel_asp, $checksum, $img['id']]);
            $db_backfilled++;
        }

        $next_offset = $offset + $batch_size;
        $has_more = $next_offset < $total_images;
        $batch_end = min($next_offset, $total_images);

        // Only prune orphans on the final batch (needs full registered_paths from all images)
        $purged_orphans = 0;
        if (!$has_more && is_dir('img_uploads')) {
            // Build full registered path list for orphan scan
            $all_images = $pdo->query("SELECT img_file FROM snap_images")->fetchAll(PDO::FETCH_COLUMN);
            $all_registered = [];
            foreach ($all_images as $afile) {
                if (!file_exists($afile)) continue;
                $all_registered[] = realpath($afile);
                $api = pathinfo($afile);
                $atd = $api['dirname'] . '/thumbs';
                $asq = $atd . '/t_' . $api['basename'];
                $aas = $atd . '/a_' . $api['basename'];
                if (file_exists($asq)) $all_registered[] = realpath($asq);
                if (file_exists($aas)) $all_registered[] = realpath($aas);
            }

            $upload_dir = new RecursiveDirectoryIterator('img_uploads');
            $iterator = new RecursiveIteratorIterator($upload_dir);
            foreach ($iterator as $file_info) {
                if ($file_info->isFile()) {
                    $f_path = $file_info->getRealPath();
                    $ext = strtolower($file_info->getExtension());
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) && !in_array($f_path, $all_registered)) {
                        unlink($f_path);
                        $purged_orphans++;
                    }
                }
            }
        }

        $msg = "BATCH {$offset}–{$batch_end} of {$total_images}: Generated {$fixed_square} square + {$fixed_aspect} aspect thumbs. Backfilled {$db_backfilled} DB recovery records.";
        if (!$has_more) {
            $msg .= " Purged {$purged_orphans} orphan files. <strong>ALL DONE.</strong>";
        } else {
            $msg .= " <strong>" . ($total_images - $batch_end) . " images remaining.</strong>";
        }
        $log[] = "SUCCESS: " . $msg;

        // Flag for the UI to show the continue button
        $asset_sync_has_more = $has_more;
        $asset_sync_next_offset = $next_offset;
    }

    // HTACCESS REPAIR
    // Checks root .htaccess and img_uploads/.htaccess, reports issues, repairs on demand.
    if ($action === 'htaccess_check' || $action === 'htaccess_repair') {
        $htaccess_path   = __DIR__ . '/.htaccess';
        $uploads_htaccess = __DIR__ . '/img_uploads/.htaccess';
        $htaccess_marker = '# SNAPSMACK-HTACCESS-RULES';
        $issues = [];

        // --- Diagnose root .htaccess ---
        if (!file_exists($htaccess_path)) {
            $issues[] = "Root .htaccess is <strong>missing entirely</strong>.";
        } else {
            $content = file_get_contents($htaccess_path);

            if (strpos($content, $htaccess_marker) === false) {
                $issues[] = "SnapSmack rules block is <strong>missing</strong> (marker not found).";
            } else {
                // Check individual critical sections
                $checks = [
                    'HTTPS redirect'    => 'RewriteCond %{HTTPS} !=on',
                    'Clean URL router'  => 'RewriteRule ^([a-zA-Z0-9_-]+)$ index.php',
                    'Security headers'  => 'X-Frame-Options',
                    'Sensitive files'   => 'FilesMatch "(^\\.ht',
                    'Core PHP blocking' => 'FilesMatch "^(db|auth|constants',
                    'Directory listings' => 'Options -Indexes',
                    'Asset caching'     => 'mod_expires.c',
                    'GZIP compression'  => 'mod_deflate.c',
                ];
                foreach ($checks as $label => $needle) {
                    if (strpos($content, $needle) === false) {
                        $issues[] = "<strong>{$label}</strong> rule is missing or damaged.";
                    }
                }
            }
        }

        // --- Diagnose img_uploads/.htaccess ---
        if (!is_dir(__DIR__ . '/img_uploads')) {
            // Not an issue — directory may not exist yet
        } elseif (!file_exists($uploads_htaccess)) {
            $issues[] = "Upload directory PHP execution block is <strong>missing</strong> (img_uploads/.htaccess).";
        } else {
            $upl_content = file_get_contents($uploads_htaccess);
            if (strpos($upl_content, 'Deny from all') === false) {
                $issues[] = "Upload directory .htaccess exists but <strong>PHP blocking rule is missing</strong>.";
            }
        }

        // --- Report or Repair ---
        if ($action === 'htaccess_check') {
            if (empty($issues)) {
                $log[] = "SUCCESS: All .htaccess rules verified — no issues detected.";
            } else {
                $log[] = "WARNING: Found " . count($issues) . " issue(s):<br>&nbsp;&nbsp;• " . implode("<br>&nbsp;&nbsp;• ", $issues)
                       . "<br>Use <strong>REPAIR</strong> to fix.";
            }
        }

        if ($action === 'htaccess_repair') {
            $repaired = [];

            // Rebuild root .htaccess SnapSmack block
            $existing = file_exists($htaccess_path) ? file_get_contents($htaccess_path) : '';

            // Strip old SnapSmack block if present (everything from marker to end-of-block)
            if (strpos($existing, $htaccess_marker) !== false) {
                // Remove from the first blank line before the marker block to end of our rules
                $existing = preg_replace(
                    '/\n*# ─+\n' . preg_quote($htaccess_marker, '/') . '.*$/s',
                    '',
                    $existing
                );
                $existing = rtrim($existing) . "\n";
            }

            $snapsmack_rules = <<<'HTACCESS'

# ─────────────────────────────────────────────────────────────
# SNAPSMACK-HTACCESS-RULES
# Do not remove the marker above — the installer and repair
# tool use it to detect whether these rules are present.
# ─────────────────────────────────────────────────────────────

# ─── FORCE HTTPS ─────────────────────────────────────────────
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]

# ─── PHP LIMITS ──────────────────────────────────────────────
php_value upload_max_filesize 64M
php_value post_max_size 64M
php_value memory_limit 128M
php_value max_execution_time 120

# ─── CLEAN URL ROUTER ────────────────────────────────────────
RewriteBase /

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

RewriteRule ^archive$ archive.php [L,QSA]
RewriteRule ^rss$ rss.php [L,QSA]
RewriteRule ^feed$ rss.php [L,QSA]

RewriteRule ^([a-zA-Z0-9_-]+)$ index.php?name=$1 [L,QSA]

# ─── SECURITY HEADERS ────────────────────────────────────────
<IfModule mod_headers.c>
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

# ─── BLOCK SENSITIVE FILES ───────────────────────────────────
<FilesMatch "(^\.ht|\.sql$|\.log$|\.bak$|\.inc$|\.sh$|\.env$)">
    Order Allow,Deny
    Deny from all
</FilesMatch>

<FilesMatch "^(db|auth|constants|release-pubkey|updater|skin-registry|manifest-inventory)\.php$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# ─── NO DIRECTORY LISTINGS ───────────────────────────────────
Options -Indexes

# ─── STATIC ASSET CACHING ───────────────────────────────────
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpeg "access plus 30 days"
    ExpiresByType image/png "access plus 30 days"
    ExpiresByType image/gif "access plus 30 days"
    ExpiresByType image/webp "access plus 30 days"
    ExpiresByType text/css "access plus 7 days"
    ExpiresByType application/javascript "access plus 7 days"
    ExpiresByType font/ttf "access plus 30 days"
    ExpiresByType font/woff2 "access plus 30 days"
</IfModule>

# ─── GZIP COMPRESSION ───────────────────────────────────────
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml
</IfModule>
HTACCESS;

            @file_put_contents($htaccess_path, $existing . $snapsmack_rules, LOCK_EX);
            $repaired[] = "Root .htaccess SnapSmack rules regenerated";

            // Rebuild img_uploads/.htaccess
            if (is_dir(__DIR__ . '/img_uploads')) {
                $upload_block = "<FilesMatch \"\\.php$\">\n    Order Deny,Allow\n    Deny from all\n</FilesMatch>\n";
                @file_put_contents($uploads_htaccess, $upload_block, LOCK_EX);
                $repaired[] = "Upload directory PHP execution block restored";
            }

            $log[] = "SUCCESS: " . implode(". ", $repaired) . ".";
        }
    }
}

$page_title = "System Maintenance";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>SYSTEM MAINTENANCE</h2>

    <?php foreach($log as $entry): ?>
        <div class="alert alert-success">> <?php echo $entry; ?></div>
    <?php endforeach; ?>

    <div class="dash-grid">
        <div class="box box-flex">
            <h3>CLEAN ORPHAN DATA</h3>
            <p class="skin-desc-text">Purges leftover category and album mappings for images that no longer exist. Fixes count mismatches without touching any files.</p>
            <form method="POST">
                <input type="hidden" name="action" value="sync_cats">
                <button type="submit" class="btn-smack btn-block">CLEAN DATABASE</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>OPTIMIZE TABLES</h3>
            <p class="skin-desc-text">Defragments MySQL tables and rebuilds indexes. Speeds up queries across the dashboard and public site.</p>
            <form method="POST">
                <input type="hidden" name="action" value="optimize">
                <button type="submit" class="btn-smack btn-block">OPTIMIZE</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>REBUILD THUMBNAILS</h3>
            <p class="skin-desc-text">Regenerates missing thumbnails in batches of 25. Orphan image files with no database record are deleted on the final batch.</p>
            <?php if (!empty($asset_sync_has_more)): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="sync_assets">
                    <input type="hidden" name="batch_offset" value="<?php echo $asset_sync_next_offset; ?>">
                    <button type="submit" class="btn-smack btn-block btn-backup">CONTINUE (BATCH <?php echo $asset_sync_next_offset; ?>+)</button>
                </form>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="sync_assets">
                    <input type="hidden" name="batch_offset" value="0">
                    <button type="submit" class="btn-smack btn-block">REBUILD</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-grid dash-grid-2 mt-30">
        <div class="box box-flex">
            <h3>HTACCESS DIAGNOSTICS</h3>
            <p class="skin-desc-text">Verifies that root and upload directory .htaccess files contain all required SnapSmack rules — HTTPS, clean URLs, security headers, PHP blocking, caching, and compression.</p>
            <form method="POST">
                <input type="hidden" name="action" value="htaccess_check">
                <button type="submit" class="btn-smack btn-block">RUN CHECK</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>HTACCESS REPAIR</h3>
            <p class="skin-desc-text">Strips any damaged SnapSmack block and writes a clean copy of all rules. Preserves any non-SnapSmack rules added by your host. Restores upload directory PHP execution block.</p>
            <form method="POST" onsubmit="return confirm('This will regenerate the SnapSmack .htaccess rules. Any manual edits inside the SnapSmack block will be replaced. Continue?')">
                <input type="hidden" name="action" value="htaccess_repair">
                <button type="submit" class="btn-smack btn-block">REPAIR</button>
            </form>
        </div>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
