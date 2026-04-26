<?php
/**
 * SNAPSMACK - Bootstrap Deployer
 *
 * Upload this single file to an empty directory on your server and open it
 * in a browser. It fetches the latest signed release from snapsmack.ca,
 * verifies the SHA-256 checksum and Ed25519 signature, then hands off to
 * install.php for database setup.
 *
 * Requirements: PHP 8.0+, cURL or allow_url_fopen, ZipArchive, sodium.
 * This file self-deletes after a successful deploy.
 */

// --- CONFIGURATION ---
$api_url    = 'https://snapsmack.ca/releases/latest.json';
$target_dir = __DIR__;

// Ed25519 public key — must match core/release-pubkey.php in the release package.
// If the installed key ever differs, smack-update.php has a repair tool.
define('SETUP_RELEASE_PUBKEY', '938cb27f4230122dc22bc70decac66a09c20ad5f8db5748d0f443a57b18470d7');

// --- SAFETY CHECK ---
if (file_exists($target_dir . '/install.php') && file_exists($target_dir . '/core/parser.php')) {
    header('Location: install.php');
    exit;
}

// --- DETECT CAPABILITIES ---
$has_curl   = function_exists('curl_init');
$has_fopen  = ini_get('allow_url_fopen');
$has_zip    = class_exists('ZipArchive');
$has_sodium = function_exists('sodium_crypto_sign_verify_detached');

$error   = '';
$success = false;
$release = null;

// --- FETCH RELEASE MANIFEST ---
function setup_fetch_url(string $url): string|false {
    global $has_curl, $has_fopen;
    if ($has_curl) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200 && $data !== false) ? $data : false;
    }
    if ($has_fopen) {
        return @file_get_contents($url);
    }
    return false;
}

// --- VERIFY PACKAGE ---
function setup_verify(string $file_path, string $expected_sha256, string $signature_hex): string|true {
    $actual = hash_file('sha256', $file_path);
    if ($actual === false) {
        return 'Could not read downloaded file for checksum — check directory permissions.';
    }
    if (!hash_equals($expected_sha256, $actual)) {
        return 'SHA-256 checksum mismatch — the download may be corrupt or tampered with.';
    }

    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        return true; // sodium unavailable — checksum passed, skip signature
    }

    if (empty($signature_hex) || strlen($signature_hex) !== 128) {
        return 'Signature missing or malformed in release manifest.';
    }

    try {
        $sig    = sodium_hex2bin($signature_hex);
        $pubkey = sodium_hex2bin(SETUP_RELEASE_PUBKEY);
        $ok     = sodium_crypto_sign_verify_detached($sig, $expected_sha256, $pubkey);
        if (!$ok) {
            return 'Ed25519 signature verification failed — this package cannot be trusted.';
        }
    } catch (Exception $e) {
        return 'Signature check error: ' . $e->getMessage();
    }

    return true;
}

// --- HANDLE DEPLOY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deploy'])) {

    if (!$has_zip) {
        $error = 'ZipArchive is not available on this server. Contact your host to enable the zip PHP extension.';
    } elseif (!$has_curl && !$has_fopen) {
        $error = 'No HTTP fetch method available. This server has no cURL and allow_url_fopen is disabled.';
    } else {

        // Step 1: Fetch release manifest
        $manifest_raw = setup_fetch_url($api_url);
        if (!$manifest_raw) {
            $error = 'Could not fetch release manifest from snapsmack.ca. Check that outbound HTTPS is allowed on this server.';
        } else {
            $release = json_decode($manifest_raw, true);
            if (empty($release['download_url']) || empty($release['checksum_sha256'])) {
                $error = 'Release manifest is missing required fields. Try again or contact support.';
            }
        }

        // Step 2: Download release zip
        if (!$error) {
            $dl_url = $release['download_url'] ?? '';
            if (!preg_match('#^https://#i', $dl_url)) {
                $error = 'Release manifest contains an invalid or non-HTTPS download URL. Aborting for safety.';
            }
        }
        if (!$error) {
            $zip_file = $target_dir . '/snapsmack-install.zip';
            $zip_data = setup_fetch_url($release['download_url']);
            if (!$zip_data || strlen($zip_data) < 10000) {
                $error = 'Failed to download release package from ' . htmlspecialchars($release['download_url']) . '.';
            } else {
                $written = file_put_contents($zip_file, $zip_data);
                if ($written === false) {
                    $error = 'Could not write release package to disk — check that the web server has write permission to ' . htmlspecialchars($target_dir) . '.';
                }
            }
        }

        // Step 3: Verify checksum + signature
        if (!$error) {
            $verify = setup_verify($zip_file, $release['checksum_sha256'], $release['signature'] ?? '');
            if ($verify !== true) {
                @unlink($zip_file);
                $error = $verify;
            }
        }

        // Step 4: Extract — validate every entry for path traversal before touching disk
        if (!$error) {
            $zip = new ZipArchive();
            if ($zip->open($zip_file) !== true) {
                $error = 'Failed to open the downloaded zip file. It may be corrupt.';
            } else {
                $real_target = realpath($target_dir) . DIRECTORY_SEPARATOR;
                $safe = true;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    $dest  = realpath($target_dir) . DIRECTORY_SEPARATOR . $entry;
                    // Reject any entry that would land outside the target directory
                    if (strpos($dest, $real_target) !== 0) {
                        $safe  = false;
                        $error = 'Package failed security check: path traversal detected in zip entry "' . htmlspecialchars($entry) . '". Installation aborted.';
                        break;
                    }
                }

                if ($safe) {
                    // Extract file by file so we can skip setup.php itself —
                    // PHP cannot overwrite a running script, and a single
                    // extractTo() call stops on the first permission error,
                    // leaving everything alphabetically after it (skins/ etc.)
                    // unextracted.
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $entry = $zip->getNameIndex($i);
                        if ($entry === 'setup.php') continue; // skip self
                        if (str_ends_with($entry, '/')) {
                            @mkdir($target_dir . '/' . $entry, 0775, true);
                            continue;
                        }
                        $content = $zip->getFromIndex($i);
                        if ($content !== false) {
                            @mkdir(dirname($target_dir . '/' . $entry), 0775, true);
                            @file_put_contents($target_dir . '/' . $entry, $content);
                        }
                    }
                }
                $zip->close();
                @unlink($zip_file);

                if ($safe) {
                    if (!file_exists($target_dir . '/core/parser.php')) {
                        $error = 'Zip extracted but expected files were not found. The package may be malformed.';
                    } else {
                        // Remove db.php — install.php generates it fresh
                        @unlink($target_dir . '/core/db.php');

                        // Set permissions so the FTP user can overwrite any file.
                        // Directories: 775 (rwxrwxr-x), files: 664 (rw-rw-r--)
                        // Requires the FTP user and web server user to share a group.
                        $iter = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($target_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::SELF_FIRST
                        );
                        foreach ($iter as $item) {
                            @chmod($item->getPathname(), $item->isDir() ? 0775 : 0664);
                        }
                        @chmod($target_dir, 0775);

                        $success = true;
                    }
                }
            }
        }
    }

    if ($success) {
        @unlink(__FILE__);
        header('Location: install.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SnapSmack Setup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #0e0e0e;
            color: #d0d0d0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 15px;
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }
        .setup { width: 100%; max-width: 640px; }
        h1 {
            font-size: 1.6rem;
            color: #a0ff90;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        h1 span { color: #555; font-weight: 300; }
        .subtitle { color: #666; font-size: 0.85rem; margin-bottom: 40px; letter-spacing: 1px; }
        h2 { font-size: 1.1rem; color: #eee; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #2a2a2a; }
        p { margin-bottom: 16px; color: #999; }
        .method-list { list-style: none; margin: 20px 0; }
        .method-list li {
            padding: 10px 0;
            border-bottom: 1px solid #1a1a1a;
            display: flex;
            justify-content: space-between;
        }
        .pass { color: #a0ff90; }
        .fail { color: #ff6b6b; }
        .warn { color: #ffcc00; }
        button {
            display: inline-block;
            padding: 12px 30px;
            background: #a0ff90;
            color: #0e0e0e;
            border: none;
            font-size: 0.9rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            border-radius: 3px;
            margin-top: 20px;
        }
        button:hover { background: #c0ffb0; }
        button:disabled { background: #333; color: #666; cursor: not-allowed; }
        .error-box {
            background: #2a0a0a;
            border: 1px solid #ff6b6b;
            color: #ff9999;
            padding: 14px 18px;
            margin-bottom: 20px;
            border-radius: 3px;
            font-size: 0.9rem;
            white-space: pre-wrap;
        }
        .warn-box {
            background: #1a1500;
            border: 1px solid #ffcc00;
            color: #ffdd66;
            padding: 14px 18px;
            margin-bottom: 20px;
            border-radius: 3px;
            font-size: 0.9rem;
        }
        .manual-box {
            background: #1a1a1a;
            border: 1px solid #333;
            padding: 14px 18px;
            margin: 20px 0;
            border-radius: 3px;
            font-family: monospace;
            font-size: 0.85rem;
            color: #a0ff90;
            word-break: break-all;
        }
        .or-divider {
            text-align: center;
            color: #444;
            margin: 24px 0;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .method-note { margin-top: 12px; font-size: 0.8rem; color: #555; }
        .version-note { font-size: 0.8rem; color: #555; margin-top: 6px; }
    </style>
</head>
<body>
<div class="setup">

    <h1>SNAPSMACK <span>SETUP</span></h1>
    <p class="subtitle">Release Deployer</p>

    <?php if (!empty($error)): ?>
        <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <h2>Deploy SnapSmack to this server</h2>

    <p>This script fetches the latest signed release from snapsmack.ca, verifies its checksum and signature, and installs it here. Once complete it hands off to the installer for database setup.</p>

    <ul class="method-list">
        <li>
            <span>cURL</span>
            <span class="<?php echo $has_curl ? 'pass' : 'fail'; ?>"><?php echo $has_curl ? 'AVAILABLE' : 'NOT FOUND'; ?></span>
        </li>
        <li>
            <span>allow_url_fopen</span>
            <span class="<?php echo $has_fopen ? 'pass' : ($has_curl ? 'warn' : 'fail'); ?>"><?php echo $has_fopen ? 'ENABLED' : ($has_curl ? 'NOT NEEDED' : 'DISABLED'); ?></span>
        </li>
        <li>
            <span>ZipArchive</span>
            <span class="<?php echo $has_zip ? 'pass' : 'fail'; ?>"><?php echo $has_zip ? 'AVAILABLE' : 'NOT FOUND'; ?></span>
        </li>
        <li>
            <span>Sodium (signature verification)</span>
            <span class="<?php echo $has_sodium ? 'pass' : 'warn'; ?>"><?php echo $has_sodium ? 'AVAILABLE' : 'NOT FOUND — checksum only'; ?></span>
        </li>
    </ul>

    <?php if (!$has_sodium): ?>
        <div class="warn-box">Sodium extension not found. The release package will be verified by SHA-256 checksum only — Ed25519 signature verification will be skipped. Ask your host to enable the sodium PHP extension for full verification.</div>
    <?php endif; ?>

    <?php if (($has_curl || $has_fopen) && $has_zip): ?>
        <form method="post">
            <input type="hidden" name="deploy" value="1">
            <button type="submit">Install SnapSmack</button>
        </form>
        <p class="method-note">Downloads the latest signed release · verifies before extracting · self-deletes on success.</p>
    <?php else: ?>
        <p>Automatic installation is not available on this server. Download the latest release manually from <strong>snapsmack.ca/releases</strong> and upload the files here, then open <strong>install.php</strong> to continue.</p>
    <?php endif; ?>

    <div class="or-divider">— or install manually —</div>

    <p>Download the latest release zip from <strong>snapsmack.ca/releases</strong>, extract it into this directory, then open <strong>install.php</strong> in your browser.</p>

</div>
</body>
</html>
