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

// Ensure key_type column exists
try { $pdo->query("SELECT key_type FROM snap_ohsnap_keys LIMIT 0");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE snap_ohsnap_keys ADD COLUMN key_type VARCHAR(20) NOT NULL DEFAULT 'ohsnap' AFTER label");
}

// --- GENERATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $label    = trim($_POST['label'] ?? '');
    $key_type = in_array($_POST['key_type'] ?? '', ['ohsnap','smackpress','flkrfckr','gyss','unzucker']) ? $_POST['key_type'] : 'ohsnap';
    if (!$label) $label = match($key_type) {
        'smackpress' => 'SmackPress Key',
        'flkrfckr'   => 'FLKR FCKR Import',
        'gyss'       => 'GET YOUR SHIT SORTED',
        'unzucker'   => 'Unzucker Import',
        default      => 'Oh Snap! Key',
    };

    $raw_key    = bin2hex(random_bytes(32));
    $key_hash   = hash('sha256', $raw_key);
    $key_prefix = substr($raw_key, 0, 8);

    $pdo->prepare("
        INSERT INTO snap_ohsnap_keys (label, key_type, key_hash, key_prefix)
        VALUES (?, ?, ?, ?)
    ")->execute([$label, $key_type, $key_hash, $key_prefix]);

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

// --- FETCH ---
$keys         = $pdo->query("
    SELECT id, label, key_type, key_prefix, is_active, created_at, last_used_at
    FROM snap_ohsnap_keys
    ORDER BY key_type ASC, created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
$active_keys  = array_values(array_filter($keys, fn($k) =>  $k['is_active']));
$revoked_keys = array_values(array_filter($keys, fn($k) => !$k['is_active']));

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

    <?php if ($new_key_raw): ?>
        <div class="alert alert-warn" style="margin-bottom:20px;">
            <strong><?php echo $msg; ?></strong><br><br>
            <code id="new-key-value" style="word-break:break-all;font-size:0.9rem;"><?php echo htmlspecialchars($new_key_raw); ?></code>
            &nbsp;
            <button type="button" class="btn-reset" style="text-decoration:underline;cursor:pointer;" onclick="
                navigator.clipboard.writeText(document.getElementById('new-key-value').textContent);
                this.textContent = 'COPIED';
                setTimeout(() => this.textContent = 'COPY', 2000);
            ">COPY</button>
        </div>
    <?php endif; ?>

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
                            <option value="ohsnap">Oh Snap! (skin designer)</option>
                            <option value="smackpress">SmackPress (WP migration)</option>
                            <option value="flkrfckr">FLKR FCKR (Flickr import)</option>
                            <option value="gyss">GET YOUR SHIT SORTED (photo sorter)</option>
                            <option value="unzucker">Unzucker (Instagram import)</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper mt-20">
                        <label>KEY LABEL</label>
                        <input type="text" name="label" value=""
                               placeholder="E.G. UNZUCKER ON DESKTOP">
                        <p class="field-hint">Give it a name so you remember what it's for.</p>
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
                                    </div>
                                </div>
                            </div>
                            <div class="item-actions">
                                <form method="post" action="smack-api-keys.php" style="display:inline;"
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
            <div class="box" style="margin-top:20px;">
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
                            <form method="post" action="smack-api-keys.php" style="display:inline;"
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
