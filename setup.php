<?php
/**
 * SNAPSMACK - Bootstrap Deployer
 *
 * Upload this single file to an empty directory on your server and open it
 * in a browser. It fetches the latest signed release from snapsmack.ca,
 * verifies the SHA-256 checksum and Ed25519 signature, then hands off to
 * install.php for database setup.
 *
 * Requirements: PHP 8.3+, cURL or allow_url_fopen, ZipArchive, sodium.
 * sodium is REQUIRED, not preferred: without it the Ed25519 signature
 * cannot be checked, and this deployer refuses rather than installing a
 * package it cannot prove came from us. Manual installation still works.
 * This file self-deletes after a successful deploy.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// --- CONFIGURATION ---
//
// SS-1075. This was one hardcoded line pointing at the stable manifest, so the
// documented one-file path -- upload this file, open it in a browser -- could
// only ever produce a stable site, and the current stable build is one the
// strict security model refuses on all thirty skins.
//
// THE DEFAULT IS DELIBERATELY STILL STABLE. The FEDISTRUCTURE bootstrap
// defaults to dev; copying that here would change what every public installer
// user receives without anybody deciding to. That is a product decision, not a
// repair, so this adds the switch and nothing else: ?track=dev opts in.
$setup_track = (($_GET['track'] ?? '') === 'dev') ? 'dev' : 'stable';
$api_url     = $setup_track === 'dev'
    ? 'https://snapsmack.ca/releases/latest-dev.json'
    : 'https://snapsmack.ca/releases/latest.json';
$target_dir  = __DIR__;

// A floor, so the dev switch cannot be aimed at an ancient D tag and quietly
// install it. Same guard the FEDISTRUCTURE bootstrap carries.
define('SETUP_MINIMUM_DEV_VERSION', '0.7.899D');

// The release host refuses a request that does not say who it is: measured on
// 30 October, the public feed answered 403 to a default client and 200 to a
// named one. Without this the one-file path would die at the first fetch with
// a message the operator cannot act on.
define('SETUP_USER_AGENT', 'SnapSmack-Setup/1');

// Ed25519 public key — must match core/release-pubkey.php in the release package.
// If the installed key ever differs, smack-update.php has a repair tool.
define('SETUP_RELEASE_PUBKEY', 'b0cbadef25a6aca5292e5c31b29dededb3f710f1d57908ba3c83a5e641f53bc2');

// --- PHP VERSION GATE ---
// The docblock at the top of this file has claimed "Requirements: PHP 8.3+"
// since it was written, and nothing checked it. An owner on an older PHP got a
// successful-looking deploy and a site that could not run. Refuse, and say the
// number, so the message they take to their host is a specific one.
//
// Everything in this file is deliberately kept to PHP 7-compatible syntax so
// that this check can RUN on the boxes it exists to turn away. A file using
// newer syntax fails to parse before any line of it executes, and the owner
// sees a blank white page -- which teaches them nothing and looks like our bug.
// Before adding anything modern here, check it parses on PHP 7.
define('SETUP_PHP_MINIMUM', '8.3.0');
if (version_compare(PHP_VERSION, SETUP_PHP_MINIMUM, '<')) {
    http_response_code(500);
    die('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>SnapSmack</title>'
      . '<style>*{margin:0;padding:0;box-sizing:border-box}body{background:#0e0e0e;color:#d0d0d0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:15px;line-height:1.6;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px 20px}.wrap{width:100%;max-width:560px}h1{font-size:1.4rem;color:#a0ff90;letter-spacing:2px;text-transform:uppercase;margin-bottom:4px}h1 span{color:#555;font-weight:300}.sub{color:#555;font-size:.8rem;letter-spacing:1px;margin-bottom:32px}p{color:#999;margin-bottom:20px}strong{color:#d0d0d0}a{color:#a0ff90}</style>'
      . '</head><body><div class="wrap">'
      . '<h1>SNAPSMACK <span>SETUP</span></h1><p class="sub">Cannot install on this server</p>'
      . '<p>SnapSmack needs <strong>PHP ' . SETUP_PHP_MINIMUM . '</strong> or newer. '
      . 'This server is running <strong>PHP ' . PHP_VERSION . '</strong>.</p>'
      . '<p>Nothing has been installed and nothing has been changed. Ask your host to '
      . 'move this site to a newer PHP version, then open this page again. Most hosts '
      . 'have a PHP version switch in their control panel.</p>'
      . '</div></body></html>');
}

// --- SAFETY CHECK (SECAUDIT 2026-08-15: installer kill-switch) ---
// setup.php only deploys code into an EMPTY directory. If SnapSmack is already
// present — the code (core/parser.php) OR a live DB config (core/db.php) — refuse
// outright. Never redeploy or hand off to the installer on a live site: that path
// could resurrect a fresh, unauthenticated install.php. The old check required
// BOTH install.php AND parser.php, so deleting install.php re-armed the deployer.
// Real recovery is admin-only via the Disaster page (login required).
if (is_dir($target_dir . '/core')
    && (file_exists($target_dir . '/core/parser.php') || file_exists($target_dir . '/core/db.php'))) {
    http_response_code(403);
    die('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>SnapSmack</title>'
      . '<style>*{margin:0;padding:0;box-sizing:border-box}body{background:#0e0e0e;color:#d0d0d0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:15px;line-height:1.6;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px 20px}.wrap{width:100%;max-width:560px}h1{font-size:1.4rem;color:#a0ff90;letter-spacing:2px;text-transform:uppercase;margin-bottom:4px}h1 span{color:#555;font-weight:300}.sub{color:#555;font-size:.8rem;letter-spacing:1px;margin-bottom:32px}p{color:#999;margin-bottom:20px}a{color:#a0ff90}.btn{display:inline-block;padding:10px 24px;background:#1a1a1a;color:#a0ff90;border:1px solid #333;font-size:.88rem;font-weight:600;text-transform:uppercase;letter-spacing:1px;cursor:pointer;border-radius:3px;text-decoration:none;margin-right:12px;margin-bottom:10px}.btn:hover{border-color:#a0ff90}</style>'
      . '</head><body><div class="wrap">'
      . '<h1>SNAPSMACK <span>SETUP</span></h1><p class="sub">Locked — already deployed</p>'
      . '<p>SnapSmack is already installed on this server. The bootstrap deployer will not run on a live site.</p>'
      . '<p>To recover a broken site, log in and use the <strong>Disaster</strong> page in the admin (Boring Ass Stuff &rarr; Disaster). You can safely delete setup.php and install.php.</p>'
      . '</div></body></html>');
}

// --- DETECT CAPABILITIES ---
/**
 * Is the sodium extension here?
 *
 * A function rather than function_exists() at each call site, for one reason:
 * without it the refusal below cannot be exercised. Every machine this is
 * written and tested on HAS sodium, so the branch that decides what happens
 * on a host without it was unreachable from a test and went unverified for
 * the whole life of this file. One seam, and the refusal can be run.
 */
function setup_has_sodium(): bool {
    return function_exists('sodium_crypto_sign_verify_detached');
}

$has_curl   = function_exists('curl_init');
$has_fopen  = ini_get('allow_url_fopen');
$has_zip    = class_exists('ZipArchive');
$has_sodium = setup_has_sodium();

$error   = '';
$success = false;
$release = null;

// --- FETCH RELEASE MANIFEST ---
/** @return string|false  Return type left off: see the PHP VERSION GATE note above. */
function setup_fetch_url($url) {
    global $has_curl, $has_fopen;
    if ($has_curl) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');  // decompress any Content-Encoding so we hash raw bytes
        curl_setopt($ch, CURLOPT_USERAGENT, SETUP_USER_AGENT);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200 && $data !== false) ? $data : false;
    }
    if ($has_fopen) {
        // The fallback has to identify itself too, or a host without cURL is
        // refused for a reason nobody can see. PHP otherwise sends its own
        // default agent, which the release host does not accept.
        $context = stream_context_create(['http' => [
            'user_agent' => SETUP_USER_AGENT,
            'timeout'    => 30,
            'follow_location' => 1,
        ]]);
        return @file_get_contents($url, false, $context);
    }
    return false;
}

// --- DEV FEED FLOOR ---
/**
 * Is the development feed too old to install from?
 *
 * SS-1075. A named function rather than four lines inside the deploy handler,
 * so the rule can be CALLED and proved. The first version of this was inline
 * and a check that only looked for the words "version_compare" in the file
 * stayed green when the comparison was replaced with if(false) -- which is
 * exactly the kind of guard that rots without anyone noticing.
 *
 * No version, or anything below the floor, is stale. The trailing D is
 * stripped from both sides before comparing, because version_compare does not
 * understand it.
 */
function setup_dev_feed_is_stale(string $manifest_version): bool {
    $manifest_numeric = (string)preg_replace('/D$/i', '', trim($manifest_version));
    $minimum_numeric  = (string)preg_replace('/D$/i', '', SETUP_MINIMUM_DEV_VERSION);
    if ($manifest_numeric === '') return true;
    return version_compare($manifest_numeric, $minimum_numeric, '<');
}

// --- VERIFY PACKAGE ---
/** @return string|true  Error message, or true. Return type left off: see the PHP VERSION GATE note. */
function setup_verify(string $file_path, string $expected_sha256, string $signature_hex) {
    $actual = hash_file('sha256', $file_path);
    if ($actual === false) {
        return 'Could not read downloaded file for checksum — check directory permissions.';
    }
    if (!hash_equals($expected_sha256, $actual)) {
        return 'SHA-256 checksum mismatch — the download may be corrupt or tampered with.';
    }

    // The checksum passing is not verification. The expected hash arrives in
    // the release manifest, over the network, from the same host as the
    // download: anyone able to serve a tampered package can serve the hash
    // that matches it, and the comparison above then passes. The Ed25519
    // signature is the only part of this that proves the package came from
    // whoever holds the release key, because that public key is pinned in
    // this file and does not travel with the manifest.
    //
    // So a missing sodium extension does not weaken verification slightly --
    // it removes it. This used to return true here, which installed an
    // unverified package and said nothing. The deployer's whole job is to put
    // unknown code from the network onto a server; if it cannot prove where
    // that code came from, it stops.
    if (!setup_has_sodium()) {
        return 'This server has no sodium PHP extension, so the Ed25519 '
             . 'signature on this package cannot be checked. The SHA-256 '
             . 'checksum matched, but it arrives from the same place as the '
             . 'download and so proves nothing on its own. Nothing has been '
             . 'installed. Ask your host to enable the sodium extension, or '
             . 'install the release by hand from snapsmack.ca/releases.';
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
    } elseif (!setup_has_sodium()) {
        // Checked here as well as in setup_verify, so a host that cannot
        // verify a package never downloads one in the first place.
        $error = 'This server has no sodium PHP extension, so a downloaded release cannot have its Ed25519 signature verified — and SnapSmack will not install code it cannot prove came from us. Nothing has been downloaded. Ask your host to enable the sodium extension, or install by hand from snapsmack.ca/releases.';
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
            // SS-1075. A floor on the dev feed, so the switch cannot be aimed
            // at an ancient D tag and quietly install it. Checked BEFORE
            // anything is downloaded, so a stale feed costs nothing but a
            // message. Stable needs no floor: it is whatever was published.
            if (!$error && $setup_track === 'dev') {
                $manifest_version = (string)($release['version'] ?? '');
                if (setup_dev_feed_is_stale($manifest_version)) {
                    $error = 'The development release feed is stale. Required '
                        . SETUP_MINIMUM_DEV_VERSION . ' or newer; received '
                        . ($manifest_version !== '' ? htmlspecialchars($manifest_version) : 'no version')
                        . '. Nothing has been installed.';
                }
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

    <?php /* SS-1075. Say which channel this is about to install, before the
    button rather than after the fact. The FEDISTRUCTURE bootstrap does the
    same, and it is the only way an operator can tell that ?track=dev took
    effect -- or that it did not. */ ?>
    <p class="subtitle">Channel:
        <strong><?php echo $setup_track === 'dev' ? 'DEV' : 'STABLE'; ?></strong>
        <?php echo $setup_track === 'dev'
            ? '&mdash; current development build'
            : '&mdash; published stable release'; ?></p>

    <?php if (!empty($error)): ?>
        <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <h2>Deploy SnapSmack to this server</h2>

    <p>This script fetches the latest signed release from snapsmack.ca, verifies its checksum and signature, and installs it here. Once complete it hands off to the installer for database setup.</p>

    <ul class="method-list">
        <li>
            <span>PHP <?php echo htmlspecialchars(PHP_VERSION); ?></span>
            <span class="pass">OK</span>
        </li>
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
            <span class="<?php echo $has_sodium ? 'pass' : 'fail'; ?>"><?php echo $has_sodium ? 'AVAILABLE' : 'REQUIRED — NOT FOUND'; ?></span>
        </li>
    </ul>

    <p class="method-note">SnapSmack needs PHP <?php echo htmlspecialchars(SETUP_PHP_MINIMUM); ?> or newer,
        and this server is new enough. What we cannot check is whether your PHP has its latest
        <strong>security</strong> patches &mdash; those arrive as updates to the last number in the version,
        and your host controls them. Please confirm with your host that this server is on the
        current patch release of its PHP version. Out-of-date PHP is the most common way a
        self-hosted site gets broken into.</p>

    <?php if (!$has_sodium): ?>
        <div class="error-box">Sodium extension not found, so automatic installation is switched off. SnapSmack will not install a release whose signature it cannot check: the SHA-256 checksum arrives from the same place as the download, so on its own it proves nothing, and the Ed25519 signature is the part that proves where the package came from. Ask your host to enable the sodium PHP extension &mdash; or install by hand from snapsmack.ca/releases, below.</div>
    <?php endif; ?>

    <?php if (($has_curl || $has_fopen) && $has_zip && $has_sodium): ?>
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
<?php // ===== SNAPSMACK EOF =====
