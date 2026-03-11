<?php
/**
 * SNAPSMACK - FTP Configuration & Backup
 * Alpha v0.7.1
 *
 * Provides web UI for configuring FTP server settings and managing remote backups.
 * Tests FTP connectivity, pushes recovery kits and image directories to remote storage.
 * Tracks last push timestamp and status in the snap_settings table.
 */

require_once 'core/auth.php';
require_once 'core/ftp-engine.php';

// Load all settings
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

// --- AJAX ENDPOINTS (processed before HTML output) ---

// TEST FTP CONNECTION
if (isset($_GET['action']) && $_GET['action'] === 'test_connection') {
    header('Content-Type: application/json');

    $config = [
        'host'       => $settings['ftp_host'] ?? '',
        'port'       => (int)($settings['ftp_port'] ?? 21),
        'user'       => $settings['ftp_user'] ?? '',
        'pass'       => !empty($settings['ftp_pass']) ? SnapSmackFTP::decryptPassword($settings['ftp_pass'], $settings['download_salt'] ?? '') : '',
        'remote_dir' => $settings['ftp_remote_dir'] ?? '/',
        'use_ssl'    => (bool)($settings['ftp_use_ssl'] ?? false),
        'passive'    => (bool)($settings['ftp_passive'] ?? true),
    ];

    $ftp = new SnapSmackFTP($config);
    $result = $ftp->testConnection();

    echo json_encode($result);
    exit;
}

// PUSH TO REMOTE (recovery kit, images, or full)
if (isset($_GET['action']) && $_GET['action'] === 'push_now') {
    header('Content-Type: text/plain; charset=utf-8');

    // Get scope from query parameter
    $scope = $_GET['scope'] ?? 'recovery';
    if (!in_array($scope, ['recovery', 'images', 'full'])) {
        echo "ERROR: Invalid scope parameter.\n";
        exit;
    }

    // Prepare FTP configuration
    $config = [
        'host'       => $settings['ftp_host'] ?? '',
        'port'       => (int)($settings['ftp_port'] ?? 21),
        'user'       => $settings['ftp_user'] ?? '',
        'pass'       => !empty($settings['ftp_pass']) ? SnapSmackFTP::decryptPassword($settings['ftp_pass'], $settings['download_salt'] ?? '') : '',
        'remote_dir' => $settings['ftp_remote_dir'] ?? '/',
        'use_ssl'    => (bool)($settings['ftp_use_ssl'] ?? false),
        'passive'    => (bool)($settings['ftp_passive'] ?? true),
    ];

    $ftp = new SnapSmackFTP($config);

    // Connect to FTP
    $conn = $ftp->connect();
    if (!$conn['success']) {
        echo "ERROR: " . $conn['message'] . "\n";
        exit;
    }

    echo "FTP Connection established.\n";
    echo "────────────────────────────────────────\n\n";

    // Include export engine if we need recovery kit
    if ($scope === 'recovery' || $scope === 'full') {
        require_once 'core/export-engine.php';
        $exporter = new SnapSmackExport($pdo, __DIR__);
    }

    // Push recovery kit
    if ($scope === 'recovery' || $scope === 'full') {
        echo "STEP 1: Generating recovery kit...\n";
        ob_flush();
        flush();

        try {
            $recovery_path = $exporter->exportRecoveryKit();
            echo "Recovery kit generated: " . basename($recovery_path) . "\n\n";

            echo "STEP 2: Uploading recovery kit to remote...\n";
            ob_flush();
            flush();

            $ftp->pushRecoveryKit($recovery_path, function($msg, $status) {
                echo "[$status] " . $msg . "\n";
                ob_flush();
                flush();
            });

            // Clean up local temporary file
            @unlink($recovery_path);
            echo "\nRecovery kit uploaded successfully.\n\n";
        } catch (Exception $e) {
            echo "ERROR generating recovery kit: " . $e->getMessage() . "\n";
            exit;
        }
    }

    // Push image directories
    if ($scope === 'images' || $scope === 'full') {
        if ($scope === 'full') {
            echo "STEP 3: Uploading image library...\n";
        } else {
            echo "STEP 1: Uploading image library...\n";
        }
        ob_flush();
        flush();

        $img_dir = __DIR__ . '/img_uploads';
        $remoteImgDir = rtrim($settings['ftp_remote_dir'] ?? '/', '/') . '/img_uploads';
        if (is_dir($img_dir)) {
            $stats = $ftp->pushDirectory($img_dir, $remoteImgDir, function($msg, $status) {
                echo "[$status] " . $msg . "\n";
                ob_flush();
                flush();
            });

            echo "\n" . str_repeat("─", 40) . "\n";
            echo "Image upload summary:\n";
            echo "  Uploaded: " . $stats['uploaded'] . "\n";
            echo "  Skipped:  " . $stats['skipped'] . "\n";
            echo "  Failed:   " . $stats['failed'] . "\n";
            echo "  Total:    " . ($stats['bytes'] / 1048576) . " MB\n";
            echo str_repeat("─", 40) . "\n\n";
        } else {
            echo "No img_uploads directory found. Skipping image library.\n\n";
        }
    }

    // Update last push timestamp and status in settings
    $push_status = "Push completed at " . date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
    $stmt->execute(['ftp_last_push', date('c'), date('c')]);
    $stmt->execute(['ftp_last_status', $push_status, $push_status]);

    echo "Push operation completed and logged.\n";
    exit;
}

// --- FORM SUBMISSION HANDLER (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ftp_settings'])) {
    // Validate and save FTP settings
    $ftp_host = trim($_POST['ftp_host'] ?? '');
    $ftp_port = (int)($_POST['ftp_port'] ?? 21);
    $ftp_user = trim($_POST['ftp_user'] ?? '');
    $ftp_pass = trim($_POST['ftp_pass'] ?? '');
    $ftp_remote_dir = trim($_POST['ftp_remote_dir'] ?? '/');
    $ftp_use_ssl = isset($_POST['ftp_use_ssl']) ? (int)$_POST['ftp_use_ssl'] : 0;
    $ftp_passive = isset($_POST['ftp_passive']) ? (int)$_POST['ftp_passive'] : 1;

    // Encrypt password if provided
    if (!empty($ftp_pass)) {
        $ftp_pass = SnapSmackFTP::encryptPassword($ftp_pass, $settings['download_salt'] ?? '');
    } else {
        // If password is empty, check if there's an existing one
        $ftp_pass = $settings['ftp_pass'] ?? '';
    }

    // Upsert all FTP settings
    $settings_to_save = [
        'ftp_host'       => $ftp_host,
        'ftp_port'       => $ftp_port,
        'ftp_user'       => $ftp_user,
        'ftp_pass'       => $ftp_pass,
        'ftp_remote_dir' => $ftp_remote_dir,
        'ftp_use_ssl'    => $ftp_use_ssl,
        'ftp_passive'    => $ftp_passive,
    ];

    foreach ($settings_to_save as $key => $val) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute([$key, $val, $val]);
    }

    $msg = "FTP settings saved successfully.";

    // Reload settings from database
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

$page_title = "FTP Backup Configuration";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>FTP BACKUP & REMOTE PUSH</h2>
    </div>

    <?php if (isset($msg)): ?>
        <div class="alert">> <?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <!-- ================================================================
         FTP SERVER CONFIGURATION
         ================================================================ -->
    <div class="box">
        <h3>FTP SERVER CONFIGURATION</h3>

        <?php if (!SnapSmackFTP::isAvailable()): ?>
            <div class="alert alert-warning">⚠ FTP EXTENSION NOT AVAILABLE ON THIS SERVER</div>
        <?php endif; ?>

        <?php if (!SnapSmackFTP::isSslAvailable()): ?>
            <div class="dim" style="margin-bottom: 20px;">
                ⚠ FTPS NOT AVAILABLE — SERVER LACKS OPENSSL EXTENSION
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="post-layout-grid">
                <div class="post-col-left">
                    <label>FTP HOST</label>
                    <input type="text" name="ftp_host" value="<?php echo htmlspecialchars($settings['ftp_host'] ?? ''); ?>" placeholder="ftp.example.com">

                    <label>FTP PORT</label>
                    <input type="number" name="ftp_port" value="<?php echo htmlspecialchars($settings['ftp_port'] ?? '21'); ?>" min="1" max="65535">

                    <label>FTP USERNAME</label>
                    <input type="text" name="ftp_user" value="<?php echo htmlspecialchars($settings['ftp_user'] ?? ''); ?>" placeholder="username">
                </div>

                <div class="post-col-right">
                    <label>FTP PASSWORD</label>
                    <input type="password" name="ftp_pass" placeholder="<?php echo !empty($settings['ftp_pass']) ? '••••••••••••••••' : 'password'; ?>">
                    <span class="dim">Leave blank to keep existing password.</span>

                    <label>REMOTE DIRECTORY</label>
                    <input type="text" name="ftp_remote_dir" value="<?php echo htmlspecialchars($settings['ftp_remote_dir'] ?? '/'); ?>" placeholder="/">

                    <label>USE SECURE CONNECTION (FTPS)</label>
                    <select name="ftp_use_ssl" <?php echo !SnapSmackFTP::isSslAvailable() ? 'disabled' : ''; ?>>
                        <option value="0" <?php echo (($settings['ftp_use_ssl'] ?? 0) == 0) ? 'selected' : ''; ?>>NO</option>
                        <option value="1" <?php echo (($settings['ftp_use_ssl'] ?? 0) == 1) ? 'selected' : ''; ?>>YES (RECOMMENDED)</option>
                    </select>

                    <label>PASSIVE MODE</label>
                    <select name="ftp_passive">
                        <option value="0" <?php echo (($settings['ftp_passive'] ?? 1) == 0) ? 'selected' : ''; ?>>NO</option>
                        <option value="1" <?php echo (($settings['ftp_passive'] ?? 1) == 1) ? 'selected' : ''; ?>>YES (RECOMMENDED)</option>
                    </select>
                </div>
            </div>

            <button type="submit" name="save_ftp_settings" class="btn-smack" style="margin-top: 20px;">SAVE FTP CONFIGURATION</button>
        </form>
    </div>

    <!-- ================================================================
         CONNECTION TEST
         ================================================================ -->
    <div class="box">
        <h3>CONNECTION TEST</h3>
        <p class="skin-desc-text">Verify that the FTP server is accessible with the current settings.</p>

        <button type="button" id="test-connection-btn" class="btn-smack">TEST CONNECTION</button>

        <div id="ftp-test-result" style="margin-top: 15px;"></div>
    </div>

    <!-- ================================================================
         PUSH TO REMOTE
         ================================================================ -->
    <div class="box">
        <h3>PUSH TO REMOTE</h3>
        <p class="skin-desc-text">Send backups to your remote FTP server. Choose what to push: just the recovery kit, the image library, or everything.</p>

        <div class="dash-grid">
            <div class="box box-flex">
                <h4 style="margin-top: 0;">RECOVERY KIT</h4>
                <p class="skin-desc-text">Database, branding, and core files packaged as .tar.gz. Portable fallback for system restoration.</p>
                <button type="button" class="btn-smack btn-block push-btn" data-scope="recovery" disabled>PUSH RECOVERY KIT</button>
            </div>

            <div class="box box-flex">
                <h4 style="margin-top: 0;">IMAGE LIBRARY</h4>
                <p class="skin-desc-text">Complete img_uploads/ directory with all user-uploaded images and thumbnails.</p>
                <button type="button" class="btn-smack btn-block push-btn" data-scope="images" disabled>PUSH IMAGES</button>
            </div>

            <div class="box box-flex">
                <h4 style="margin-top: 0;">FULL BACKUP</h4>
                <p class="skin-desc-text">Everything: recovery kit AND image library. Most comprehensive option.</p>
                <button type="button" class="btn-smack btn-block push-btn" data-scope="full" disabled>PUSH FULL BACKUP</button>
            </div>
        </div>

        <div id="ftp-push-progress" style="margin-top: 20px;"></div>
    </div>

    <!-- ================================================================
         LAST PUSH STATUS
         ================================================================ -->
    <div class="box">
        <h3>LAST PUSH STATUS</h3>

        <?php
        $last_push = $settings['ftp_last_push'] ?? null;
        $last_status = $settings['ftp_last_status'] ?? null;
        ?>

        <?php if ($last_push): ?>
            <div style="margin-bottom: 10px;">
                <strong>Timestamp:</strong> <?php echo htmlspecialchars($last_push); ?>
            </div>
            <div>
                <strong>Status:</strong> <?php echo htmlspecialchars($last_status ?? 'Unknown'); ?>
            </div>
        <?php else: ?>
            <p class="dim">NO PUSH HISTORY</p>
        <?php endif; ?>
    </div>
</div>

<script>
// --- TEST CONNECTION ---
document.getElementById('test-connection-btn').addEventListener('click', function() {
    const btn = this;
    const resultDiv = document.getElementById('ftp-test-result');

    btn.disabled = true;
    resultDiv.innerHTML = '<p class="dim">Testing connection...</p>';

    fetch('smack-ftp.php?action=test_connection')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                resultDiv.innerHTML = '<div class="alert alert-success">' +
                    '✓ ' + htmlEncode(data.message) + '</div>';
                // Enable push buttons on success
                document.querySelectorAll('.push-btn').forEach(btn => btn.disabled = false);
            } else {
                resultDiv.innerHTML = '<div class="alert alert-warning">' +
                    '✗ ' + htmlEncode(data.message) + '</div>';
            }
        })
        .catch(e => {
            resultDiv.innerHTML = '<div class="alert alert-warning">ERROR: ' + htmlEncode(e.message) + '</div>';
        })
        .finally(() => {
            btn.disabled = false;
        });
});

// --- PUSH TO REMOTE ---
document.querySelectorAll('.push-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const scope = this.dataset.scope;
        const progressDiv = document.getElementById('ftp-push-progress');
        const testBtn = document.getElementById('test-connection-btn');

        // Disable all buttons during push
        document.querySelectorAll('.push-btn').forEach(b => b.disabled = true);
        testBtn.disabled = true;

        progressDiv.innerHTML = '<pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; font-size: 12px; overflow-x: auto; max-height: 400px; line-height: 1.5;"></pre>';
        const preEl = progressDiv.querySelector('pre');

        const url = 'smack-ftp.php?action=push_now&scope=' + encodeURIComponent(scope);

        fetch(url)
            .then(response => response.body.getReader())
            .then(reader => {
                const decoder = new TextDecoder();

                return reader.read().then(function process({done, value}) {
                    if (done) return;

                    const chunk = decoder.decode(value, {stream: true});
                    preEl.textContent += chunk;

                    // Auto-scroll to bottom
                    preEl.parentElement.scrollTop = preEl.parentElement.scrollHeight;

                    return reader.read().then(process);
                });
            })
            .catch(e => {
                preEl.textContent += '\nERROR: ' + e.message;
            })
            .finally(() => {
                // Re-enable all buttons
                document.querySelectorAll('.push-btn').forEach(b => b.disabled = false);
                testBtn.disabled = false;
            });
    });
});

// Helper to encode HTML entities
function htmlEncode(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
</script>

<?php include 'core/admin-footer.php'; ?>
