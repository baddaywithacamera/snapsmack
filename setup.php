<?php
/**
 * SNAPSMACK - Bootstrap Deployer
 * Alpha v0.7.4
 *
 * Upload this single file to an empty directory on your server and open it
 * in a browser. It pulls the SnapSmack codebase from GitHub (via Git or zip
 * download), then hands off to install.php for database setup.
 *
 * Requirements: PHP 8.0+, and either Git or allow_url_fopen / cURL.
 * This file self-deletes after a successful deploy.
 */

// --- CONFIGURATION ---
$repo_url     = 'https://github.com/baddaywithacamera/snapsmack.git';
$zip_url      = 'https://github.com/baddaywithacamera/snapsmack/archive/refs/heads/master.zip';
$branch       = 'master';
$target_dir   = __DIR__;

// --- SAFETY CHECK ---
// If install.php already exists, the codebase is already here.
if (file_exists($target_dir . '/install.php') && file_exists($target_dir . '/core/parser.php')) {
    header('Location: install.php');
    exit;
}

// --- DETECT CAPABILITIES ---
$has_git   = false;
$has_curl  = function_exists('curl_init');
$has_fopen = ini_get('allow_url_fopen');

// Check for Git binary
$git_path = trim(shell_exec('which git 2>/dev/null') ?? '');
if (!empty($git_path) && file_exists($git_path)) {
    $has_git = true;
}

$method  = '';
$error   = '';
$success = false;

// --- HANDLE DEPLOY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deploy'])) {

    $chosen = $_POST['method'] ?? 'auto';

    // --- METHOD 1: GIT CLONE ---
    if (($chosen === 'git' || $chosen === 'auto') && $has_git) {
        $method = 'git';

        // Clone into a temp directory first, then move files into place.
        // This avoids Git complaining about a non-empty directory (setup.php is here).
        $tmp_dir = $target_dir . '/.snapsmack-deploy-tmp';

        // Clean up any leftover temp dir from a failed attempt
        if (is_dir($tmp_dir)) {
            shell_exec("rm -rf " . escapeshellarg($tmp_dir));
        }

        $cmd = sprintf(
            'git clone --depth 1 --branch %s %s %s 2>&1',
            escapeshellarg($branch),
            escapeshellarg($repo_url),
            escapeshellarg($tmp_dir)
        );
        $output = shell_exec($cmd);

        if (is_dir($tmp_dir . '/core')) {
            // Move everything from temp into the target directory.
            // The .git folder comes along — that's fine for future updates.
            $items = array_diff(scandir($tmp_dir), ['.', '..']);
            $move_failed = false;
            foreach ($items as $item) {
                $src = $tmp_dir . '/' . $item;
                $dst = $target_dir . '/' . $item;
                if (!rename($src, $dst)) {
                    $move_failed = true;
                    $error = "Git clone succeeded but failed to move files into place. Check directory permissions.";
                    break;
                }
            }

            // Clean up the (now empty) temp dir
            @rmdir($tmp_dir);

            if (!$move_failed) {
                // Remove db.php if it came from the repo — install.php will generate it
                @unlink($target_dir . '/core/db.php');
                $success = true;
            }
        } else {
            $error = "Git clone failed. Output:\n" . ($output ?? 'No output captured.');
        }
    }

    // --- METHOD 2: ZIP DOWNLOAD ---
    if (!$success && ($chosen === 'zip' || $chosen === 'auto') && ($has_curl || $has_fopen)) {
        $method = 'zip';
        $zip_file = $target_dir . '/snapsmack-download.zip';

        // Download the zip
        $zip_data = false;
        if ($has_curl) {
            $ch = curl_init($zip_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $zip_data = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http_code !== 200) $zip_data = false;
        } elseif ($has_fopen) {
            $zip_data = @file_get_contents($zip_url);
        }

        if ($zip_data === false || strlen($zip_data) < 1000) {
            $error = "Failed to download the release zip from GitHub. Check that outbound HTTPS is allowed on this server.";
        } else {
            file_put_contents($zip_file, $zip_data);

            // Extract
            $zip = new ZipArchive();
            if ($zip->open($zip_file) === true) {
                // GitHub zips contain a top-level folder like "snapsmack-master/"
                // We need to extract its contents into the target directory.
                $zip->extractTo($target_dir);
                $zip->close();

                // Find the extracted folder
                $extracted_dir = null;
                foreach (scandir($target_dir) as $item) {
                    if (strpos($item, 'snapsmack-') === 0 && is_dir($target_dir . '/' . $item)) {
                        $extracted_dir = $target_dir . '/' . $item;
                        break;
                    }
                }

                if ($extracted_dir && is_dir($extracted_dir . '/core')) {
                    // Recursively merge source into destination.
                    // Plain rename() fails when the target directory already exists,
                    // so we walk the tree: files overwrite, directories recurse.
                    $merge_dirs = function (string $src, string $dst) use (&$merge_dirs): void {
                        if (!is_dir($dst)) { @mkdir($dst, 0755, true); }
                        $items = array_diff(scandir($src), ['.', '..']);
                        foreach ($items as $item) {
                            $s = $src . '/' . $item;
                            $d = $dst . '/' . $item;
                            if (is_dir($s)) {
                                $merge_dirs($s, $d);
                            } else {
                                rename($s, $d);
                            }
                        }
                        @rmdir($src);
                    };
                    $merge_dirs($extracted_dir, $target_dir);

                    // Remove db.php if it came from the repo
                    @unlink($target_dir . '/core/db.php');
                    $success = true;
                } else {
                    $error = "Zip extracted but the expected folder structure was not found.";
                }
            } else {
                $error = "Failed to open the downloaded zip file. It may be corrupted.";
            }

            // Clean up zip file
            @unlink($zip_file);
        }
    }

    // --- NO METHOD AVAILABLE ---
    if (!$success && empty($error)) {
        $error = "No deployment method available. This server has no Git, no cURL, and allow_url_fopen is disabled. You'll need to upload the SnapSmack files manually.";
    }

    // --- SUCCESS: REDIRECT TO INSTALLER ---
    if ($success) {
        // Self-delete
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
        .method-note {
            margin-top: 12px;
            font-size: 0.8rem;
            color: #555;
        }
    </style>
</head>
<body>
<div class="setup">

    <h1>SNAPSMACK <span>SETUP</span></h1>
    <p class="subtitle">Codebase Deployer</p>

    <?php if (!empty($error)): ?>
        <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <h2>Deploy SnapSmack to this server</h2>

    <p>This script will pull the SnapSmack codebase from GitHub into this directory, then hand off to the installer for database setup.</p>

    <ul class="method-list">
        <li>
            <span>Git</span>
            <span class="<?php echo $has_git ? 'pass' : 'fail'; ?>">
                <?php echo $has_git ? 'AVAILABLE' : 'NOT FOUND'; ?>
            </span>
        </li>
        <li>
            <span>cURL</span>
            <span class="<?php echo $has_curl ? 'pass' : 'fail'; ?>">
                <?php echo $has_curl ? 'AVAILABLE' : 'NOT FOUND'; ?>
            </span>
        </li>
        <li>
            <span>allow_url_fopen</span>
            <span class="<?php echo $has_fopen ? 'pass' : 'fail'; ?>">
                <?php echo $has_fopen ? 'ENABLED' : 'DISABLED'; ?>
            </span>
        </li>
    </ul>

    <?php if ($has_git || $has_curl || $has_fopen): ?>
        <form method="post">
            <input type="hidden" name="deploy" value="1">
            <input type="hidden" name="method" value="auto">
            <button type="submit">Deploy SnapSmack</button>
        </form>

        <p class="method-note">
            <?php if ($has_git): ?>
                Will use Git clone (preferred).
            <?php else: ?>
                Will download zip from GitHub.
            <?php endif; ?>
        </p>

    <?php else: ?>
        <p>No automatic deployment method is available on this server. Upload the files manually:</p>
    <?php endif; ?>

    <div class="or-divider">— or deploy manually —</div>

    <p>SSH into your server and run:</p>
    <div class="manual-box">cd <?php echo htmlspecialchars($target_dir); ?> && git clone <?php echo htmlspecialchars($repo_url); ?> .</div>

    <p>Then open <strong>install.php</strong> in your browser to continue setup.</p>

</div>
</body>
</html>
