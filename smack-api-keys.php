<?php
/**
 * SNAPSMACK - API Key Management
 *
 * Generates and revokes API keys for SnapSmack desktop tools.
 * Keys are shown once at creation and stored as SHA-256 hashes — there is
 * no way to recover a key after the creation screen is dismissed.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';

$msg         = '';
$new_key_raw = null;   // Set once after generation — shown to the user once only

// Ensure key_type and key_prefix columns exist
try { $pdo->query("SELECT key_type FROM snap_ohsnap_keys LIMIT 0");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE snap_ohsnap_keys ADD COLUMN key_type VARCHAR(20) NOT NULL DEFAULT 'ohsnap' AFTER label");
}
try { $pdo->query("SELECT key_prefix FROM snap_ohsnap_keys LIMIT 0");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE snap_ohsnap_keys ADD COLUMN key_prefix VARCHAR(8) NOT NULL DEFAULT '' AFTER key_hash");
}
try { $pdo->query("SELECT expires_at FROM snap_ohsnap_keys LIMIT 0");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE snap_ohsnap_keys ADD COLUMN expires_at DATETIME DEFAULT NULL AFTER last_used_at");
}
// Per-user binding (canonical owns it). Import keys act AS the bound user; the
// import API forces content ownership to this user. Defensive add for unsynced installs.
try { $pdo->query("SELECT user_id FROM snap_ohsnap_keys LIMIT 0");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE snap_ohsnap_keys ADD COLUMN user_id INT UNSIGNED DEFAULT NULL AFTER is_active");
}

// --- GENERATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $label    = trim($_POST['label'] ?? '');
    $key_type = in_array($_POST['key_type'] ?? '', ['ohsnap','smackpress','flkrfckr','gyss','unzucker','suyb','sybu']) ? $_POST['key_type'] : 'ohsnap';
    if (!$label) $label = match($key_type) {
        'smackpress' => 'SmackPress Key',
        'flkrfckr'   => 'FLKR FCKR Import',
        'gyss'       => 'GET YOUR SHIT SORTED',
        'unzucker'   => 'Unzucker Import',
        'suyb'       => 'Smack Up Your Backup',
        'sybu'       => 'SMACK YOUR BATCH UP',
        default      => 'Oh Snap! Key',
    };

    $raw_key    = bin2hex(random_bytes(32));
    $key_hash   = hash('sha256', $raw_key);
    $key_prefix = substr($raw_key, 0, 8);

    // Mandatory expiry — keys live at most 4 weeks (0.7.263). No "never" option.
    $expiry_map = ['1d' => '+1 day', '1w' => '+1 week', '2w' => '+2 weeks', '4w' => '+4 weeks'];
    $expiry_sel = (string)($_POST['expiry'] ?? '');
    if (!isset($expiry_map[$expiry_sel])) $expiry_sel = '4w'; // default + cap
    $expires_at = date('Y-m-d H:i:s', strtotime($expiry_map[$expiry_sel]));

    // Bind the key to its creator. Per-user keys: each user generates their own,
    // and the import API attributes all imported content to this user.
    $key_user_id = (int)($_SESSION['user_id'] ?? 0) ?: null;

    $pdo->prepare("
        INSERT INTO snap_ohsnap_keys (label, key_type, key_hash, key_prefix, expires_at, user_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$label, $key_type, $key_hash, $key_prefix, $expires_at, $key_user_id]);

    $new_key_raw = $raw_key;
    $msg = '> KEY GENERATED. COPY IT NOW — IT WILL NOT BE SHOWN AGAIN.';
}

// --- REVOKE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke') {
    $key_id = (int)($_POST['key_id'] ?? 0);
    if ($key_id > 0) {
        $pdo->prepare("UPDATE snap_ohsnap_keys SET is_active = 0 WHERE id = ?")
            ->execute([$key_id]);
        header('Location: smack-api-keys.php?msg=KEY+REVOKED');
        exit;
    }
}

// --- DELETE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $key_id = (int)($_POST['key_id'] ?? 0);
    if ($key_id > 0) {
        $pdo->prepare("DELETE FROM snap_ohsnap_keys WHERE id = ? AND is_active = 0")
            ->execute([$key_id]);
        header('Location: smack-api-keys.php?msg=KEY+DELETED');
        exit;
    }
}

// --- AUTHORIZE BULK IMPORT (step-up: password + TOTP) ---
// Flkr Fckr / Unzucker refuse to write to a site holding > 5 items unless the
// owner opens a short import window here. Enabling GRANTS access → requires
// re-auth; cancelling REDUCES access → no password needed.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'authorize_import') {
    require_once 'core/reauth.php';
    $ra = reauth_verify($pdo, (string)($_POST['reauth_password'] ?? ''), (string)($_POST['reauth_totp'] ?? ''));
    if (!$ra['ok']) {
        $msg = 'IMPORT AUTHORIZATION BLOCKED — ' . $ra['error'];
    } else {
        $until = time() + 3600; // 1-hour window
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('import_authorized_until', ?)
                       ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")->execute([(string)$until]);
        header('Location: smack-api-keys.php?msg=' . urlencode('Import authorized until ' . date('H:i', $until)));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_import_auth') {
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('import_authorized_until', '0')
                   ON DUPLICATE KEY UPDATE setting_val = '0'")->execute();
    header('Location: smack-api-keys.php?msg=Import+authorization+cancelled');
    exit;
}

// --- OFFLINE POSTING (SON OF A BATCH) consent gate ---
// Persistent owner opt-in: enabling lets the offline poster write to an
// already-populated gram site. Enabling GRANTS access → requires re-auth;
// disabling REDUCES access → no password. (SECAUDIT 2026-06-25 Finding 1.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enable_offline_posting') {
    require_once 'core/reauth.php';
    $ra = reauth_verify($pdo, (string)($_POST['reauth_password'] ?? ''), (string)($_POST['reauth_totp'] ?? ''));
    if (!$ra['ok']) {
        $msg = 'OFFLINE POSTING NOT ENABLED — ' . $ra['error'];
    } else {
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('gram_authoring_enabled', '1')
                       ON DUPLICATE KEY UPDATE setting_val = '1'")->execute();
        header('Location: smack-api-keys.php?msg=' . urlencode('Offline posting enabled for this site.'));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disable_offline_posting') {
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('gram_authoring_enabled', '0')
                   ON DUPLICATE KEY UPDATE setting_val = '0'")->execute();
    header('Location: smack-api-keys.php?msg=Offline+posting+disabled');
    exit;
}

// --- FETCH ---
$keys         = $pdo->query("
    SELECT id, label, key_type, key_prefix, is_active, created_at, last_used_at, expires_at
    FROM snap_ohsnap_keys
    ORDER BY key_type ASC, created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
$active_keys  = array_values(array_filter($keys, fn($k) =>  $k['is_active']));
$revoked_keys = array_values(array_filter($keys, fn($k) => !$k['is_active']));

// Bulk-import authorization window state (set by the panel below).
$import_auth_until  = (int)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='import_authorized_until' LIMIT 1")->fetchColumn() ?: 0);
$import_auth_active = $import_auth_until > time();

// Offline-posting (SON OF A BATCH) consent state.
$gram_authoring_on = ((string)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='gram_authoring_enabled' LIMIT 1")->fetchColumn() ?: '0')) === '1';

include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">

    <div class="header-row header-row--ruled">
        <h2>API KEYS</h2>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">&gt; <?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>

    <?php if ($msg && !$new_key_raw): ?>
        <div class="alert alert-warn">&gt; <?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <?php if ($new_key_raw): ?>
        <div class="alert alert-warn mb-20">
            <strong><?php echo $msg; ?></strong><br><br>
            <code id="new-key-value" class="key-reveal"><?php echo htmlspecialchars($new_key_raw); ?></code>
            &nbsp;
            <button type="button" class="action-copy" onclick="
                navigator.clipboard.writeText(document.getElementById('new-key-value').textContent);
                this.textContent = 'COPIED';
                setTimeout(() => this.textContent = 'COPY', 2000);
            ">COPY</button>
        </div>
    <?php endif; ?>

    <!-- BULK IMPORT AUTHORIZATION (Flkr Fckr / Unzucker non-empty-site gate) -->
    <div class="box mb-20">
        <h3>BULK IMPORT AUTHORIZATION</h3>
        <p class="dim mb-20">
            The Flkr Fckr and Unzucker importers refuse to write to a site that already holds
            more than 5 items, to stop an accidental bulk import from clobbering an established
            site. Empty or new sites need no authorization. Authorizing requires your password
            (and 2FA code if enabled) and lasts one hour.
        </p>
        <?php if ($import_auth_active): ?>
            <div class="alert alert-success">
                &#10003; Import authorized until <?php echo date('H:i', $import_auth_until); ?>
                (about <?php echo max(1, (int)ceil(($import_auth_until - time()) / 60)); ?> min left).
            </div>
            <form method="post" action="smack-api-keys.php">
                <input type="hidden" name="action" value="cancel_import_auth">
                <button type="submit" class="btn-smack">CANCEL AUTHORIZATION</button>
            </form>
        <?php else: ?>
            <form method="post" action="smack-api-keys.php">
                <input type="hidden" name="action" value="authorize_import">
                <div class="reauth-row">
                    <div class="lens-input-wrapper">
                        <label>PASSWORD</label>
                        <input type="password" name="reauth_password" autocomplete="off">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>2FA CODE (IF ENABLED)</label>
                        <input type="text" name="reauth_totp" inputmode="numeric" autocomplete="off" class="input-code">
                    </div>
                </div>
                <button type="submit" class="master-update-btn">AUTHORIZE IMPORT FOR 1 HOUR</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- OFFLINE POSTING (SON OF A BATCH) consent gate -->
    <div class="box mb-20">
        <h3>OFFLINE POSTING (SON OF A BATCH)</h3>
        <p class="dim mb-20">
            The SON OF A BATCH offline poster (BATCH SLAPPED / BATCH, PLEASE) writes new posts to
            this site via the API. On a site that already holds content, posting stays blocked until
            you enable it here — a one-time opt-in (no per-session re-authorizing). Enabling requires
            your password (and 2FA code if enabled). The server also caps offline posting at 300
            images/hour regardless. Empty or new sites need no opt-in.
        </p>
        <?php if ($gram_authoring_on): ?>
            <div class="alert alert-success">&#10003; Offline posting is ENABLED for this site.</div>
            <form method="post" action="smack-api-keys.php">
                <input type="hidden" name="action" value="disable_offline_posting">
                <button type="submit" class="btn-smack">DISABLE OFFLINE POSTING</button>
            </form>
        <?php else: ?>
            <form method="post" action="smack-api-keys.php">
                <input type="hidden" name="action" value="enable_offline_posting">
                <div class="reauth-row">
                    <div class="lens-input-wrapper">
                        <label>PASSWORD</label>
                        <input type="password" name="reauth_password" autocomplete="off">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>2FA CODE (IF ENABLED)</label>
                        <input type="text" name="reauth_totp" inputmode="numeric" autocomplete="off" class="input-code">
                    </div>
                </div>
                <button type="submit" class="master-update-btn">ENABLE OFFLINE POSTING</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="post-layout-grid">

        <!-- LEFT: Generate form -->
        <div class="post-col-left">
            <div class="box">
                <h3>GENERATE NEW KEY</h3>

                <form method="post" action="smack-api-keys.php">
                    <input type="hidden" name="action" value="generate">

                    <div class="lens-input-wrapper">
                        <label>KEY TYPE</label>
                        <select name="key_type">
                            <option value="ohsnap">OH SNAP! (SKIN DESIGNER)</option>
                            <option value="smackpress">SMACKPRESS (WP MIGRATION)</option>
                            <option value="flkrfckr">FLKR FCKR (FLICKR IMPORT)</option>
                            <option value="gyss">GET YOUR SHIT SORTED (PHOTO SORTER)</option>
                            <option value="unzucker">UNZUCKER (INSTAGRAM IMPORT)</option>
                            <option value="suyb">SUYB (SMACK UP YOUR BACKUP)</option>
                            <option value="sybu">SYBU (SMACK YOUR BATCH UP)</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper mt-20">
                        <label>KEY LABEL</label>
                        <input type="text" name="label" value=""
                               placeholder="E.G. UNZUCKER ON DESKTOP">
                        <p class="field-hint">Give it a name so you remember what it's for.</p>
                    </div>

                    <div class="lens-input-wrapper mt-20">
                        <label>EXPIRES</label>
                        <select name="expiry">
                            <option value="1d">1 day</option>
                            <option value="1w">1 week</option>
                            <option value="2w">2 weeks</option>
                            <option value="4w" selected>4 weeks (max)</option>
                        </select>
                        <p class="field-hint">Keys expire automatically &mdash; 4 weeks max. Mint a fresh one when this lapses.</p>
                    </div>

                    <div class="lens-input-wrapper mt-20">
                        <button type="submit" class="master-update-btn">GENERATE KEY</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- RIGHT: Active keys list -->
        <div class="flex-1">
            <div class="box">
                <h3>ACTIVE KEYS</h3>

                <?php if (!$active_keys): ?>
                    <p class="dim empty-notice">No active keys.</p>
                <?php else: ?>
                    <?php foreach ($active_keys as $key): ?>
                        <div class="recent-item">
                            <div class="item-details">
                                <div class="item-text">
                                    <strong><?php echo htmlspecialchars($key['label']); ?></strong>
                                    <code class="slug-display"><?php echo htmlspecialchars($key['key_type'] ?? 'ohsnap'); ?> &middot; <?php echo htmlspecialchars($key['key_prefix']); ?>…</code>
                                    <div class="item-meta">
                                        CREATED <?php echo htmlspecialchars($key['created_at']); ?> &middot;
                                        LAST USED: <?php echo $key['last_used_at'] ? htmlspecialchars($key['last_used_at']) : 'NEVER'; ?>
                                        <?php if (!empty($key['expires_at'])):
                                            $exp_ts = strtotime($key['expires_at']); ?>
                                            &middot; <?php echo ($exp_ts && $exp_ts <= time())
                                                ? 'EXPIRED ' . htmlspecialchars($key['expires_at'])
                                                : 'EXPIRES ' . htmlspecialchars($key['expires_at']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="item-actions">
                                <form method="post" action="smack-api-keys.php" class="form-inline"
                                      onsubmit="return confirm('REVOKE THIS KEY?');">
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="key_id" value="<?php echo (int)$key['id']; ?>">
                                    <button type="submit" class="btn-reset action-delete">REVOKE</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($revoked_keys): ?>
            <div class="box mt-20">
                <h3>REVOKED KEYS</h3>
                <?php foreach ($revoked_keys as $key): ?>
                    <div class="recent-item">
                        <div class="item-details">
                            <div class="item-text">
                                <strong class="dim"><?php echo htmlspecialchars($key['label']); ?></strong>
                                <code class="slug-display"><?php echo htmlspecialchars($key['key_type'] ?? 'ohsnap'); ?> &middot; <?php echo htmlspecialchars($key['key_prefix']); ?>…</code>
                            </div>
                        </div>
                        <div class="item-actions">
                            <form method="post" action="smack-api-keys.php" class="form-inline"
                                  onsubmit="return confirm('PERMANENTLY DELETE THIS KEY RECORD?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="key_id" value="<?php echo (int)$key['id']; ?>">
                                <button type="submit" class="btn-reset action-delete">DELETE</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>

</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
