<?php
/**
 * SNAPSMACK - System backup and recovery
 * Alpha v0.7
 *
 * Comprehensive backup & recovery system with Recovery Kit, Data Liberation exports,
 * and integration with FTP remote backup capabilities.
 * Preserves SQL database extractions, WordPress exports, portable JSON, and source archival.
 */

require_once 'core/auth.php';

// --- EXPORT & RECOVERY HANDLERS ---
if (isset($_POST['action']) && $_POST['action'] === 'export') {
    $type = $_POST['type'];

    // SQL EXTRACTION: Processes Full Dumps, Schemas, or User Keys.
    if (in_array($type, ['full', 'schema'])) {
        $filename = "snapsmack_" . $type . "_" . date('Y-m-d_H-i') . ".sql";
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = "-- SnapSmack Backup Service\n-- Type: " . strtoupper($type) . "\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";
        $tables = ['snap_images', 'snap_categories', 'snap_image_cat_map', 'snap_image_album_map', 'snap_albums', 'snap_comments', 'snap_users', 'snap_settings', 'snap_pages', 'snap_blogroll', 'snap_assets'];

        foreach ($tables as $table) {
            $res = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_ASSOC);
            $output .= "DROP TABLE IF EXISTS `$table`;\n" . $res['Create Table'] . ";\n\n";

            if ($type !== 'schema') {
                $rows = $pdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $keys = array_map(function($k) { return "`$k`"; }, array_keys($row));
                    $vals = array_map(function($v) use ($pdo) { return $v === null ? "NULL" : $pdo->quote($v); }, array_values($row));
                    $output .= "INSERT INTO `$table` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $vals) . ");\n";
                }
                $output .= "\n";
            }
        }
        echo $output;
        exit;
    }

    // SOURCE ARCHIVE: Bundles logic and design files while excluding heavy media.
    if ($type === 'source') {
        $filename = "snapsmack_source_" . date('Y-m-d_H-i') . ".tar";
        $tempPath = sys_get_temp_dir() . '/' . $filename;

        try {
            $a = new PharData($tempPath);
            $rootPath = realpath(__DIR__);
            $media_dirs = ['assets/img', 'media', 'uploads', 'img_uploads', 'skins'];
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($files as $name => $file) {
                if ($file->isDir()) continue;

                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($rootPath) + 1);
                $relativePath = str_replace('\\', '/', $relativePath);

                $isMedia = false;
                foreach ($media_dirs as $dir) {
                    if (strpos($relativePath, $dir . '/') === 0) {
                        $isMedia = true;
                        break;
                    }
                }
                if (!$isMedia && !str_contains($relativePath, '.tar')) {
                    $a->addFile($filePath, $relativePath);
                }
            }

            // Compress to GZ format and finalize stream.
            $gzPath = $a->compress(Phar::GZ)->getPath();

            unset($a);
            unlink($tempPath);
            header('Content-Type: application/x-gzip');
            header('Content-Disposition: attachment; filename="' . basename($gzPath) . '"');
            header('Content-Length: ' . filesize($gzPath));
            readfile($gzPath);
            unlink($gzPath);
            exit;

        } catch (Exception $e) {
            die("RECOVERY_ENGINE_CRITICAL: " . $e->getMessage());
        }
    }

    // WORDPRESS WXR EXPORT: Standard WordPress eXtended RSS format.
    if ($type === 'wxr') {
        require_once 'core/export-engine.php';
        try {
            $exporter = new SnapSmackExport($pdo, __DIR__);
            $wxrContent = $exporter->exportWordPressWXR();

            $filename = "snapsmack_wordpress_" . date('Y-m-d_H-i') . ".xml";
            header('Content-Type: application/xml');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $wxrContent;
            exit;
        } catch (Exception $e) {
            die("WXR_EXPORT_ERROR: " . $e->getMessage());
        }
    }

    // PORTABLE JSON EXPORT: Platform-agnostic JSON with documented schema.
    if ($type === 'json_export') {
        require_once 'core/export-engine.php';
        try {
            $exporter = new SnapSmackExport($pdo, __DIR__);
            $jsonContent = $exporter->exportPortableJSON();

            $filename = "snapsmack_export_" . date('Y-m-d_H-i') . ".json";
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $jsonContent;
            exit;
        } catch (Exception $e) {
            die("JSON_EXPORT_ERROR: " . $e->getMessage());
        }
    }
}

// Load FTP settings for last push information.
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

$page_title = "Backup & Recovery";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>BACKUP & RECOVERY</h2>
        <div class="header-actions">
            <a href="smack-disaster.php" class="btn-smack">DISASTER RECOVERY</a>
        </div>
    </div>

    <!-- ============================================================
         ROUTINE BACKUPS — Full SQL dump + Schema only
         ============================================================ -->
    <div class="dash-grid dash-grid-2 mt-30">
        <div class="box box-flex">
            <h3>FULL SQL DUMP</h3>
            <p class="skin-desc-text">Complete database export — structure and all content. Standard backup for site migrations.</p>
            <form method="POST">
                <input type="hidden" name="action" value="export">
                <input type="hidden" name="type" value="full">
                <button type="submit" class="btn-smack btn-block">DOWNLOAD</button>
            </form>
        </div>
        <div class="box box-flex">
            <h3>SCHEMA ONLY</h3>
            <p class="skin-desc-text">Table structure with no data. Used for system updates, cloning the engine, or debugging.</p>
            <form method="POST">
                <input type="hidden" name="action" value="export">
                <input type="hidden" name="type" value="schema">
                <button type="submit" class="btn-smack btn-block">DOWNLOAD</button>
            </form>
        </div>
    </div>

    <!-- ============================================================
         MIGRATION TOOLS — WordPress and JSON exports
         ============================================================ -->
    <div class="box mt-30">
        <h3>MIGRATION TOOLS</h3>
        <div class="dash-grid dash-grid-2">
            <div class="box box-flex">
                <h3>WORDPRESS WXR</h3>
                <p class="skin-desc-text">Standard WordPress eXtended RSS format. Import directly into any WordPress site.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="export">
                    <input type="hidden" name="type" value="wxr">
                    <button type="submit" class="btn-smack btn-block">EXPORT</button>
                </form>
            </div>
            <div class="box box-flex">
                <h3>PORTABLE JSON</h3>
                <p class="skin-desc-text">Platform-agnostic export with documented schema. For migration to any CMS.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="export">
                    <input type="hidden" name="type" value="json_export">
                    <button type="submit" class="btn-smack btn-block">EXPORT</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================================
         SYSTEM TOOLS — Integrity check + Source archive
         ============================================================ -->
    <div class="dash-grid dash-grid-2 mt-30">
        <div class="box box-flex">
            <h3>VERIFY INTEGRITY</h3>
            <p class="skin-desc-text">Spot-checks files against stored SHA-256 checksums. Lightweight — no full filesystem walk.</p>
            <a href="smack-verify.php" class="btn-smack btn-block">RUN CHECK</a>
        </div>
        <div class="box box-flex">
            <h3>SOURCE ARCHIVE</h3>
            <p class="skin-desc-text">PHP and CSS logic only — no media. Lightweight code-only snapshot for version control.</p>
            <form method="POST">
                <input type="hidden" name="action" value="export">
                <input type="hidden" name="type" value="source">
                <button type="submit" class="btn-smack btn-block">DOWNLOAD</button>
            </form>
        </div>
    </div>

    <!-- ============================================================
         REMOTE PUSH — FTP + Cloud in one row
         ============================================================ -->
    <div class="dash-grid dash-grid-2 mt-30">
        <div class="box box-flex">
            <h3>FTP BACKUP</h3>
            <p class="skin-desc-text">Push recovery kits or images to a remote FTP server. Configure credentials, test connection, and push on demand.</p>
            <a href="smack-ftp.php" class="btn-smack btn-block">CONFIGURE FTP</a>
            <?php if (!empty($settings['ftp_last_push'])): ?>
                <p style="margin-top: 15px; font-size: 12px; color: #888;">
                    Last: <?php echo htmlspecialchars($settings['ftp_last_push']); ?>
                    <?php if (!empty($settings['ftp_last_status'])): ?>
                        — <?php echo htmlspecialchars($settings['ftp_last_status']); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="box box-flex">
            <h3>CLOUD BACKUP</h3>
            <p class="skin-desc-text">Push backups to Google Drive or OneDrive. Authorize once — refresh tokens are stored encrypted for persistent access.</p>
            <a href="smack-cloud.php" class="btn-smack btn-block">CONFIGURE CLOUD</a>
            <?php if (!empty($settings['cloud_last_push'])): ?>
                <p style="margin-top: 15px; font-size: 12px; color: #888;">
                    Last: <?php echo htmlspecialchars($settings['cloud_last_push']); ?>
                    <?php if (!empty($settings['cloud_last_status'])): ?>
                        — <?php echo htmlspecialchars($settings['cloud_last_status']); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
