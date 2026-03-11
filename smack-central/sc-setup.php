<?php
/**
 * SMACK CENTRAL - Setup Installer
 * Alpha v0.7.1
 *
 * One-time web-based installer. Navigate to this file in a browser
 * to configure SMACK CENTRAL on a fresh deployment.
 *
 * What it does:
 *   1. Preflight: checks PHP, extensions, and filesystem requirements
 *   2. Collects DB credentials, paths, and admin account details
 *   3. Tests the DB connection
 *   4. Runs sc-schema.sql to create all tables
 *   5. Generates an Ed25519 signing keypair via libsodium
 *   6. Creates the first admin user
 *   7. Writes sc-config.php
 *   8. Displays the PUBLIC key to copy into SnapSmack installs
 *
 * Delete this file after installation.
 * sc-config.php is gitignored and will never be overwritten by updates.
 */

// ─── GUARD: Already installed? ───────────────────────────────────────────────

$config_file  = __DIR__ . '/sc-config.php';
$schema_file  = __DIR__ . '/sc-schema.sql';
$already_done = file_exists($config_file);

// ─── PREFLIGHT CHECKS ────────────────────────────────────────────────────────

$checks = [
    'PHP 8.0+'       => version_compare(PHP_VERSION, '8.0.0', '>='),
    'pdo_mysql'      => extension_loaded('pdo_mysql'),
    'libsodium'      => function_exists('sodium_crypto_sign_keypair'),
    'exec()'         => function_exists('exec'),
    'ZipArchive'     => class_exists('ZipArchive'),
    'sc-schema.sql'  => file_exists($schema_file),
    'Dir writable'   => is_writable(__DIR__),
];

$preflight_ok = !in_array(false, $checks, true);

// ─── PROCESS FORM ────────────────────────────────────────────────────────────

$error   = '';
$success = false;
$pub_key_hex = '';
$steps   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $preflight_ok) {

    $db_host        = trim($_POST['db_host']        ?? 'localhost');
    $db_name        = trim($_POST['db_name']        ?? '');
    $db_user        = trim($_POST['db_user']        ?? '');
    $db_pass        = $_POST['db_pass']              ?? '';
    $base_url       = rtrim(trim($_POST['base_url'] ?? ''), '/') . '/';
    $forum_api_url  = rtrim(trim($_POST['forum_api_url'] ?? 'https://snapsmack.ca/api/forum'), '/');
    $forum_mod_key  = trim($_POST['forum_mod_key']  ?? '');
    $repo_path      = rtrim(trim($_POST['repo_path']  ?? ''), '/');
    $releases_dir   = rtrim(trim($_POST['releases_dir'] ?? ''), '/') . '/';
    $releases_url   = rtrim(trim($_POST['releases_url'] ?? ''), '/') . '/';
    $git_bin        = trim($_POST['git_bin']        ?? 'git');
    $admin_user     = trim($_POST['admin_user']     ?? '');
    $admin_pass     = $_POST['admin_pass']           ?? '';
    $admin_confirm  = $_POST['admin_confirm']        ?? '';

    // Basic validation
    if (!$db_name || !$db_user) {
        $error = 'Database name and user are required.';
    } elseif (!$admin_user || !$admin_pass) {
        $error = 'Admin username and password are required.';
    } elseif ($admin_pass !== $admin_confirm) {
        $error = 'Admin passwords do not match.';
    } elseif (strlen($admin_pass) < 10) {
        $error = 'Admin password must be at least 10 characters.';
    } elseif (!filter_var($base_url, FILTER_VALIDATE_URL)) {
        $error = 'Base URL does not look like a valid URL.';
    }

    if (!$error) {
        // Step 1: Test DB connection
        try {
            $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $steps[] = ['ok', 'Database connection successful'];
        } catch (PDOException $e) {
            $error = 'Database connection failed: ' . $e->getMessage();
        }
    }

    if (!$error) {
        // Step 2: Run schema
        $sql = file_get_contents($schema_file);
        $statements = array_filter(
            array_map(
                fn($s) => trim(preg_replace('/^\s*--.*$/m', '', $s)),
                explode(';', $sql)
            ),
            fn($s) => $s !== ''
        );

        $schema_errors = [];
        foreach ($statements as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                $errno = (int)($e->errorInfo[1] ?? 0);
                // 1050 = table already exists — safe to ignore
                if ($errno !== 1050) {
                    $schema_errors[] = $e->getMessage();
                }
            }
        }

        if ($schema_errors) {
            $error = 'Schema errors: ' . implode('; ', $schema_errors);
        } else {
            $steps[] = ['ok', 'Schema installed (all tables ready)'];
        }
    }

    if (!$error) {
        // Step 3: Generate Ed25519 keypair
        try {
            $keypair    = sodium_crypto_sign_keypair();
            $priv_key   = sodium_crypto_sign_secretkey($keypair);
            $pub_key    = sodium_crypto_sign_publickey($keypair);
            $priv_hex   = sodium_bin2hex($priv_key);
            $pub_key_hex = sodium_bin2hex($pub_key);
            $steps[] = ['ok', 'Ed25519 signing keypair generated'];
        } catch (SodiumException $e) {
            $error = 'Key generation failed: ' . $e->getMessage();
        }
    }

    if (!$error) {
        // Step 4: Create admin user
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
        // Step 5: Write sc-config.php
        // Values are sanitised before embedding in PHP string literals.
        $esc = fn(string $v) => str_replace(["\\", "'"], ["\\\\", "\\'"], $v);

        $config_contents = <<<PHP
<?php
/**
 * SMACK CENTRAL - Configuration
 * Generated by sc-setup.php on {$_SERVER['SERVER_NAME']} — {$_SERVER['REQUEST_TIME_FLOAT']}
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
// Ed25519 SECRET key (128 hex chars). The public key was shown during install.
// NEVER share or commit this value.
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
    // Best-effort — may fail on some hosts; we tell the user either way
    @unlink(__FILE__);
}

// ─── DETECT SENSIBLE BASE URL DEFAULT ────────────────────────────────────────

$proto       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script_dir  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
$default_url = "{$proto}://{$host}{$script_dir}/";

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SMACK CENTRAL — Setup</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:       #0d0d0d;
    --surface:  #141414;
    --border:   #2a2a2a;
    --text:     #e8e8e8;
    --muted:    #666;
    --accent:   #39FF14;
    --danger:   #ff4d4d;
    --warn:     #f5a623;
    --input-bg: #0a0a0a;
    font-size: 16px;
}

body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Courier New', Courier, monospace;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 3rem 1rem 4rem;
}

.shell {
    width: 100%;
    max-width: 680px;
}

/* ── Header ── */
.sc-logo {
    font-size: 1.1rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    color: var(--accent);
    margin-bottom: 0.25rem;
}
.sc-sub {
    font-size: 0.72rem;
    letter-spacing: 0.2em;
    color: var(--muted);
    text-transform: uppercase;
    margin-bottom: 2.5rem;
}

/* ── Boxes ── */
.box {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 1.5rem;
    margin-bottom: 1.25rem;
}
.box h2 {
    font-size: 0.72rem;
    letter-spacing: 0.18em;
    color: var(--muted);
    text-transform: uppercase;
    margin-bottom: 1.25rem;
    padding-bottom: 0.6rem;
    border-bottom: 1px solid var(--border);
}

/* ── Preflight ── */
.check-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.3rem 0;
    font-size: 0.82rem;
}
.check-icon { width: 1.1rem; text-align: center; flex-shrink: 0; }
.check-ok   { color: var(--accent); }
.check-fail { color: var(--danger); }
.check-name { flex: 1; }

/* ── Alerts ── */
.alert {
    padding: 0.75rem 1rem;
    border-radius: 3px;
    font-size: 0.82rem;
    margin-bottom: 1rem;
    line-height: 1.5;
}
.alert-warn    { background: rgba(245,166,35,0.1);  border-left: 3px solid var(--warn); }
.alert-error   { background: rgba(255,77,77,0.1);   border-left: 3px solid var(--danger); }
.alert-success { background: rgba(57,255,20,0.07);  border-left: 3px solid var(--accent); color: var(--accent); }
.alert-info    { background: rgba(255,255,255,0.03); border-left: 3px solid var(--border); color: var(--muted); }

/* ── Form ── */
.field { margin-bottom: 1rem; }
.field label {
    display: block;
    font-size: 0.68rem;
    letter-spacing: 0.14em;
    color: var(--muted);
    text-transform: uppercase;
    margin-bottom: 0.35rem;
}
.field input[type="text"],
.field input[type="url"],
.field input[type="email"],
.field input[type="password"] {
    width: 100%;
    padding: 0.5rem 0.7rem;
    background: var(--input-bg);
    border: 1px solid var(--border);
    border-radius: 3px;
    color: var(--text);
    font-family: inherit;
    font-size: 0.85rem;
    transition: border-color 0.15s;
}
.field input:focus {
    outline: none;
    border-color: var(--accent);
}
.field-hint {
    font-size: 0.72rem;
    color: var(--muted);
    margin-top: 0.3rem;
    line-height: 1.4;
}

.field-row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.section-label {
    font-size: 0.68rem;
    letter-spacing: 0.18em;
    color: var(--muted);
    text-transform: uppercase;
    padding: 0.9rem 0 0.5rem;
    margin-bottom: 0.5rem;
    border-top: 1px solid var(--border);
    margin-top: 0.75rem;
}
.section-label:first-child { border-top: none; margin-top: 0; padding-top: 0; }

/* ── Buttons ── */
.btn {
    display: inline-block;
    padding: 0.55rem 1.5rem;
    background: transparent;
    border: 1px solid var(--accent);
    color: var(--accent);
    font-family: inherit;
    font-size: 0.78rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    cursor: pointer;
    border-radius: 3px;
    transition: background 0.15s, color 0.15s;
}
.btn:hover   { background: var(--accent); color: #000; }
.btn-danger  { border-color: var(--danger); color: var(--danger); }
.btn-danger:hover { background: var(--danger); color: #fff; }
.btn-muted   { border-color: var(--border); color: var(--muted); }
.btn-muted:hover { background: var(--border); color: var(--text); }

/* ── Step log ── */
.step-log { list-style: none; margin: 0.5rem 0; }
.step-log li {
    padding: 0.35rem 0;
    font-size: 0.82rem;
    display: flex;
    gap: 0.6rem;
    align-items: flex-start;
}
.step-ok-icon   { color: var(--accent); flex-shrink: 0; }
.step-fail-icon { color: var(--danger); flex-shrink: 0; }

/* ── Public key display ── */
.pubkey-block {
    background: var(--input-bg);
    border: 1px solid var(--accent);
    border-radius: 3px;
    padding: 1rem;
    font-size: 0.78rem;
    word-break: break-all;
    line-height: 1.7;
    color: var(--accent);
    letter-spacing: 0.04em;
    margin: 0.75rem 0;
    user-select: all;
}
.pubkey-label {
    font-size: 0.68rem;
    color: var(--muted);
    letter-spacing: 0.14em;
    text-transform: uppercase;
    margin-bottom: 0.4rem;
}
.pubkey-destination {
    background: #0a0a0a;
    border: 1px solid var(--border);
    padding: 0.6rem 0.8rem;
    border-radius: 3px;
    font-size: 0.78rem;
    color: var(--text);
    margin-top: 0.75rem;
}
.pubkey-destination code {
    color: var(--accent);
}

/* ── Already installed notice ── */
.reinstall-notice {
    font-size: 0.8rem;
    line-height: 1.6;
    color: var(--muted);
}
</style>
</head>
<body>
<div class="shell">

    <div class="sc-logo">◈ SMACK CENTRAL</div>
    <div class="sc-sub">Setup &amp; Installation</div>

    <?php if ($success): ?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         SUCCESS SCREEN
         ═══════════════════════════════════════════════════════════════════════ -->

    <div class="box">
        <h2>Installation Complete</h2>
        <ul class="step-log">
            <?php foreach ($steps as [$status, $msg]): ?>
            <li>
                <span class="step-<?php echo $status; ?>-icon"><?php echo $status === 'ok' ? '✓' : '✗'; ?></span>
                <span><?php echo htmlspecialchars($msg); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="box">
        <h2>★ Action Required — Copy Your Public Key</h2>
        <p style="font-size:0.82rem; line-height:1.6; margin-bottom:1rem;">
            The Ed25519 signing keypair was generated during install. The private key is
            in <code style="color:var(--accent)">sc-config.php</code>. You need to paste the
            public key into <strong>every SnapSmack install</strong> before the auto-updater
            will trust packages you sign. This is a one-time operation — the same key works
            for all releases forever.
        </p>

        <div class="pubkey-label">ED25519 PUBLIC KEY (64 hex chars)</div>
        <div class="pubkey-block" id="pubkey-display"><?php echo htmlspecialchars($pub_key_hex); ?></div>

        <div class="pubkey-destination">
            Paste this value into <code>core/release-pubkey.php</code> on each SnapSmack install, replacing the placeholder:<br><br>
            <code>define('SNAPSMACK_RELEASE_PUBKEY', '<?php echo htmlspecialchars($pub_key_hex); ?>');</code>
        </div>

        <p style="font-size:0.75rem; color:var(--muted); margin-top:1rem; line-height:1.5;">
            If you lose this key, you can regenerate it from the private key in sc-config.php using:
            <code style="color:var(--accent)">sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_secretkey(sodium_hex2bin(SMACK_RELEASE_PRIVKEY))))</code>
        </p>
    </div>

    <div class="box">
        <h2>Next Steps</h2>
        <ul class="step-log">
            <li><span class="step-ok-icon">1.</span> <span>Copy the public key above into <code style="color:var(--accent)">core/release-pubkey.php</code> on each SnapSmack install</span></li>
            <li><span class="step-ok-icon">2.</span> <span>Make sure <code style="color:var(--accent)"><?php echo htmlspecialchars($repo_path); ?></code> contains a clone of the SnapSmack repo</span></li>
            <li><span class="step-ok-icon">3.</span> <span>Make sure <code style="color:var(--accent)"><?php echo htmlspecialchars(rtrim($releases_dir, '/')); ?></code> is web-accessible at <code style="color:var(--accent)"><?php echo htmlspecialchars(rtrim($releases_url, '/')); ?></code></span></li>
            <li><span class="step-ok-icon">4.</span> <span>Delete <code style="color:var(--danger)">sc-setup.php</code> — it has done its job</span></li>
            <li><span class="step-ok-icon">5.</span> <span>Sign in at <a href="sc-login.php" style="color:var(--accent)">sc-login.php</a></span></li>
        </ul>

        <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:1.5rem;">
            <form method="POST" style="display:inline;"
                  onsubmit="return confirm('Delete sc-setup.php from the server? This cannot be undone.');">
                <input type="hidden" name="self_delete" value="1">
                <button class="btn btn-danger" type="submit">DELETE SC-SETUP.PHP NOW</button>
            </form>
            <a href="sc-login.php" class="btn">GO TO LOGIN →</a>
        </div>
        <?php if (!file_exists(__FILE__)): ?>
        <div class="alert alert-success" style="margin-top:1rem;">
            sc-setup.php has been deleted from the server.
        </div>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         SETUP FORM
         ═══════════════════════════════════════════════════════════════════════ -->

    <!-- Preflight Checks -->
    <div class="box">
        <h2>System Requirements</h2>
        <?php foreach ($checks as $label => $pass): ?>
        <div class="check-row">
            <span class="check-icon <?php echo $pass ? 'check-ok' : 'check-fail'; ?>">
                <?php echo $pass ? '✓' : '✗'; ?>
            </span>
            <span class="check-name"><?php echo htmlspecialchars($label); ?></span>
            <?php if (!$pass): ?>
            <span style="font-size:0.72rem; color:var(--danger);">
                <?php
                echo match($label) {
                    'PHP 8.0+'      => 'PHP ' . PHP_VERSION . ' installed. Upgrade to 8.0+.',
                    'pdo_mysql'     => 'Install the php-mysql or pdo_mysql extension.',
                    'libsodium'     => 'Install the php-sodium extension.',
                    'exec()'        => 'exec() is disabled. Enable it or ask your host.',
                    'ZipArchive'    => 'Install the php-zip extension.',
                    'sc-schema.sql' => 'sc-schema.sql not found in the same directory.',
                    'Dir writable'  => 'Make ' . __DIR__ . ' writable (chmod 755 or chown).',
                    default         => 'Requirement not met.',
                };
                ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if (!$preflight_ok): ?>
        <div class="alert alert-error" style="margin-top:1rem;">
            Fix the issues above before continuing.
        </div>
        <?php endif; ?>
    </div>

    <?php if ($already_done): ?>
    <div class="box">
        <h2>Already Installed</h2>
        <p class="reinstall-notice">
            <strong style="color:var(--warn)">sc-config.php already exists.</strong>
            Running setup again will <strong>overwrite it</strong> and generate a <strong>new signing keypair</strong>.
            Doing this will invalidate all previously signed release packages — SnapSmack installs
            using the old public key will stop trusting updates until you distribute the new key.
            Only continue if you are re-installing from scratch.
        </p>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error">&gt; <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($preflight_ok): ?>
    <form method="POST">

        <!-- Database -->
        <div class="box">
            <h2>Database</h2>
            <div class="field-row-2">
                <div class="field">
                    <label>DB Host</label>
                    <input type="text" name="db_host"
                           value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>"
                           placeholder="localhost">
                </div>
                <div class="field">
                    <label>DB Name</label>
                    <input type="text" name="db_name"
                           value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>"
                           placeholder="squir871_smackforum" required>
                </div>
            </div>
            <div class="field-row-2">
                <div class="field">
                    <label>DB User</label>
                    <input type="text" name="db_user"
                           value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>"
                           placeholder="db_username" required>
                </div>
                <div class="field">
                    <label>DB Password</label>
                    <input type="password" name="db_pass"
                           value="<?php echo htmlspecialchars($_POST['db_pass'] ?? ''); ?>"
                           placeholder="db_password">
                </div>
            </div>
        </div>

        <!-- URLs & Paths -->
        <div class="box">
            <h2>URLs &amp; Paths</h2>

            <div class="field">
                <label>Base URL <span style="color:var(--muted)">(trailing slash)</span></label>
                <input type="url" name="base_url"
                       value="<?php echo htmlspecialchars($_POST['base_url'] ?? $default_url); ?>"
                       placeholder="https://snapsmack.ca/smack-central/" required>
                <div class="field-hint">The public URL of this smack-central/ directory.</div>
            </div>

            <div class="section-label">Release Packager</div>

            <div class="field">
                <label>SnapSmack Repo Path <span style="color:var(--muted)">(absolute, on this server)</span></label>
                <input type="text" name="repo_path"
                       value="<?php echo htmlspecialchars($_POST['repo_path'] ?? ''); ?>"
                       placeholder="/home/youruser/snapsmack-repo">
                <div class="field-hint">Absolute path to a local clone of the SnapSmack git repo. Leave blank if skipping the Release Packager for now.</div>
            </div>

            <div class="field-row-2">
                <div class="field">
                    <label>Releases Directory <span style="color:var(--muted)">(absolute)</span></label>
                    <input type="text" name="releases_dir"
                           value="<?php echo htmlspecialchars($_POST['releases_dir'] ?? ''); ?>"
                           placeholder="/home/youruser/public_html/releases/">
                </div>
                <div class="field">
                    <label>Releases URL</label>
                    <input type="url" name="releases_url"
                           value="<?php echo htmlspecialchars($_POST['releases_url'] ?? ''); ?>"
                           placeholder="https://snapsmack.ca/releases/">
                </div>
            </div>

            <div class="field" style="max-width:220px;">
                <label>Git Binary</label>
                <input type="text" name="git_bin"
                       value="<?php echo htmlspecialchars($_POST['git_bin'] ?? 'git'); ?>"
                       placeholder="git">
                <div class="field-hint">Use a full path if git isn't in $PATH.</div>
            </div>

            <div class="section-label">Forum API</div>

            <div class="field-row-2">
                <div class="field">
                    <label>Forum API URL</label>
                    <input type="url" name="forum_api_url"
                           value="<?php echo htmlspecialchars($_POST['forum_api_url'] ?? 'https://snapsmack.ca/api/forum'); ?>"
                           placeholder="https://snapsmack.ca/api/forum">
                </div>
                <div class="field">
                    <label>Forum Mod Key</label>
                    <input type="text" name="forum_mod_key"
                           value="<?php echo htmlspecialchars($_POST['forum_mod_key'] ?? ''); ?>"
                           placeholder="mod_your_key_here">
                    <div class="field-hint">From forum-server config. Required for admin actions.</div>
                </div>
            </div>
        </div>

        <!-- Admin Account -->
        <div class="box">
            <h2>Admin Account</h2>
            <div class="field-row-2">
                <div class="field">
                    <label>Username</label>
                    <input type="text" name="admin_user"
                           value="<?php echo htmlspecialchars($_POST['admin_user'] ?? ''); ?>"
                           placeholder="admin" required
                           autocomplete="username">
                </div>
                <div class="field"><!-- spacer --></div>
            </div>
            <div class="field-row-2">
                <div class="field">
                    <label>Password <span style="color:var(--muted)">(min 10 chars)</span></label>
                    <input type="password" name="admin_pass" required
                           placeholder="••••••••••••"
                           autocomplete="new-password">
                </div>
                <div class="field">
                    <label>Confirm Password</label>
                    <input type="password" name="admin_confirm" required
                           placeholder="••••••••••••"
                           autocomplete="new-password">
                </div>
            </div>
            <div class="alert alert-info">
                A new Ed25519 signing keypair will be generated during install.
                The public key will be displayed on the next screen — you will need
                to copy it into every SnapSmack install.
            </div>
        </div>

        <button type="submit" class="btn"
                onclick="return confirm('<?php echo $already_done
                    ? 'Re-running will generate a NEW signing keypair and overwrite sc-config.php. Are you sure?'
                    : 'Install SMACK CENTRAL?'; ?>');">
            <?php echo $already_done ? 'RE-INSTALL SMACK CENTRAL' : 'INSTALL SMACK CENTRAL'; ?> →
        </button>

    </form>
    <?php endif; // preflight_ok ?>

    <?php endif; // !success ?>

</div><!-- /.shell -->
</body>
</html>
