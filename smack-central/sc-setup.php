<?php
/**
 * SMACK CENTRAL - Bootstrap Installer
 * Alpha v0.7.3
 *
 * Upload THIS FILE ALONE to your target directory on snapsmack.ca.
 * Navigate to it in a browser. It does everything else:
 *
 *   1. Downloads the SnapSmack repo zip from GitHub
 *   2. Extracts smack-central/ files into the current directory
 *   3. Runs forum-schema.sql then sc-schema.sql against your database
 *   4. Generates an Ed25519 signing keypair
 *   5. Creates the first admin user
 *   6. Writes sc-config.php
 *   7. Offers to delete itself
 *
 * Delete this file after installation.
 */

// ─── CONFIG ──────────────────────────────────────────────────────────────────

const REPO_SLUG = 'baddaywithacamera/snapsmack';

/**
 * Resolve the latest GitHub release tag and return [zip_url, prefix].
 * Falls back to a hardcoded version if the API is unreachable.
 */
function sc_resolve_latest_tag(): array {
    $fallback_tag = 'v0.7.3';

    $ctx  = stream_context_create(['http' => [
        'timeout'    => 10,
        'user_agent' => 'SnapSmack-Installer/1.0',
        'header'     => 'Accept: application/vnd.github.v3+json',
    ]]);
    $body = @file_get_contents(
        'https://api.github.com/repos/' . REPO_SLUG . '/tags?per_page=1',
        false, $ctx
    );

    $tag = $fallback_tag;
    if ($body !== false) {
        $data = json_decode($body, true);
        if (!empty($data[0]['name'])) {
            $tag = $data[0]['name'];
        }
    }

    $slug   = ltrim(preg_replace('/^v/i', '', $tag), '');  // "0.7.1"
    $prefix = 'snapsmack-' . $slug . '/';
    $url    = 'https://github.com/' . REPO_SLUG . '/archive/refs/tags/' . urlencode($tag) . '.zip';
    return [$url, $prefix, $tag];
}

[$repo_zip_url, $repo_prefix, $resolved_tag] = sc_resolve_latest_tag();

// ─── GUARD: Already installed? ───────────────────────────────────────────────

$config_file  = __DIR__ . '/sc-config.php';
$already_done = file_exists($config_file);

// ─── PREFLIGHT CHECKS ────────────────────────────────────────────────────────

function can_fetch_url(): bool {
    if (function_exists('curl_init')) return true;
    return ini_get('allow_url_fopen') && function_exists('file_get_contents');
}

$checks = [
    'PHP 8.0+'      => version_compare(PHP_VERSION, '8.0.0', '>='),
    'pdo_mysql'     => extension_loaded('pdo_mysql'),
    'libsodium'     => function_exists('sodium_crypto_sign_keypair'),
    'ZipArchive'    => class_exists('ZipArchive'),
    'HTTP fetch'    => can_fetch_url(),
    'Dir writable'  => is_writable(__DIR__),
];

$preflight_ok = !in_array(false, $checks, true);

// ─── HELPERS ─────────────────────────────────────────────────────────────────

function sc_http_get(string $url): string|false {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SnapSmack-Installer/0.7.3',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? $body : false;
    }
    $ctx = stream_context_create(['http' => [
        'timeout'    => 120,
        'user_agent' => 'SnapSmack-Installer/0.7.3',
    ]]);
    return @file_get_contents($url, false, $ctx);
}

/**
 * Extract a specific subfolder from a zip archive into $dest_dir.
 * Strips the $strip_prefix from all entry paths.
 * Returns an array of relative paths written.
 */
function sc_extract_subfolder(string $zip_path, string $strip_prefix, string $subfolder, string $dest_dir): array|false {
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) return false;

    $written = [];
    $full_prefix = $strip_prefix . $subfolder . '/';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (!str_starts_with($name, $full_prefix)) continue;

        // Relative path inside the subfolder
        $relative = substr($name, strlen($full_prefix));
        if ($relative === '' || str_ends_with($relative, '/')) continue;
        if (str_contains($relative, '..') || str_starts_with($relative, '/')) continue;

        $dest = rtrim($dest_dir, '/') . '/' . $relative;
        $dir  = dirname($dest);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $content = $zip->getFromIndex($i);
        if ($content === false) continue;

        // Never overwrite an already-working sc-config.php
        if (basename($dest) === 'sc-config.php' && file_exists($dest)) continue;

        file_put_contents($dest, $content);
        $written[] = $relative;
    }

    $zip->close();
    return $written;
}

/**
 * Extract a single file from a zip by its path inside the archive.
 * Returns the file contents or false.
 */
function sc_extract_file(string $zip_path, string $inner_path): string|false {
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) return false;
    $content = $zip->getFromName($inner_path);
    $zip->close();
    return $content;
}

/**
 * Run a block of SQL statements, tolerating "already done" MySQL errors.
 * Returns ['applied' => int, 'skipped' => int, 'errors' => [...]]
 */
function sc_run_sql(PDO $pdo, string $sql): array {
    $idempotent = [1060, 1050, 1091]; // dup column, dup table, missing key
    $result = ['applied' => 0, 'skipped' => 0, 'errors' => []];

    foreach (explode(';', $sql) as $raw) {
        $stmt = trim(preg_replace('/^\s*--.*$/m', '', $raw));
        if ($stmt === '') continue;
        try {
            $pdo->exec($stmt);
            $result['applied']++;
        } catch (PDOException $e) {
            $errno = (int)($e->errorInfo[1] ?? 0);
            if (in_array($errno, $idempotent, true)) {
                $result['skipped']++;
            } else {
                $result['errors'][] = $e->getMessage();
            }
        }
    }
    return $result;
}

// ─── PROCESS FORM ────────────────────────────────────────────────────────────

$error      = '';
$success    = false;
$steps      = [];
$pub_key_hex = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $preflight_ok) {

    $db_host        = trim($_POST['db_host']         ?? 'localhost');
    $db_name        = trim($_POST['db_name']         ?? '');
    $db_user        = trim($_POST['db_user']         ?? '');
    $db_pass        = $_POST['db_pass']               ?? '';
    $base_url       = rtrim(trim($_POST['base_url']  ?? ''), '/') . '/';
    $forum_api_url  = rtrim(trim($_POST['forum_api_url'] ?? 'https://snapsmack.ca/api/forum'), '/');
    $forum_mod_key  = trim($_POST['forum_mod_key']   ?? '');
    $repo_path      = rtrim(trim($_POST['repo_path'] ?? ''), '/');
    $releases_dir   = rtrim(trim($_POST['releases_dir'] ?? ''), '/') . '/';
    $releases_url   = rtrim(trim($_POST['releases_url'] ?? ''), '/') . '/';
    $git_bin        = trim($_POST['git_bin']         ?? 'git');
    $admin_user     = trim($_POST['admin_user']      ?? '');
    $admin_pass     = $_POST['admin_pass']            ?? '';
    $admin_confirm  = $_POST['admin_confirm']         ?? '';
    $install_forum  = !empty($_POST['install_forum']);
    $forum_dest     = rtrim(trim($_POST['forum_dest'] ?? ''), '/');

    // Validate
    if (!$db_name || !$db_user) {
        $error = 'Database name and user are required.';
    } elseif (!$admin_user || !$admin_pass) {
        $error = 'Admin username and password are required.';
    } elseif ($admin_pass !== $admin_confirm) {
        $error = 'Admin passwords do not match.';
    } elseif (strlen($admin_pass) < 10) {
        $error = 'Admin password must be at least 10 characters.';
    }

    if (!$error) {
        // Step 1: Download repo zip
        $steps[] = ['info', 'Downloading ' . $repo_zip_url . ' …'];
        $zip_data = sc_http_get($repo_zip_url);
        if ($zip_data === false) {
            $error = 'Could not download repo zip. Check your server\'s outbound HTTPS access.';
        } else {
            $zip_tmp = sys_get_temp_dir() . '/sc_install_' . time() . '.zip';
            file_put_contents($zip_tmp, $zip_data);
            $steps[count($steps) - 1] = ['ok', 'Repo zip downloaded (' . number_format(strlen($zip_data) / 1024, 0) . ' KB)'];
        }
    }

    if (!$error) {
        // Step 2: Extract smack-central/ into this directory
        $written = sc_extract_subfolder($zip_tmp, $repo_prefix, 'smack-central', __DIR__);
        if ($written === false) {
            $error = 'Could not open downloaded zip. The download may be corrupt — try again.';
        } else {
            $steps[] = ['ok', 'Extracted ' . count($written) . ' files into ' . __DIR__];
        }
    }

    if (!$error && $install_forum && $forum_dest) {
        // Step 3 (optional): Extract forum-server/api/forum/ to the specified path
        $fw = sc_extract_subfolder($zip_tmp, $repo_prefix, 'forum-server/api/forum', $forum_dest);
        if ($fw === false) {
            $steps[] = ['warn', 'Forum server extraction failed — skipping. Deploy manually from forum-server/api/forum/.'];
        } else {
            $steps[] = ['ok', 'Forum server: extracted ' . count($fw) . ' files into ' . $forum_dest];
            $steps[] = ['warn', 'Forum server: edit ' . $forum_dest . '/config.php with your DB credentials and mod key'];
        }
    }

    if (!$error) {
        // Step 4: Connect to DB
        try {
            $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $steps[] = ['ok', 'Database connection OK'];
        } catch (PDOException $e) {
            $error = 'Database connection failed: ' . $e->getMessage();
        }
    }

    if (!$error) {
        // Step 5: Run forum-schema.sql (from zip)
        $forum_sql = sc_extract_file($zip_tmp, $repo_prefix . 'forum-server/forum-schema.sql');
        if ($forum_sql !== false) {
            $r = sc_run_sql($pdo, $forum_sql);
            if ($r['errors']) {
                $error = 'forum-schema.sql error: ' . implode('; ', $r['errors']);
            } else {
                $steps[] = ['ok', "Forum schema: {$r['applied']} statements run, {$r['skipped']} already in place"];
            }
        } else {
            $steps[] = ['warn', 'Could not find forum-schema.sql in zip — skipping. Run it manually if needed.'];
        }
    }

    if (!$error) {
        // Step 6: Run sc-schema.sql (from zip)
        $sc_sql = sc_extract_file($zip_tmp, $repo_prefix . 'smack-central/sc-schema.sql');
        if ($sc_sql !== false) {
            $r = sc_run_sql($pdo, $sc_sql);
            if ($r['errors']) {
                $error = 'sc-schema.sql error: ' . implode('; ', $r['errors']);
            } else {
                $steps[] = ['ok', "SMACK CENTRAL schema: {$r['applied']} statements run, {$r['skipped']} already in place"];
            }
        } else {
            $error = 'Could not find sc-schema.sql in zip.';
        }
    }

    // Clean up zip
    if (isset($zip_tmp) && file_exists($zip_tmp)) @unlink($zip_tmp);

    if (!$error) {
        // Step 7: Generate Ed25519 keypair
        try {
            $keypair     = sodium_crypto_sign_keypair();
            $priv_key    = sodium_crypto_sign_secretkey($keypair);
            $pub_key     = sodium_crypto_sign_publickey($keypair);
            $priv_hex    = sodium_bin2hex($priv_key);
            $pub_key_hex = sodium_bin2hex($pub_key);
            $steps[] = ['ok', 'Ed25519 signing keypair generated'];
        } catch (SodiumException $e) {
            $error = 'Key generation failed: ' . $e->getMessage();
        }
    }

    if (!$error) {
        // Step 8: Create admin user
        $hash = password_hash($admin_pass, PASSWORD_BCRYPT, ['cost' => 12]);
        try {
            $pdo->prepare("
                INSERT INTO sc_admin_users (username, password_hash)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)
            ")->execute([$admin_user, $hash]);
            $steps[] = ['ok', "Admin user \"{$admin_user}\" created"];
        } catch (PDOException $e) {
            $error = 'Could not create admin user: ' . $e->getMessage();
        }
    }

    if (!$error) {
        // Step 9: Write sc-config.php
        $esc = fn(string $v) => str_replace(["\\", "'"], ["\\\\", "\\'"], $v);
        $ts  = date('Y-m-d H:i:s');

        $config_contents = <<<PHP
<?php
/**
 * SMACK CENTRAL - Configuration
 * Generated by sc-setup.php on {$ts}
 *
 * This file is gitignored and must never be committed.
 * Delete sc-setup.php from the server if you haven't already.
 */

// ── Database ──────────────────────────────────────────────────────────────────
define('SC_DB_HOST', '{$esc($db_host)}');
define('SC_DB_NAME', '{$esc($db_name)}');
define('SC_DB_USER', '{$esc($db_user)}');
define('SC_DB_PASS', '{$esc($db_pass)}');

// ── Session ───────────────────────────────────────────────────────────────────
define('SC_SESSION_NAME', 'smack_central_session');
define('SC_BASE_URL',     '{$esc($base_url)}');

// ── Forum API ─────────────────────────────────────────────────────────────────
define('FORUM_API_URL',  '{$esc($forum_api_url)}');
define('FORUM_MOD_KEY',  '{$esc($forum_mod_key)}');

// ── Release Signing ───────────────────────────────────────────────────────────
// Ed25519 SECRET key (128 hex chars). NEVER share or commit this value.
// The matching public key was shown during install — it goes in core/release-pubkey.php
// on every SnapSmack install.
define('SMACK_RELEASE_PRIVKEY', '{$priv_hex}');

// ── Git & Release Paths ───────────────────────────────────────────────────────
define('SNAPSMACK_REPO_PATH', '{$esc($repo_path)}');
define('RELEASES_DIR',        '{$esc($releases_dir)}');
define('RELEASES_URL',        '{$esc($releases_url)}');
define('GIT_BIN',             '{$esc($git_bin)}');
PHP;

        if (file_put_contents($config_file, $config_contents) === false) {
            $error = 'Could not write sc-config.php. Check directory permissions.';
        } else {
            $steps[] = ['ok', 'sc-config.php written'];
            $success = true;
        }
    }
}

// ─── SELF-DELETE ─────────────────────────────────────────────────────────────

if (isset($_POST['self_delete']) && $success) {
    @unlink(__FILE__);
}

// ─── AUTO-DETECT BASE URL ────────────────────────────────────────────────────

$proto       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script_dir  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
$default_url = "{$proto}://{$host}{$script_dir}/";

// Guess forum dest: public_html/api/forum/
$default_forum_dest = preg_replace('#/smack-central/?$#', '/api/forum', rtrim(__DIR__, '/'));

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SMACK CENTRAL — Install</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg: #0d0d0d; --surface: #141414; --border: #2a2a2a;
    --text: #e8e8e8; --muted: #666; --accent: #39FF14;
    --danger: #ff4d4d; --warn: #f5a623; --input-bg: #0a0a0a;
}
body {
    background: var(--bg); color: var(--text);
    font-family: 'Courier New', Courier, monospace;
    min-height: 100vh; display: flex;
    align-items: flex-start; justify-content: center;
    padding: 3rem 1rem 4rem;
}
.shell { width: 100%; max-width: 680px; }
.sc-logo { font-size: 1.1rem; font-weight: 700; letter-spacing: 0.12em; color: var(--accent); margin-bottom: 0.25rem; }
.sc-sub  { font-size: 0.72rem; letter-spacing: 0.2em; color: var(--muted); text-transform: uppercase; margin-bottom: 2.5rem; }
.box { background: var(--surface); border: 1px solid var(--border); border-radius: 4px; padding: 1.5rem; margin-bottom: 1.25rem; }
.box h2 { font-size: 0.72rem; letter-spacing: 0.18em; color: var(--muted); text-transform: uppercase; margin-bottom: 1.25rem; padding-bottom: 0.6rem; border-bottom: 1px solid var(--border); }
.check-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.3rem 0; font-size: 0.82rem; }
.check-icon { width: 1.1rem; text-align: center; flex-shrink: 0; }
.c-ok { color: var(--accent); } .c-fail { color: var(--danger); }
.alert { padding: 0.75rem 1rem; border-radius: 3px; font-size: 0.82rem; margin-bottom: 1rem; line-height: 1.5; }
.alert-warn    { background: rgba(245,166,35,.1);  border-left: 3px solid var(--warn); color: var(--warn); }
.alert-error   { background: rgba(255,77,77,.1);   border-left: 3px solid var(--danger); color: var(--danger); }
.alert-success { background: rgba(57,255,20,.07);  border-left: 3px solid var(--accent); color: var(--accent); }
.alert-info    { background: rgba(255,255,255,.03); border-left: 3px solid var(--border); color: var(--muted); }
.field { margin-bottom: 1rem; }
.field label { display: block; font-size: 0.68rem; letter-spacing: 0.14em; color: var(--muted); text-transform: uppercase; margin-bottom: 0.35rem; }
.field input[type="text"],
.field input[type="url"],
.field input[type="password"] {
    width: 100%; padding: 0.5rem 0.7rem;
    background: var(--input-bg); border: 1px solid var(--border);
    border-radius: 3px; color: var(--text); font-family: inherit; font-size: 0.85rem;
}
.field input:focus { outline: none; border-color: var(--accent); }
.field-hint { font-size: 0.72rem; color: var(--muted); margin-top: 0.3rem; line-height: 1.4; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.section-rule { font-size: 0.68rem; letter-spacing: 0.18em; color: var(--muted); text-transform: uppercase; padding: 0.9rem 0 0.5rem; border-top: 1px solid var(--border); margin-top: 0.75rem; margin-bottom: 0.5rem; }
.section-rule:first-child { border-top: none; margin-top: 0; padding-top: 0; }
.btn { display: inline-block; padding: 0.55rem 1.5rem; background: transparent; border: 1px solid var(--accent); color: var(--accent); font-family: inherit; font-size: 0.78rem; letter-spacing: 0.1em; text-transform: uppercase; cursor: pointer; border-radius: 3px; transition: background .15s, color .15s; }
.btn:hover { background: var(--accent); color: #000; }
.btn-danger { border-color: var(--danger); color: var(--danger); }
.btn-danger:hover { background: var(--danger); color: #fff; }
.step-log { list-style: none; margin: 0.5rem 0; }
.step-log li { padding: 0.35rem 0; font-size: 0.82rem; display: flex; gap: 0.6rem; align-items: flex-start; }
.s-ok   { color: var(--accent); flex-shrink: 0; }
.s-warn { color: var(--warn);   flex-shrink: 0; }
.s-fail { color: var(--danger); flex-shrink: 0; }
.s-info { color: var(--muted);  flex-shrink: 0; }
.pubkey-block { background: var(--input-bg); border: 1px solid var(--accent); border-radius: 3px; padding: 1rem; font-size: 0.78rem; word-break: break-all; line-height: 1.7; color: var(--accent); margin: 0.75rem 0; user-select: all; }
.pubkey-dest  { background: #0a0a0a; border: 1px solid var(--border); padding: 0.6rem 0.8rem; border-radius: 3px; font-size: 0.78rem; color: var(--text); margin-top: 0.75rem; }
.checkbox-row { display: flex; align-items: center; gap: 0.6rem; font-size: 0.82rem; margin-bottom: 0.75rem; cursor: pointer; }
.checkbox-row input { width: 1rem; height: 1rem; cursor: pointer; }
</style>
</head>
<body>
<div class="shell">

<div class="sc-logo">◈ SMACK CENTRAL</div>
<div class="sc-sub">Bootstrap Installer — upload this file alone, it handles the rest</div>

<?php if ($success): ?>
<!-- ═══════════════ SUCCESS ═══════════════ -->

<div class="box">
    <h2>Installation Complete</h2>
    <ul class="step-log">
    <?php foreach ($steps as [$s, $msg]): ?>
        <li>
            <span class="s-<?php echo $s; ?>"><?php echo match($s) { 'ok'=>'✓', 'warn'=>'▲', 'fail'=>'✗', default=>'·' }; ?></span>
            <span><?php echo htmlspecialchars($msg); ?></span>
        </li>
    <?php endforeach; ?>
    </ul>
</div>

<div class="box">
    <h2>★ Copy Your Public Signing Key</h2>
    <p style="font-size:.82rem;line-height:1.6;margin-bottom:1rem;">
        Paste this into <code style="color:var(--accent)">core/release-pubkey.php</code> on
        <strong>every SnapSmack install</strong>. Without it, the auto-updater will not trust
        packages you sign. One key, all installs, forever.
    </p>
    <div style="font-size:.68rem;color:var(--muted);letter-spacing:.14em;text-transform:uppercase;margin-bottom:.4rem;">Ed25519 Public Key</div>
    <div class="pubkey-block"><?php echo htmlspecialchars($pub_key_hex); ?></div>
    <div class="pubkey-dest">
        Replace the placeholder in <code style="color:var(--accent)">core/release-pubkey.php</code>:<br><br>
        <code style="color:var(--accent)">define('SNAPSMACK_RELEASE_PUBKEY', '<?php echo htmlspecialchars($pub_key_hex); ?>');</code>
    </div>
    <p style="font-size:.72rem;color:var(--muted);margin-top:.75rem;line-height:1.5;">
        If you ever need to recover this from sc-config.php:
        <code style="color:var(--accent)">sodium_bin2hex(sodium_crypto_sign_publickey(sodium_hex2bin(SMACK_RELEASE_PRIVKEY)))</code>
    </p>
</div>

<div class="box">
    <h2>Next Steps</h2>
    <ul class="step-log">
        <li><span class="s-ok">1.</span><span>Copy the public key above into <code style="color:var(--accent)">core/release-pubkey.php</code> on each SnapSmack install</span></li>
        <li><span class="s-ok">2.</span><span>Clone the SnapSmack repo on this server: <code style="color:var(--accent)">git clone https://github.com/baddaywithacamera/snapsmack.git <?php echo htmlspecialchars($repo_path ?: '/path/to/repo'); ?></code></span></li>
        <li><span class="s-ok">3.</span><span>Make sure <code style="color:var(--accent)"><?php echo htmlspecialchars(rtrim($releases_dir??'', '/')); ?></code> is web-accessible</span></li>
        <li><span class="s-ok">4.</span><span>Edit the forum-server <code style="color:var(--accent)">config.php</code> with DB credentials and your mod key</span></li>
        <li><span class="s-ok">5.</span><span><strong>Delete sc-setup.php</strong> — it has done its job</span></li>
        <li><span class="s-ok">6.</span><span>Sign in at <a href="sc-login.php" style="color:var(--accent)">sc-login.php</a></span></li>
    </ul>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem;">
        <form method="POST" style="display:inline"
              onsubmit="return confirm('Delete sc-setup.php from the server?');">
            <input type="hidden" name="self_delete" value="1">
            <button class="btn btn-danger" type="submit">DELETE SC-SETUP.PHP NOW</button>
        </form>
        <a href="sc-login.php" class="btn">GO TO LOGIN →</a>
    </div>
    <?php if (!file_exists(__FILE__)): ?>
    <div class="alert alert-success" style="margin-top:1rem;">sc-setup.php has been deleted.</div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ═══════════════ SETUP FORM ═══════════════ -->

<div class="box">
    <h2>System Requirements</h2>
    <?php foreach ($checks as $label => $pass): ?>
    <div class="check-row">
        <span class="check-icon <?php echo $pass ? 'c-ok' : 'c-fail'; ?>"><?php echo $pass ? '✓' : '✗'; ?></span>
        <span style="flex:1"><?php echo htmlspecialchars($label); ?></span>
        <?php if (!$pass): ?>
        <span style="font-size:.72rem;color:var(--danger)"><?php echo match($label) {
            'PHP 8.0+'     => 'Running PHP ' . PHP_VERSION . '. Need 8.0+.',
            'pdo_mysql'    => 'Install the php-mysql / pdo_mysql extension.',
            'libsodium'    => 'Install the php-sodium extension.',
            'ZipArchive'   => 'Install the php-zip extension.',
            'HTTP fetch'   => 'Enable curl or allow_url_fopen.',
            'Dir writable' => __DIR__ . ' is not writable.',
            default        => 'Not met.',
        }; ?></span>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (!$preflight_ok): ?>
    <div class="alert alert-error" style="margin-top:1rem;">Fix the issues above before continuing.</div>
    <?php endif; ?>
</div>

<div class="alert alert-info">
    Installing <strong><?php echo htmlspecialchars($resolved_tag); ?></strong> — latest tag resolved from GitHub.
    Download: <code style="color:var(--accent)"><?php echo htmlspecialchars($repo_zip_url); ?></code>
</div>

<?php if ($already_done): ?>
<div class="alert alert-warn">
    sc-config.php already exists. Re-running will generate a <strong>new signing keypair</strong>
    and overwrite your config. All previously signed packages will be untrusted by installs
    until you distribute the new public key.
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-error">&gt; <?php echo htmlspecialchars($error); ?></div>
<?php if (!empty($steps)): ?>
<div class="box"><h2>Progress Before Error</h2>
<ul class="step-log">
<?php foreach ($steps as [$s, $msg]): ?>
    <li><span class="s-<?php echo $s; ?>"><?php echo match($s){'ok'=>'✓','warn'=>'▲','fail'=>'✗',default=>'·'}; ?></span>
    <span><?php echo htmlspecialchars($msg); ?></span></li>
<?php endforeach; ?>
</ul></div>
<?php endif; ?>
<?php endif; ?>

<?php if ($preflight_ok): ?>
<form method="POST">

    <div class="box">
        <h2>Database</h2>
        <div class="grid2">
            <div class="field">
                <label>DB Host</label>
                <input type="text" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>">
            </div>
            <div class="field">
                <label>DB Name</label>
                <input type="text" name="db_name" value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>" required placeholder="squir871_smackforum">
            </div>
        </div>
        <div class="grid2">
            <div class="field">
                <label>DB User</label>
                <input type="text" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>" required>
            </div>
            <div class="field">
                <label>DB Password</label>
                <input type="password" name="db_pass" value="<?php echo htmlspecialchars($_POST['db_pass'] ?? ''); ?>">
            </div>
        </div>
    </div>

    <div class="box">
        <h2>Paths &amp; URLs</h2>

        <div class="field">
            <label>Base URL</label>
            <input type="text" name="base_url" value="<?php echo htmlspecialchars($_POST['base_url'] ?? $default_url); ?>" required>
            <div class="field-hint">Public URL of this smack-central/ directory, trailing slash.</div>
        </div>

        <div class="section-rule">Release Packager</div>
        <div class="field">
            <label>SnapSmack Repo Path <span style="color:var(--muted)">(absolute, on this server)</span></label>
            <input type="text" name="repo_path" value="<?php echo htmlspecialchars($_POST['repo_path'] ?? ''); ?>" placeholder="/home/youruser/snapsmack-repo">
            <div class="field-hint">Where to clone the SnapSmack repo for <code>git archive</code>. Leave blank to set up later.</div>
        </div>
        <div class="grid2">
            <div class="field">
                <label>Releases Directory</label>
                <input type="text" name="releases_dir" value="<?php echo htmlspecialchars($_POST['releases_dir'] ?? ''); ?>" placeholder="/home/youruser/public_html/releases/">
            </div>
            <div class="field">
                <label>Releases URL</label>
                <input type="text" name="releases_url" value="<?php echo htmlspecialchars($_POST['releases_url'] ?? ''); ?>" placeholder="https://snapsmack.ca/releases/">
            </div>
        </div>
        <div class="field" style="max-width:220px">
            <label>Git Binary</label>
            <input type="text" name="git_bin" value="<?php echo htmlspecialchars($_POST['git_bin'] ?? 'git'); ?>">
        </div>

        <div class="section-rule">Forum API</div>
        <div class="grid2">
            <div class="field">
                <label>Forum API URL</label>
                <input type="text" name="forum_api_url" value="<?php echo htmlspecialchars($_POST['forum_api_url'] ?? 'https://snapsmack.ca/api/forum'); ?>">
            </div>
            <div class="field">
                <label>Forum Mod Key</label>
                <input type="text" name="forum_mod_key" value="<?php echo htmlspecialchars($_POST['forum_mod_key'] ?? ''); ?>" placeholder="mod_your_key_here">
                <div class="field-hint">From forum-server config.php — must match.</div>
            </div>
        </div>

        <div class="section-rule">Forum Server (optional)</div>
        <label class="checkbox-row">
            <input type="checkbox" name="install_forum" value="1" <?php echo !empty($_POST['install_forum']) ? 'checked' : ''; ?>>
            Also extract forum-server files from the zip
        </label>
        <div class="field">
            <label>Forum Server Destination <span style="color:var(--muted)">(absolute path)</span></label>
            <input type="text" name="forum_dest" value="<?php echo htmlspecialchars($_POST['forum_dest'] ?? $default_forum_dest); ?>" placeholder="/home/youruser/public_html/api/forum">
            <div class="field-hint">Where <code>index.php</code>, <code>.htaccess</code>, and <code>config.example.php</code> land. You still need to create config.php there.</div>
        </div>
    </div>

    <div class="box">
        <h2>Admin Account</h2>
        <div class="grid2">
            <div class="field">
                <label>Username</label>
                <input type="text" name="admin_user" value="<?php echo htmlspecialchars($_POST['admin_user'] ?? ''); ?>" required autocomplete="username">
            </div>
            <div></div>
        </div>
        <div class="grid2">
            <div class="field">
                <label>Password <span style="color:var(--muted)">(min 10 chars)</span></label>
                <input type="password" name="admin_pass" required autocomplete="new-password">
            </div>
            <div class="field">
                <label>Confirm Password</label>
                <input type="password" name="admin_confirm" required autocomplete="new-password">
            </div>
        </div>
        <div class="alert alert-info">
            An Ed25519 signing keypair will be generated. The public key is shown after install
            and must be copied into each SnapSmack install's <code>core/release-pubkey.php</code>.
        </div>
    </div>

    <button type="submit" class="btn"
            onclick="return confirm('<?php echo $already_done ? 'Re-install will generate a NEW keypair and overwrite sc-config.php. Continue?' : 'Download repo and install SMACK CENTRAL?'; ?>');">
        <?php echo $already_done ? 'RE-INSTALL' : 'INSTALL SMACK CENTRAL'; ?> →
    </button>

</form>
<?php endif; ?>

<?php endif; // !success ?>

</div>
</body>
</html>
