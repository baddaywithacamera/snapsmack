<?php
/**
 * SNAPSMACK - Oh Snap! API Key Management
 *
 * Generates and revokes API keys for the Oh Snap! desktop skin designer.
 * Keys are shown once at creation and stored as SHA-256 hashes — there is
 * no way to recover a key after the creation screen is dismissed.
 */

require_once 'core/auth.php';

$msg          = '';
$msg_type     = 'ok';
$new_key_raw  = null;   // Set once after generation — shown to the user once only

// --- GENERATE A NEW KEY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    $label = trim($_POST['label'] ?? 'Oh Snap! Key');
    if (!$label) $label = 'Oh Snap! Key';

    $raw_key   = bin2hex(random_bytes(32));   // 64-char hex key
    $key_hash  = hash('sha256', $raw_key);
    $key_prefix = substr($raw_key, 0, 8);

    $pdo->prepare("
        INSERT INTO snap_ohsnap_keys (label, key_hash, key_prefix)
        VALUES (?, ?, ?)
    ")->execute([$label, $key_hash, $key_prefix]);

    $new_key_raw = $raw_key;
    $msg = 'New key generated. Copy it now — it will not be shown again.';
    $msg_type = 'warn';
}

// --- REVOKE A KEY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revoke') {
    $key_id = (int)($_POST['key_id'] ?? 0);
    if ($key_id > 0) {
        $pdo->prepare("UPDATE snap_ohsnap_keys SET is_active = 0 WHERE id = ?")
            ->execute([$key_id]);
        $msg = 'Key revoked.';
    }
}

// --- DELETE A REVOKED KEY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $key_id = (int)($_POST['key_id'] ?? 0);
    if ($key_id > 0) {
        // Only allow deleting inactive keys for safety
        $pdo->prepare("DELETE FROM snap_ohsnap_keys WHERE id = ? AND is_active = 0")
            ->execute([$key_id]);
        $msg = 'Key deleted.';
    }
}

// --- FETCH ALL KEYS ---
$keys = $pdo->query("
    SELECT id, label, key_prefix, is_active, created_at, last_used_at
    FROM snap_ohsnap_keys
    ORDER BY created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="smack-main">

    <div class="smack-topbar">
        <h1 class="smack-page-title">Oh Snap! API Keys</h1>
    </div>

    <?php if ($msg): ?>
        <div class="sc-notice <?php echo $msg_type === 'warn' ? 'sc-notice-warn' : 'sc-notice-ok'; ?>">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <?php if ($new_key_raw): ?>
        <div class="ohsnap-new-key-block">
            <p class="ohsnap-key-label">Your new API key — copy it now, it will not be shown again:</p>
            <div class="ohsnap-key-display">
                <code id="new-key-value"><?php echo htmlspecialchars($new_key_raw); ?></code>
                <button type="button" class="sc-btn sc-btn-sm" onclick="
                    navigator.clipboard.writeText(document.getElementById('new-key-value').textContent);
                    this.textContent = 'Copied!';
                    setTimeout(() => this.textContent = 'Copy', 2000);
                ">Copy</button>
            </div>
            <p class="ohsnap-key-hint">Paste this into Oh Snap! under Settings → Connect to SnapSmack.</p>
        </div>
    <?php endif; ?>

    <!-- GENERATE NEW KEY -->
    <div class="smack-card">
        <h2 class="smack-card-title">Generate New Key</h2>
        <form method="post" action="smack-api-keys.php">
            <input type="hidden" name="action" value="generate">
            <div class="smack-form-row">
                <label for="key-label">Key Label</label>
                <input type="text" id="key-label" name="label" value="Oh Snap! Key"
                       placeholder="e.g. Oh Snap! on Desktop"
                       class="smack-input" style="max-width:360px;">
                <p class="smack-field-hint">Give it a name so you remember what it's for.</p>
            </div>
            <button type="submit" class="sc-btn sc-btn-primary">Generate Key</button>
        </form>
    </div>

    <!-- ACTIVE KEYS -->
    <div class="smack-card" style="margin-top:24px;">
        <h2 class="smack-card-title">Active Keys</h2>

        <?php $active_keys = array_filter($keys, fn($k) => $k['is_active']); ?>

        <?php if (!$active_keys): ?>
            <p class="smack-muted">No active keys. Generate one above to connect Oh Snap!.</p>
        <?php else: ?>
            <table class="smack-table">
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Prefix</th>
                        <th>Created</th>
                        <th>Last Used</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active_keys as $key): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($key['label']); ?></td>
                            <td><code><?php echo htmlspecialchars($key['key_prefix']); ?>…</code></td>
                            <td><?php echo htmlspecialchars($key['created_at']); ?></td>
                            <td><?php echo $key['last_used_at'] ? htmlspecialchars($key['last_used_at']) : '<span class="smack-muted">Never</span>'; ?></td>
                            <td>
                                <form method="post" action="smack-api-keys.php"
                                      onsubmit="return confirm('Revoke this key? Any Oh Snap! instance using it will lose access immediately.');">
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="key_id" value="<?php echo (int)$key['id']; ?>">
                                    <button type="submit" class="sc-btn sc-btn-sm sc-btn-danger">Revoke</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- REVOKED KEYS -->
    <?php $revoked_keys = array_filter($keys, fn($k) => !$k['is_active']); ?>
    <?php if ($revoked_keys): ?>
        <div class="smack-card" style="margin-top:24px;">
            <h2 class="smack-card-title">Revoked Keys</h2>
            <table class="smack-table">
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Prefix</th>
                        <th>Created</th>
                        <th>Last Used</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($revoked_keys as $key): ?>
                        <tr class="smack-row-muted">
                            <td><?php echo htmlspecialchars($key['label']); ?></td>
                            <td><code><?php echo htmlspecialchars($key['key_prefix']); ?>…</code></td>
                            <td><?php echo htmlspecialchars($key['created_at']); ?></td>
                            <td><?php echo $key['last_used_at'] ? htmlspecialchars($key['last_used_at']) : '<span class="smack-muted">Never</span>'; ?></td>
                            <td>
                                <form method="post" action="smack-api-keys.php"
                                      onsubmit="return confirm('Permanently delete this key record?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="key_id" value="<?php echo (int)$key['id']; ?>">
                                    <button type="submit" class="sc-btn sc-btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- HOW TO CONNECT -->
    <div class="smack-card" style="margin-top:24px;">
        <h2 class="smack-card-title">Connecting Oh Snap!</h2>
        <ol class="ohsnap-instructions">
            <li>Generate a key above.</li>
            <li>In Oh Snap!, open <strong>Settings → Connect to SnapSmack</strong>.</li>
            <li>Enter your site URL: <code><?php echo htmlspecialchars(BASE_URL); ?></code></li>
            <li>Paste the key into the API Key field.</li>
            <li>Click <strong>Connect</strong>. Oh Snap! will pull your content and active skin.</li>
        </ol>
        <p class="smack-field-hint" style="margin-top:12px;">
            The API endpoint is <code><?php echo htmlspecialchars(BASE_URL); ?>api.php?route=ohsnap/ping</code>
        </p>
    </div>

</div>

<style>
.ohsnap-new-key-block {
    background: var(--card-bg, #1e1e1e);
    border: 1px solid var(--accent, #f5c842);
    border-radius: 6px;
    padding: 20px 24px;
    margin-bottom: 24px;
}
.ohsnap-key-label {
    margin: 0 0 10px;
    font-weight: 600;
    color: var(--accent, #f5c842);
}
.ohsnap-key-display {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--bg-primary, #111);
    border-radius: 4px;
    padding: 10px 14px;
}
.ohsnap-key-display code {
    flex: 1;
    font-size: 0.85rem;
    word-break: break-all;
    color: var(--text-primary, #e0e0e0);
}
.ohsnap-key-hint {
    margin: 10px 0 0;
    font-size: 0.82rem;
    color: var(--text-muted, #888);
}
.ohsnap-instructions {
    margin: 0;
    padding-left: 20px;
    line-height: 2;
}
</style>

<?php include 'core/admin-footer.php'; ?>
<?php // EOF
