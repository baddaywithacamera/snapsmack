<?php
/**
 * SNAPSMACK - Disaster Recovery
 * Alpha v0.7.4
 *
 * Serious-business recovery operations: full Recovery Kit export/import
 * and User Credentials export. Separated from routine backup tools so
 * the stakes are clear before you click anything.
 */

require_once 'core/auth.php';

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

    // USER CREDENTIALS EXPORT: snap_users table only.
    if ($_POST['type'] === 'keys') {
        $filename = "snapsmack_keys_" . date('Y-m-d_H-i') . ".sql";
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = "-- SnapSmack Disaster Recovery\n-- Type: USER CREDENTIALS\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";
        $res     = $pdo->query("SHOW CREATE TABLE snap_users")->fetch(PDO::FETCH_ASSOC);
        $output .= "DROP TABLE IF EXISTS `snap_users`;\n" . $res['Create Table'] . ";\n\n";
        $rows    = $pdo->query("SELECT * FROM snap_users")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $keys    = array_map(fn($k) => "`$k`", array_keys($row));
            $vals    = array_map(fn($v) => $v === null ? "NULL" : $pdo->quote($v), array_values($row));
            $output .= "INSERT INTO `snap_users` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $vals) . ");\n";
        }
        echo $output;
        exit;
    }
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
                Complete backup — database, media library, branding assets, active skin.
                Everything needed to rebuild from scratch.
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
                Upload a previously exported .tar.gz to restore your entire site.
                Overwrites the database and restores all files.
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

        <div class="box box-flex">
            <h3>USER CREDENTIALS</h3>
            <p class="skin-desc-text">
                Exports the user table only — logins and permission hashes.
                Essential for regaining entry to a fresh install.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="export">
                <input type="hidden" name="type"   value="keys">
                <button type="submit" class="btn-smack btn-block">DOWNLOAD</button>
            </form>
        </div>

    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
