<?php
/**
 * SNAPSMACK - Network Settings Push
 *
 * Hub-only page. Pushes selected settings from the hub to connected spokes.
 * Most groups push to all active spokes. Downloads uses a custom spoke selector
 * (stored so the selection persists across sessions).
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once 'core/auth-smack.php';
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

// --- HUB GUARD ---
$multisite_role = $settings['multisite_role'] ?? '';
if ($multisite_role !== 'hub') {
    header('Location: smack-multisite.php');
    exit;
}

// --- ACTIVE SPOKES ---
$spokes = $pdo->query("
    SELECT id, site_url, site_name, api_key_local
    FROM snap_multisite_nodes
    WHERE role = 'spoke' AND status = 'active'
    ORDER BY site_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// --- PERSISTED DOWNLOADS SPOKE SELECTION ---
$dl_spoke_ids_raw = $settings['network_push_downloads_spokes'] ?? '';
$dl_spoke_ids     = array_filter(array_map('intval', json_decode($dl_spoke_ids_raw, true) ?? []));

// ─────────────────────────────────────────────────────────────────────────────
// cURL helper — push key/value pairs to a spoke
// ─────────────────────────────────────────────────────────────────────────────
function ms_settings_push(string $site_url, string $api_key, array $pairs): array {
    $url = rtrim($site_url, '/') . '/api.php?route=multisite/settings/push';
    $ch  = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['settings' => json_encode($pairs)]),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Accept: application/json',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err)        return ['ok' => false, 'error' => $err];
    if ($code !== 200) return ['ok' => false, 'error' => "HTTP {$code}"];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Bad JSON'];
}

// ─────────────────────────────────────────────────────────────────────────────
// POST handlers — one per group
// ─────────────────────────────────────────────────────────────────────────────
$push_results = [];
$csrf = $_SESSION['csrf_token'] ?? '';

// Save downloads spoke selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_dl_spokes'])) {
    $selected = array_map('intval', (array)($_POST['dl_spoke_ids'] ?? []));
    $encoded  = json_encode(array_values($selected));
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('network_push_downloads_spokes', ?)
                   ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")->execute([$encoded]);
    $dl_spoke_ids = $selected;
    $push_results['dl_spokes'] = ['saved' => true];
}

// Generic all-spokes push
$push_groups = [
    'push_timezone'  => ['timezone', 'date_format'],
    'push_akismet'   => ['akismet_key'],
    'push_ai'        => ['ai_training_policy'],
    'push_smackback' => ['smackback_enabled', 'smackback_mode'],
    'push_comments'  => ['global_comments_enabled'],
    'push_email'     => ['site_email'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($push_groups as $action => $keys) {
        if (!isset($_POST[$action])) continue;
        $pairs = [];
        foreach ($keys as $k) {
            if (isset($settings[$k])) $pairs[$k] = $settings[$k];
        }
        if (empty($pairs)) continue;
        $results = [];
        foreach ($spokes as $spoke) {
            $r = ms_settings_push($spoke['site_url'], $spoke['api_key_local'], $pairs);
            $results[$spoke['site_name'] ?: $spoke['site_url']] = ($r['ok'] ?? false) ? 'OK' : ($r['error'] ?? 'Failed');
        }
        $push_results[$action] = $results;
    }

    // Downloads push — custom spoke subset
    if (isset($_POST['push_downloads'])) {
        $pairs = [];
        foreach (['download_link_required', 'download_default_mode'] as $k) {
            if (isset($settings[$k])) $pairs[$k] = $settings[$k];
        }
        $results = [];
        foreach ($spokes as $spoke) {
            if (!in_array((int)$spoke['id'], $dl_spoke_ids, true)) continue;
            $r = ms_settings_push($spoke['site_url'], $spoke['api_key_local'], $pairs);
            $results[$spoke['site_name'] ?: $spoke['site_url']] = ($r['ok'] ?? false) ? 'OK' : ($r['error'] ?? 'Failed');
        }
        $push_results['push_downloads'] = $results ?: ['(no spokes selected)' => '—'];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PAGE
// ─────────────────────────────────────────────────────────────────────────────
$page_title = 'Network Settings';
require_once 'core/admin-header.php';
?>

<div class="main">
    <div class="header-row">
        <h2>NETWORK SETTINGS</h2>
        <div class="header-actions">
            <div class="status-pill status-online">
                <?php echo count($spokes); ?> SPOKE<?php echo count($spokes) !== 1 ? 'S' : ''; ?> CONNECTED
            </div>
        </div>
    </div>

    <div class="signal-control-header" style="margin-bottom:20px;">
        <div class="signal-nav-group">
            <a href="smack-multisite.php"           class="btn-clear">DASHBOARD</a>
            <a href="smack-multisite-comments.php"  class="btn-clear">SIGNALS</a>
            <a href="smack-multisite-posts.php"     class="btn-clear">POSTS</a>
            <a href="smack-multisite-backup.php"    class="btn-clear">BACKUP DOCK</a>
            <a href="smack-multisite-stats.php"     class="btn-clear">STATS</a>
            <a href="smack-multisite-crosspost.php" class="btn-clear">CROSS-POST</a>
            <a href="smack-multisite-blogroll.php"  class="btn-clear">BLOGROLL</a>
            <a href="smack-multisite-settings.php"  class="btn-clear active">SETTINGS</a>
        </div>
    </div>

    <?php if (empty($spokes)): ?>
    <div class="box">
        <p class="dim">No active spokes connected. Connect spokes from the <a href="smack-multisite.php">Dashboard</a>.</p>
    </div>
    <?php else: ?>

    <p class="dim" style="margin-bottom:24px; font-size:0.85rem;">
        Push hub settings to all connected spokes. Each group pushes the hub&rsquo;s current value &mdash; review before pushing.
        Downloads can be targeted to specific spokes only.
    </p>

    <?php
    // Helper: render a push result block
    function render_push_result(array $results): void {
        if (empty($results)) return;
        echo '<div class="push-result-block">';
        foreach ($results as $name => $status) {
            $ok  = $status === 'OK';
            $cls = $ok ? 'push-ok' : 'push-fail';
            echo '<span class="' . $cls . '">' . htmlspecialchars($name) . ': ' . htmlspecialchars($status) . '</span>';
        }
        echo '</div>';
    }
    ?>

    <!-- TIMEZONE & DATE FORMAT -->
    <div class="box">
        <h3>TIMEZONE &amp; DATE FORMAT</h3>
        <div class="dash-grid" style="margin-bottom:16px;">
            <div class="lens-input-wrapper">
                <label>TIMEZONE</label>
                <div class="read-only-display"><?php echo htmlspecialchars($settings['timezone'] ?? 'America/Edmonton'); ?></div>
            </div>
            <div class="lens-input-wrapper">
                <label>DATE FORMAT</label>
                <div class="read-only-display"><?php echo htmlspecialchars($settings['date_format'] ?? 'F j, Y'); ?></div>
            </div>
        </div>
        <?php if (!empty($push_results['push_timezone'])): ?>
            <?php render_push_result($push_results['push_timezone']); ?>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="push_timezone" class="btn-smack" style="width:auto;height:auto;padding:8px 20px;margin-top:0;">
                PUSH TO ALL SPOKES
            </button>
        </form>
    </div>

    <!-- SPAM PROTECTION -->
    <div class="box">
        <h3>SPAM PROTECTION</h3>
        <div class="dash-grid" style="margin-bottom:16px;">
            <div class="lens-input-wrapper">
                <label>AKISMET API KEY</label>
                <div class="read-only-display" style="font-family:monospace;">
                    <?php
                    $key = $settings['akismet_key'] ?? '';
                    echo $key ? '••••••••' . substr($key, -4) : '(not set)';
                    ?>
                </div>
            </div>
        </div>
        <?php if (!empty($push_results['push_akismet'])): ?>
            <?php render_push_result($push_results['push_akismet']); ?>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="push_akismet" class="btn-smack" style="width:auto;height:auto;padding:8px 20px;margin-top:0;"
                <?php echo empty($settings['akismet_key']) ? 'disabled title="No Akismet key set on hub"' : ''; ?>>
                PUSH TO ALL SPOKES
            </button>
        </form>
    </div>

    <!-- AI TRAINING POLICY -->
    <div class="box">
        <h3>AI TRAINING CRAWLERS</h3>
        <div class="dash-grid" style="margin-bottom:16px;">
            <div class="lens-input-wrapper">
                <label>POLICY</label>
                <div class="read-only-display"><?php
                    $pol = $settings['ai_training_policy'] ?? 'no_opinion';
                    echo htmlspecialchars(strtoupper(str_replace('_', ' ', $pol)));
                ?></div>
            </div>
        </div>
        <?php if (!empty($push_results['push_ai'])): ?>
            <?php render_push_result($push_results['push_ai']); ?>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="push_ai" class="btn-smack" style="width:auto;height:auto;padding:8px 20px;margin-top:0;">
                PUSH TO ALL SPOKES
            </button>
        </form>
    </div>

    <!-- SMACKBACK -->
    <div class="box">
        <h3>SMACKBACK &mdash; FILE INTEGRITY</h3>
        <p class="dim" style="font-size:0.85rem;margin-bottom:16px;">
            Pushes the enabled/mode settings only. Re-baselining file hashes on each spoke
            must be done per-site after any skin or file update.
        </p>
        <div class="dash-grid" style="margin-bottom:16px;">
            <div class="lens-input-wrapper">
                <label>ENABLED</label>
                <div class="read-only-display"><?php echo ($settings['smackback_enabled'] ?? '0') === '1' ? 'YES' : 'NO'; ?></div>
            </div>
            <div class="lens-input-wrapper">
                <label>MODE</label>
                <div class="read-only-display"><?php echo htmlspecialchars(strtoupper($settings['smackback_mode'] ?? 'lockout')); ?></div>
            </div>
        </div>
        <?php if (!empty($push_results['push_smackback'])): ?>
            <?php render_push_result($push_results['push_smackback']); ?>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="push_smackback" class="btn-smack" style="width:auto;height:auto;padding:8px 20px;margin-top:0;">
                PUSH TO ALL SPOKES
            </button>
        </form>
    </div>

    <!-- GLOBAL COMMENTS -->
    <div class="box">
        <h3>COMMENTS</h3>
        <div class="dash-grid" style="margin-bottom:16px;">
            <div class="lens-input-wrapper">
                <label>GLOBAL COMMENTS</label>
                <div class="read-only-display"><?php echo ($settings['global_comments_enabled'] ?? '1') === '1' ? 'ENABLED' : 'DISABLED'; ?></div>
            </div>
        </div>
        <?php if (!empty($push_results['push_comments'])): ?>
            <?php render_push_result($push_results['push_comments']); ?>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="push_comments" class="btn-smack" style="width:auto;height:auto;padding:8px 20px;margin-top:0;">
                PUSH TO ALL SPOKES
            </button>
        </form>
    </div>

    <!-- CONTACT EMAIL -->
    <div class="box">
        <h3>CONTACT EMAIL</h3>
        <div class="dash-grid" style="margin-bottom:16px;">
            <div class="lens-input-wrapper">
                <label>SITE EMAIL</label>
                <div class="read-only-display"><?php echo htmlspecialchars($settings['site_email'] ?? '(not set)'); ?></div>
            </div>
        </div>
        <?php if (!empty($push_results['push_email'])): ?>
            <?php render_push_result($push_results['push_email']); ?>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="push_email" class="btn-smack" style="width:auto;height:auto;padding:8px 20px;margin-top:0;"
                <?php echo empty($settings['site_email']) ? 'disabled title="No site email set on hub"' : ''; ?>>
                PUSH TO ALL SPOKES
            </button>
        </form>
    </div>

    <!-- DOWNLOADS — custom spoke selector -->
    <div class="box">
        <h3>DOWNLOADS</h3>
        <p class="dim" style="font-size:0.85rem;margin-bottom:16px;">
            Push to selected spokes only. Selection is saved automatically.
        </p>
        <div class="dash-grid" style="margin-bottom:16px;">
            <div class="lens-input-wrapper">
                <label>REQUIRE DOWNLOAD LINK?</label>
                <div class="read-only-display"><?php echo ($settings['download_link_required'] ?? '0') === '1' ? 'YES — BLOCK PUBLISH IF MISSING' : 'NO — OPTIONAL'; ?></div>
            </div>
            <div class="lens-input-wrapper">
                <label>DEFAULT DOWNLOAD MODE</label>
                <div class="read-only-display"><?php echo ($settings['download_default_mode'] ?? 'per_post') === 'all_posts' ? 'ALL POSTS (ON BY DEFAULT)' : 'PER-POST (ENABLE MANUALLY)'; ?></div>
            </div>
        </div>

        <!-- Spoke selector (saves independently) -->
        <form method="POST" style="margin-bottom:16px;">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <label style="display:block;font-size:0.7rem;letter-spacing:1.5px;text-transform:uppercase;opacity:0.5;margin-bottom:10px;">TARGET SPOKES</label>
            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px;">
                <?php foreach ($spokes as $spoke): ?>
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:0.9rem;">
                    <input type="checkbox" name="dl_spoke_ids[]"
                           value="<?php echo $spoke['id']; ?>"
                           <?php echo in_array((int)$spoke['id'], $dl_spoke_ids, true) ? 'checked' : ''; ?>>
                    <?php echo htmlspecialchars($spoke['site_name'] ?: $spoke['site_url']); ?>
                    <span class="dim" style="font-size:0.75rem;"><?php echo htmlspecialchars($spoke['site_url']); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" name="save_dl_spokes" class="btn-smack"
                    style="width:auto;height:auto;padding:6px 16px;margin-top:0;opacity:0.7;font-size:0.8rem;">
                SAVE SELECTION
            </button>
            <?php if (isset($push_results['dl_spokes']['saved'])): ?>
                <span style="font-size:0.8rem;color:var(--success,#4caf50);margin-left:10px;">Saved ✓</span>
            <?php endif; ?>
        </form>

        <?php if (!empty($push_results['push_downloads'])): ?>
            <?php render_push_result($push_results['push_downloads']); ?>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="push_downloads" class="btn-smack"
                    style="width:auto;height:auto;padding:8px 20px;margin-top:0;"
                    <?php echo empty($dl_spoke_ids) ? 'disabled title="No spokes selected above"' : ''; ?>>
                PUSH TO SELECTED SPOKES
            </button>
        </form>
    </div>

    <?php endif; // spokes ?>
</div>

<style>
.push-result-block { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
.push-ok   { font-size:0.8rem; padding:3px 10px; border-radius:3px; background:rgba(76,175,80,0.15); color:#4caf50; border:1px solid rgba(76,175,80,0.3); }
.push-fail { font-size:0.8rem; padding:3px 10px; border-radius:3px; background:rgba(197,84,0,0.15);  color:#c55400; border:1px solid rgba(197,84,0,0.3); }
</style>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
