<?php
/**
 * SNAPSMACK FEDISTRUCTURE - Bootstrap Deployer
 *
 * Upload this one file to an empty web directory. It fetches only the signed
 * FEDISTRUCTURE release, verifies it, extracts it, and hands off to install.php.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$fedup_track = (($_GET['track'] ?? '') === 'dev') ? 'dev' : 'stable';
$fedup_manifest_url = $fedup_track === 'dev'
    ? 'https://snapsmack.ca/releases/latest-fedistructure-dev.json'
    : 'https://snapsmack.ca/releases/latest-fedistructure.json';
$fedup_target = __DIR__;
define('FEDUP_RELEASE_PUBKEY', 'b0cbadef25a6aca5292e5c31b29dededb3f710f1d57908ba3c83a5e641f53bc2');

if (is_file($fedup_target . '/core/fedistructure-package.php')
    && is_file($fedup_target . '/install.php')) {
    header('Location: install.php');
    exit;
}

$fedup_error = '';
$fedup_has_fetch = function_exists('curl_init') || (bool)ini_get('allow_url_fopen');
$fedup_ready = PHP_VERSION_ID >= 80000
            && class_exists('ZipArchive')
            && function_exists('sodium_crypto_sign_verify_detached')
            && $fedup_has_fetch;

function fedup_fetch(string $url): string|false {
    if (!preg_match('#^https://snapsmack\.ca/#i', $url)) return false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'SnapSmack-FEDUP/1',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $body !== false && $code === 200 ? $body : false;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout' => 45,
            'follow_location' => 1,
            'max_redirects' => 3,
            'user_agent' => 'SnapSmack-FEDUP/1',
        ]]);
        return @file_get_contents($url, false, $ctx);
    }
    return false;
}

function fedup_safe_entry(string $entry): bool {
    if ($entry === '' || str_contains($entry, "\0") || str_contains($entry, '\\')) return false;
    if ($entry[0] === '/' || preg_match('/^[A-Za-z]:/', $entry)) return false;
    foreach (explode('/', $entry) as $part) {
        if ($part === '..') return false;
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deploy']) && $fedup_ready) {
    $raw = fedup_fetch($fedup_manifest_url);
    $manifest = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($manifest)
        || ($manifest['distribution'] ?? '') !== 'fedistructure'
        || empty($manifest['download_url'])
        || empty($manifest['checksum_sha256'])
        || empty($manifest['signature'])) {
        $fedup_error = 'The FEDISTRUCTURE release manifest is missing, invalid, or the wrong distribution.';
    }

    $zip_path = $fedup_target . '/fedistructure-install.zip';
    if ($fedup_error === '') {
        $zip_bytes = fedup_fetch((string)$manifest['download_url']);
        if ($zip_bytes === false || strlen($zip_bytes) < 10000) {
            $fedup_error = 'The signed FEDISTRUCTURE package could not be downloaded.';
        } elseif (file_put_contents($zip_path, $zip_bytes, LOCK_EX) === false) {
            $fedup_error = 'The package could not be written. Check directory permissions.';
        }
        unset($zip_bytes);
    }

    if ($fedup_error === '') {
        $expected = strtolower((string)$manifest['checksum_sha256']);
        $signature = strtolower((string)$manifest['signature']);
        $actual = hash_file('sha256', $zip_path);
        if (!preg_match('/^[0-9a-f]{64}$/', $expected)
            || !is_string($actual)
            || !hash_equals($expected, $actual)) {
            $fedup_error = 'SHA-256 verification failed. Nothing was extracted.';
        } elseif (!preg_match('/^[0-9a-f]{128}$/', $signature)) {
            $fedup_error = 'The release signature is malformed.';
        } else {
            try {
                $valid = sodium_crypto_sign_verify_detached(
                    sodium_hex2bin($signature),
                    $expected,
                    sodium_hex2bin(FEDUP_RELEASE_PUBKEY)
                );
            } catch (Throwable $e) {
                $valid = false;
            }
            if (!$valid) $fedup_error = 'Ed25519 verification failed. Nothing was extracted.';
        }
    }

    if ($fedup_error === '') {
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            $fedup_error = 'The verified package could not be opened.';
        } else {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string)$zip->getNameIndex($i);
                if (!fedup_safe_entry($entry)) {
                    $fedup_error = 'Unsafe ZIP entry rejected: ' . htmlspecialchars($entry, ENT_QUOTES, 'UTF-8');
                    break;
                }
            }
            if ($fedup_error === '') {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = (string)$zip->getNameIndex($i);
                    if ($entry === 'fedup.php') continue;
                    $dest = $fedup_target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
                    if (str_ends_with($entry, '/')) {
                        if (!is_dir($dest) && !@mkdir($dest, 0775, true) && !is_dir($dest)) {
                            $fedup_error = 'Could not create an installation directory.';
                            break;
                        }
                        continue;
                    }
                    $bytes = $zip->getFromIndex($i);
                    if ($bytes === false) {
                        $fedup_error = 'Could not read a verified package entry.';
                        break;
                    }
                    $parent = dirname($dest);
                    if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                        $fedup_error = 'Could not create an installation directory.';
                        break;
                    }
                    if (@file_put_contents($dest, $bytes, LOCK_EX) === false) {
                        $fedup_error = 'Could not extract ' . htmlspecialchars($entry, ENT_QUOTES, 'UTF-8') . '.';
                        break;
                    }
                    @chmod($dest, 0664);
                }
            }
            $zip->close();
        }
    }
    @unlink($zip_path);

    if ($fedup_error === '') {
        if (!is_file($fedup_target . '/core/fedistructure-package.php')
            || !is_file($fedup_target . '/install.php')) {
            $fedup_error = 'Extraction completed without the FEDISTRUCTURE marker or installer.';
        } else {
            @unlink($fedup_target . '/core/db.php');
            @unlink(__FILE__);
            header('Location: install.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>FED UP — FEDISTRUCTURE Installer</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#081220;color:#d8e3ed;font:15px/1.6 system-ui,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}.card{width:min(650px,100%);border:1px solid #16406a;background:#101d29;padding:34px}h1{margin:0;color:#00ffff;letter-spacing:.12em;font-size:1.8rem}h1 span{color:#728293;font-weight:400}p{color:#9badbd}.status{margin:24px 0;padding:14px;border-left:3px solid #00ffff;background:#081522}.error{border-color:#ff4365;color:#ff98aa}.checks{list-style:none;padding:0}.checks li{border-bottom:1px solid #20303d;padding:8px 0;display:flex;justify-content:space-between}.ok{color:#57d163}.bad{color:#ff5a73}button{width:100%;margin-top:20px;padding:14px;border:0;background:#00ffff;color:#06111b;font-weight:800;letter-spacing:.1em;cursor:pointer}code{color:#00ffff}
</style>
</head>
<body><main class="card">
<h1>FED UP <span>/ FEDISTRUCTURE</span></h1>
<p>Installs the signed service distribution for PHOTOFRI.DAY, APHOTOEVERY.DAY, or SMACKCAST.</p>
<?php if ($fedup_error !== ''): ?><div class="status error"><?php echo $fedup_error; ?></div><?php endif; ?>
<ul class="checks">
<li><span>PHP 8+</span><strong class="<?php echo PHP_VERSION_ID >= 80000 ? 'ok' : 'bad'; ?>"><?php echo PHP_VERSION; ?></strong></li>
<li><span>HTTPS download</span><strong class="<?php echo $fedup_has_fetch ? 'ok' : 'bad'; ?>"><?php echo $fedup_has_fetch ? 'READY' : 'MISSING'; ?></strong></li>
<li><span>ZIP extraction</span><strong class="<?php echo class_exists('ZipArchive') ? 'ok' : 'bad'; ?>"><?php echo class_exists('ZipArchive') ? 'READY' : 'MISSING'; ?></strong></li>
<li><span>Ed25519 verification</span><strong class="<?php echo function_exists('sodium_crypto_sign_verify_detached') ? 'ok' : 'bad'; ?>"><?php echo function_exists('sodium_crypto_sign_verify_detached') ? 'READY' : 'MISSING'; ?></strong></li>
</ul>
<?php if ($fedup_ready): ?>
<form method="post" action="<?php echo $fedup_track === 'dev' ? '?track=dev' : ''; ?>"><button type="submit" name="deploy" value="1">INSTALL FEDISTRUCTURE</button></form>
<?php else: ?><div class="status error">This server is missing a required capability. No unsigned fallback is permitted.</div><?php endif; ?>
</main></body></html>
<?php // ===== SNAPSMACK EOF =====
