<?php
/**
 * SNAPSMACK - Disaster Recovery
 *
 * Serious-business recovery operations: full Recovery Kit export/import.
 * Separated from routine backup tools so the stakes are clear before you
 * click anything.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once __DIR__ . '/core/csrf.php';
csrf_exempt();

// SUYB scoped key (see suyb-data.php). Additive — legacy auth still works.
$GLOBALS['SNAP_API_KEY_TYPES'] = ['suyb'];
require_once 'core/api-auth.php';

// --- RECOVERY KIT EXPORT ---
if (isset($_POST['action']) && $_POST['action'] === 'export') {

    // RECOVERY KIT EXPORT: Complete site backup with database, branding, media, and skin.
    if ($_POST['type'] === 'recovery_kit') {
        require_once 'core/export-engine.php';
        try {
            $exporter = new SnapSmackExport($pdo, __DIR__);
            $kitPath  = $exporter->exportRecoveryKit();

            header('Content-Type: application/x-gzip');
            header('Content-Disposition: attachment; filename="' . basename($kitPath) . '"');
            header('Content-Length: ' . filesize($kitPath));
            readfile($kitPath);
            unlink($kitPath);
            exit;
        } catch (Exception $e) {
            die("RECOVERY_KIT_ERROR: " . $e->getMessage());
        }
    }

    // USER CREDENTIALS EXPORT: REMOVED 0.7.440. It dumped the entire snap_users
    // table (password hashes + any 2FA secrets, via SELECT *) as a one-click / one-
    // POST download — an unsafe exfil path, and redundant with the full recovery-kit
    // DB dump above (which already contains snap_users). Do NOT reinstate a bare
    // table dump here; credential recovery belongs in the encrypted Break-Glass Card.
}

// --- RECOVERY KIT IMPORT ---
if (isset($_POST['action']) && $_POST['action'] === 'import_recovery') {
    if (!empty($_FILES['recovery_file']['tmp_name'])) {
        require_once 'core/recovery-engine.php';
        try {
            $recovery      = new SnapSmackRecovery($pdo, __DIR__);
            $import_result = $recovery->importRecoveryKit($_FILES['recovery_file']['tmp_name']);
        } catch (Exception $e) {
            $import_result = ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

$page_title = "Disaster Recovery";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>DISASTER RECOVERY</h2>
        <div class="header-actions">
            <a href="smack-backup.php" class="btn-clear">← Backup &amp; Recovery</a>
        </div>
    </div>

    <?php if (isset($import_result) && is_array($import_result)): ?>
    <div class="box">
        <?php if (empty($import_result['errors'])): ?>
            <div class="alert">> RECOVERY KIT IMPORTED SUCCESSFULLY<br>
                SQL statements: <?php echo $import_result['sql_imported'] ?? 0; ?><br>
                Files restored: <?php echo $import_result['files_restored'] ?? 0; ?><br>
                Checksums verified: <?php echo $import_result['checksum_ok'] ?? 0; ?><br>
                <?php if (($import_result['checksum_fail'] ?? 0) > 0): ?>
                    Checksum failures: <?php echo $import_result['checksum_fail']; ?><br>
                <?php endif; ?>
                <?php if (($import_result['not_bundled'] ?? 0) > 0): ?>
                    Not bundled (restore via FTP/Cloud): <?php echo $import_result['not_bundled']; ?><br>
                <?php endif; ?>
                <?php if (($import_result['missing'] ?? 0) > 0): ?>
                    Missing files: <?php echo $import_result['missing']; ?><br>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="alert">> RECOVERY KIT IMPORT COMPLETED WITH ERRORS<br>
                SQL statements: <?php echo $import_result['sql_imported'] ?? 0; ?><br>
                Files restored: <?php echo $import_result['files_restored'] ?? 0; ?><br>
                <?php foreach ($import_result['errors'] as $err): ?>
                    ERROR: <?php echo htmlspecialchars($err); ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="dash-grid">

        <div class="box box-flex">
            <h3>EXPORT RECOVERY KIT</h3>
            <p class="skin-desc-text">
                Full SQL dump plus a complete file manifest with SHA-256 checksums
                for every media file, branding asset, and skin file. Media files are
                inventoried (path, size, hash) but not bundled — use FTP or Cloud
                Backup to push actual files separately.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="export">
                <input type="hidden" name="type"   value="recovery_kit">
                <button type="submit" class="btn-smack btn-block">DOWNLOAD RECOVERY KIT</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>IMPORT RECOVERY KIT</h3>
            <p class="skin-desc-text">
                Upload a previously exported .tar.gz to restore your database
                and verify your file inventory. The SQL dump is imported directly;
                media files listed in the manifest must be restored separately
                via FTP or Cloud Backup.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_recovery">
                <div class="file-upload-wrapper" onclick="document.getElementById('recovery-input').click()">
                    <div class="file-custom-btn">SELECT FILE</div>
                    <div class="file-name-display" id="recovery-name">SELECT .TAR.GZ FILE</div>
                    <input type="file" name="recovery_file" id="recovery-input" accept=".tar.gz,.gz"
                           class="file-input-hidden"
                           onchange="document.getElementById('recovery-name').innerText = this.files[0].name;">
                </div>
                <button type="submit" class="btn-smack btn-block"
                        onclick="return confirm('This will overwrite your database and files. Continue?');">
                    IMPORT RECOVERY KIT
                </button>
            </form>
        </div>

    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
