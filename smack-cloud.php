<?php
/**
 * SNAPSMACK - Cloud Backup Configuration & Push
 * Alpha v0.7.2
 *
 * OAuth cloud push to Google Drive and OneDrive. Refresh tokens stored
 * encrypted (AES-256-CBC) in snap_settings — authorize once, push anytime.
 * Access tokens obtained silently from refresh tokens and cached in $_SESSION.
 */

require_once 'core/auth.php';
require_once 'core/cloud-engine.php';

// Load all settings
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$salt = $settings['download_salt'] ?? '';
$siteUrl = rtrim($settings['site_url'] ?? '', '/');

// =================================================================
// AJAX / REDIRECT HANDLERS (before HTML output)
// =================================================================

// --- OAUTH CALLBACK ---
if (isset($_GET['action']) && $_GET['action'] === 'oauth_callback') {
    $provider = $_GET['provider'] ?? '';
    $code     = $_GET['code'] ?? '';
    $state    = $_GET['state'] ?? '';
    $error    = $_GET['error'] ?? '';

    if ($error) {
        header("Location: smack-cloud.php?msg=" . urlencode("Authorization denied: {$error}") . "&msg_type=error");
        exit;
    }

    if (!in_array($provider, ['google', 'onedrive'])) {
        header("Location: smack-cloud.php?msg=" . urlencode("Unknown provider.") . "&msg_type=error");
        exit;
    }

    // Verify CSRF state
    if (!SnapSmackCloudOAuth::verifyState($provider, $state)) {
        header("Location: smack-cloud.php?msg=" . urlencode("Invalid state parameter. Possible CSRF attack.") . "&msg_type=error");
        exit;
    }

    // Exchange auth code for token
    $creds = SnapSmackCloudOAuth::getStoredCredentials($provider, $settings, $salt);
    $redirectUri = $siteUrl . '/smack-cloud.php?action=oauth_callback&provider=' . $provider;

    $result = SnapSmackCloudOAuth::exchangeAuthCode(
        $provider, $code, $creds['client_id'], $creds['client_secret'], $redirectUri
    );

    if ($result['success']) {
        $label = ($provider === 'google') ? 'Google Drive' : 'OneDrive';

        // Store encrypted refresh token in DB for persistent access
        if (!empty($result['refresh_token'])) {
            require_once 'core/ftp-engine.php';
            $encRefresh = SnapSmackFTP::encryptPassword($result['refresh_token'], $salt);
            $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
            $stmt->execute(["{$provider}_refresh_token", $encRefresh, $encRefresh]);
        }

        header("Location: smack-cloud.php?msg=" . urlencode("Linked to {$label} successfully.") . "&msg_type=success");
    } else {
        header("Location: smack-cloud.php?msg=" . urlencode($result['message']) . "&msg_type=error");
    }
    exit;
}

// --- DISCONNECT ---
if (isset($_GET['action']) && $_GET['action'] === 'disconnect') {
    $provider = $_GET['provider'] ?? '';
    if (in_array($provider, ['google', 'onedrive'])) {
        SnapSmackCloudOAuth::clearToken($provider, $pdo);
        $label = ($provider === 'google') ? 'Google Drive' : 'OneDrive';
        header("Location: smack-cloud.php?msg=" . urlencode("Unlinked from {$label}.") . "&msg_type=success");
    } else {
        header("Location: smack-cloud.php?msg=" . urlencode("Unknown provider.") . "&msg_type=error");
    }
    exit;
}

// --- PUSH TO CLOUD (streaming response) ---
if (isset($_GET['action']) && $_GET['action'] === 'push_now') {
    header('Content-Type: text/plain; charset=utf-8');

    $provider = $_GET['provider'] ?? '';
    $type     = $_GET['type'] ?? 'recovery_kit';

    if (!in_array($provider, ['google', 'onedrive'])) {
        echo "ERROR: Unknown provider.\n";
        exit;
    }

    if (!in_array($type, ['recovery_kit', 'wxr', 'json'])) {
        echo "ERROR: Invalid export type.\n";
        exit;
    }

    // Try to refresh from stored token if session token expired
    if (!SnapSmackCloudOAuth::ensureAccessToken($provider, $settings, $salt)) {
        echo "ERROR: Not linked to this provider. Please connect via Cloud Backup settings.\n";
        exit;
    }

    $accessToken = SnapSmackCloudOAuth::getAccessToken($provider);
    $label = ($provider === 'google') ? 'Google Drive' : 'OneDrive';

    echo "Cloud push to {$label}\n";
    echo str_repeat("─", 40) . "\n\n";
    ob_flush(); flush();

    // Progress callback
    $progressFn = function(string $msg, string $status) {
        echo "[{$status}] {$msg}\n";
        ob_flush(); flush();
    };

    // Generate the export file
    require_once 'core/export-engine.php';
    $exporter = new SnapSmackExport($pdo, __DIR__);

    try {
        if ($type === 'recovery_kit') {
            echo "STEP 1: Generating recovery kit...\n";
            ob_flush(); flush();
            $filePath = $exporter->exportRecoveryKit();
            $filename = basename($filePath);
        } elseif ($type === 'wxr') {
            echo "STEP 1: Generating WordPress WXR export...\n";
            ob_flush(); flush();
            $content = $exporter->exportWordPressWXR();
            $filename = "snapsmack_wordpress_" . date('Y-m-d_H-i') . ".xml";
            $filePath = sys_get_temp_dir() . '/' . $filename;
            file_put_contents($filePath, $content);
        } elseif ($type === 'json') {
            echo "STEP 1: Generating portable JSON export...\n";
            ob_flush(); flush();
            $content = $exporter->exportPortableJSON();
            $filename = "snapsmack_export_" . date('Y-m-d_H-i') . ".json";
            $filePath = sys_get_temp_dir() . '/' . $filename;
            file_put_contents($filePath, $content);
        }

        echo "[ok] Export generated: {$filename} (" . round(filesize($filePath) / 1048576, 1) . " MB)\n\n";
        echo "STEP 2: Uploading to {$label}...\n";
        ob_flush(); flush();

        // Upload
        $uploader = new SnapSmackCloudUploader($provider, $accessToken, $progressFn);
        $result = $uploader->uploadFile($filePath, $filename);

        // Clean up temp file
        @unlink($filePath);

        echo "\n" . str_repeat("─", 40) . "\n";
        if ($result['success']) {
            echo "[ok] {$result['message']}\n";

            // Update last push timestamp
            $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
            $pushTime = date('c');
            $pushStatus = "Pushed {$filename} to {$label}";
            $stmt->execute(['cloud_last_push', $pushTime, $pushTime]);
            $stmt->execute(['cloud_last_status', $pushStatus, $pushStatus]);

            echo "Push logged at {$pushTime}\n";
        } else {
            echo "[error] {$result['message']}\n";
        }

    } catch (Exception $e) {
        echo "[error] " . $e->getMessage() . "\n";
        if (isset($filePath) && file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    exit;
}

// =================================================================
// FORM SUBMISSION — Save OAuth Credentials
// =================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cloud_settings'])) {
    require_once 'core/ftp-engine.php';

    $providers = ['google', 'onedrive'];
    foreach ($providers as $p) {
        $clientId     = trim($_POST["{$p}_client_id"] ?? '');
        $clientSecret = trim($_POST["{$p}_client_secret"] ?? '');

        // Save client ID (plain text — not sensitive)
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute(["{$p}_client_id", $clientId, $clientId]);

        // Encrypt and save client secret if provided
        if (!empty($clientSecret)) {
            $encrypted = SnapSmackFTP::encryptPassword($clientSecret, $salt);
            $stmt->execute(["{$p}_client_secret", $encrypted, $encrypted]);
        }
        // If empty, keep existing secret (don't wipe it)
    }

    $msg = "Cloud backup settings saved.";
    $msg_type = 'success';

    // Reload settings
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Pick up flash messages from redirects
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
    $msg_type = $_GET['msg_type'] ?? 'success';
}

// Provider status — "linked" = refresh token stored, "ready" = active session token
$googleConfigured   = SnapSmackCloudOAuth::isProviderConfigured('google', $settings);
$onedriveConfigured = SnapSmackCloudOAuth::isProviderConfigured('onedrive', $settings);
$googleLinked       = SnapSmackCloudOAuth::isProviderLinked('google', $settings);
$onedriveLinked     = SnapSmackCloudOAuth::isProviderLinked('onedrive', $settings);
$googleReady        = $googleLinked || SnapSmackCloudOAuth::hasActiveToken('google');
$onedriveReady      = $onedriveLinked || SnapSmackCloudOAuth::hasActiveToken('onedrive');

$page_title = "Cloud Backup Configuration";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>CLOUD BACKUP & PUSH</h2>
    </div>

    <?php if (isset($msg)): ?>
        <div class="alert">> <?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <?php if (!SnapSmackCloudOAuth::isAvailable()): ?>
        <div class="alert">> cURL EXTENSION NOT AVAILABLE — CLOUD BACKUP REQUIRES cURL</div>
    <?php endif; ?>

    <!-- ================================================================
         PROVIDER CONFIGURATION
         ================================================================ -->
    <div class="box">
        <h3>PROVIDER CONFIGURATION</h3>
        <p class="dim">Enter your OAuth 2.0 credentials below. Client secrets are encrypted before storage. Leave a secret field blank to keep the existing stored value.</p>

        <form method="POST">
            <div class="post-layout-grid">
                <div class="post-col-left">
                    <h4 style="margin-top: 0;">GOOGLE DRIVE</h4>
                    <span class="dim">Requires a Google Cloud Console project with OAuth 2.0 credentials.</span>

                    <label>CLIENT ID</label>
                    <input type="text" name="google_client_id" value="<?php echo htmlspecialchars($settings['google_client_id'] ?? ''); ?>" placeholder="xxxx.apps.googleusercontent.com">

                    <label>CLIENT SECRET</label>
                    <input type="password" name="google_client_secret" placeholder="<?php echo !empty($settings['google_client_secret']) ? '••••••••••••••••' : 'client secret'; ?>">
                    <span class="dim">Leave blank to keep existing secret.</span>

                    <details style="margin-top: 15px;">
                        <summary class="dim" style="cursor: pointer;">SETUP GUIDE</summary>
                        <div style="padding: 10px 0; font-size: 12px; line-height: 1.6;">
                            1. Go to <strong>console.cloud.google.com</strong><br>
                            2. Create a new project (or select existing)<br>
                            3. Enable the <strong>Google Drive API</strong><br>
                            4. Go to <strong>Credentials → Create Credentials → OAuth client ID</strong><br>
                            5. Application type: <strong>Web application</strong><br>
                            6. Add redirect URI:<br>
                            <code style="font-size: 11px; background: rgba(0,0,0,0.05); padding: 2px 6px;"><?php echo htmlspecialchars($siteUrl); ?>/smack-cloud.php?action=oauth_callback&provider=google</code>
                        </div>
                    </details>
                </div>

                <div class="post-col-right">
                    <h4 style="margin-top: 0;">ONEDRIVE</h4>
                    <span class="dim">Requires an Azure AD app registration with Web redirect.</span>

                    <label>APPLICATION (CLIENT) ID</label>
                    <input type="text" name="onedrive_client_id" value="<?php echo htmlspecialchars($settings['onedrive_client_id'] ?? ''); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">

                    <label>CLIENT SECRET</label>
                    <input type="password" name="onedrive_client_secret" placeholder="<?php echo !empty($settings['onedrive_client_secret']) ? '••••••••••••••••' : 'client secret'; ?>">
                    <span class="dim">Leave blank to keep existing secret.</span>

                    <details style="margin-top: 15px;">
                        <summary class="dim" style="cursor: pointer;">SETUP GUIDE</summary>
                        <div style="padding: 10px 0; font-size: 12px; line-height: 1.6;">
                            1. Go to <strong>portal.azure.com → App registrations</strong><br>
                            2. Click <strong>New registration</strong><br>
                            3. Name: <strong>SnapSmack Backup</strong><br>
                            4. Supported account types: <strong>Personal Microsoft accounts</strong><br>
                            5. Redirect URI (Web):<br>
                            <code style="font-size: 11px; background: rgba(0,0,0,0.05); padding: 2px 6px;"><?php echo htmlspecialchars($siteUrl); ?>/smack-cloud.php?action=oauth_callback&provider=onedrive</code><br>
                            6. Go to <strong>Certificates & secrets → New client secret</strong><br>
                            7. Under <strong>API permissions</strong>, add <strong>Files.ReadWrite</strong>
                        </div>
                    </details>
                </div>
            </div>

            <button type="submit" name="save_cloud_settings" class="btn-smack" style="margin-top: 20px;">SAVE CLOUD CONFIGURATION</button>
        </form>
    </div>

    <!-- ================================================================
         AUTHORIZATION STATUS
         ================================================================ -->
    <div class="box">
        <h3>AUTHORIZATION STATUS</h3>
        <p class="dim">Authorize once — your refresh token is stored encrypted so you can push anytime without re-authenticating.</p>

        <div class="post-layout-grid">
            <div class="post-col-left">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: <?php echo $googleReady ? '#4CAF50' : '#999'; ?>;"></span>
                    <strong>GOOGLE DRIVE: <?php echo $googleLinked ? 'LINKED' : ($googleReady ? 'ACTIVE' : 'NOT LINKED'); ?></strong>
                </div>

                <?php if ($googleConfigured && !$googleReady): ?>
                    <?php
                    $googleCreds = SnapSmackCloudOAuth::getStoredCredentials('google', $settings, $salt);
                    $googleRedirect = $siteUrl . '/smack-cloud.php?action=oauth_callback&provider=google';
                    $googleAuthUrl = SnapSmackCloudOAuth::getAuthorizationUrl('google', $googleCreds['client_id'], $googleRedirect);
                    ?>
                    <a href="<?php echo htmlspecialchars($googleAuthUrl); ?>" class="btn-smack">LINK GOOGLE DRIVE</a>
                <?php elseif ($googleReady): ?>
                    <a href="smack-cloud.php?action=disconnect&provider=google" class="btn-smack" onclick="return confirm('Unlink Google Drive? You will need to re-authorize.');">UNLINK</a>
                <?php else: ?>
                    <span class="dim">Configure credentials above first.</span>
                <?php endif; ?>
            </div>

            <div class="post-col-right">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: <?php echo $onedriveReady ? '#4CAF50' : '#999'; ?>;"></span>
                    <strong>ONEDRIVE: <?php echo $onedriveLinked ? 'LINKED' : ($onedriveReady ? 'ACTIVE' : 'NOT LINKED'); ?></strong>
                </div>

                <?php if ($onedriveConfigured && !$onedriveReady): ?>
                    <?php
                    $onedriveCreds = SnapSmackCloudOAuth::getStoredCredentials('onedrive', $settings, $salt);
                    $onedriveRedirect = $siteUrl . '/smack-cloud.php?action=oauth_callback&provider=onedrive';
                    $onedriveAuthUrl = SnapSmackCloudOAuth::getAuthorizationUrl('onedrive', $onedriveCreds['client_id'], $onedriveRedirect);
                    ?>
                    <a href="<?php echo htmlspecialchars($onedriveAuthUrl); ?>" class="btn-smack">LINK ONEDRIVE</a>
                <?php elseif ($onedriveReady): ?>
                    <a href="smack-cloud.php?action=disconnect&provider=onedrive" class="btn-smack" onclick="return confirm('Unlink OneDrive? You will need to re-authorize.');">UNLINK</a>
                <?php else: ?>
                    <span class="dim">Configure credentials above first.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ================================================================
         PUSH TO CLOUD
         ================================================================ -->
    <div class="box">
        <h3>PUSH TO CLOUD</h3>
        <p class="dim">Select a provider and export type to push. You must be connected to a provider first.</p>
    </div>

    <div class="dash-grid dash-grid-2">
        <div class="box box-flex">
            <h4 style="margin-top: 0;">GOOGLE DRIVE</h4>
            <?php if ($googleReady): ?>
                <button type="button" class="btn-smack btn-block push-cloud-btn" data-provider="google" data-type="recovery_kit">PUSH RECOVERY KIT</button>
                <button type="button" class="btn-smack btn-block push-cloud-btn" data-provider="google" data-type="wxr" style="margin-top: 8px;">PUSH WORDPRESS WXR</button>
                <button type="button" class="btn-smack btn-block push-cloud-btn" data-provider="google" data-type="json" style="margin-top: 8px;">PUSH PORTABLE JSON</button>
            <?php else: ?>
                <p class="dim"><?php echo $googleConfigured ? 'Link Google Drive above to enable push.' : 'Configure credentials above first.'; ?></p>
                <button type="button" class="btn-smack btn-block" disabled>PUSH RECOVERY KIT</button>
                <button type="button" class="btn-smack btn-block" disabled style="margin-top: 8px;">PUSH WORDPRESS WXR</button>
                <button type="button" class="btn-smack btn-block" disabled style="margin-top: 8px;">PUSH PORTABLE JSON</button>
            <?php endif; ?>
        </div>

        <div class="box box-flex">
            <h4 style="margin-top: 0;">ONEDRIVE</h4>
            <?php if ($onedriveReady): ?>
                <button type="button" class="btn-smack btn-block push-cloud-btn" data-provider="onedrive" data-type="recovery_kit">PUSH RECOVERY KIT</button>
                <button type="button" class="btn-smack btn-block push-cloud-btn" data-provider="onedrive" data-type="wxr" style="margin-top: 8px;">PUSH WORDPRESS WXR</button>
                <button type="button" class="btn-smack btn-block push-cloud-btn" data-provider="onedrive" data-type="json" style="margin-top: 8px;">PUSH PORTABLE JSON</button>
            <?php else: ?>
                <p class="dim"><?php echo $onedriveConfigured ? 'Link OneDrive above to enable push.' : 'Configure credentials above first.'; ?></p>
                <button type="button" class="btn-smack btn-block" disabled>PUSH RECOVERY KIT</button>
                <button type="button" class="btn-smack btn-block" disabled style="margin-top: 8px;">PUSH WORDPRESS WXR</button>
                <button type="button" class="btn-smack btn-block" disabled style="margin-top: 8px;">PUSH PORTABLE JSON</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Progress output -->
    <div id="cloud-push-progress" style="margin-top: 20px;"></div>

    <!-- ================================================================
         LAST PUSH STATUS
         ================================================================ -->
    <div class="box mt-30">
        <h3>LAST PUSH STATUS</h3>

        <?php
        $lastPush   = $settings['cloud_last_push'] ?? null;
        $lastStatus = $settings['cloud_last_status'] ?? null;
        ?>

        <?php if ($lastPush): ?>
            <div style="margin-bottom: 10px;">
                <strong>Timestamp:</strong> <?php echo htmlspecialchars($lastPush); ?>
            </div>
            <div>
                <strong>Status:</strong> <?php echo htmlspecialchars($lastStatus ?? 'Unknown'); ?>
            </div>
        <?php else: ?>
            <p class="dim">NO PUSH HISTORY</p>
        <?php endif; ?>
    </div>
</div>

<script>
// --- PUSH TO CLOUD (streaming progress) ---
document.querySelectorAll('.push-cloud-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var provider = this.dataset.provider;
        var type = this.dataset.type;
        var progressDiv = document.getElementById('cloud-push-progress');

        // Disable all push buttons during operation
        document.querySelectorAll('.push-cloud-btn').forEach(function(b) { b.disabled = true; });

        progressDiv.innerHTML = '<pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; font-size: 12px; overflow-x: auto; max-height: 400px; line-height: 1.5;"></pre>';
        var preEl = progressDiv.querySelector('pre');

        var url = 'smack-cloud.php?action=push_now&provider=' + encodeURIComponent(provider) + '&type=' + encodeURIComponent(type);

        fetch(url)
            .then(function(response) { return response.body.getReader(); })
            .then(function(reader) {
                var decoder = new TextDecoder();

                return reader.read().then(function process(result) {
                    if (result.done) return;

                    var chunk = decoder.decode(result.value, {stream: true});
                    preEl.textContent += chunk;

                    // Auto-scroll to bottom
                    preEl.parentElement.scrollTop = preEl.parentElement.scrollHeight;

                    return reader.read().then(process);
                });
            })
            .catch(function(e) {
                preEl.textContent += '\nERROR: ' + e.message;
            })
            .finally(function() {
                // Re-enable all push buttons
                document.querySelectorAll('.push-cloud-btn').forEach(function(b) { b.disabled = false; });
            });
    });
});
</script>

<?php include 'core/admin-footer.php'; ?>
