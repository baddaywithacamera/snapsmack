<?php
/**
 * SNAPSMACK - Push It (Push It Real Good)
 *
 * Hub-side control over which settings are pushed to spokes and whether
 * the hub owns that setting fleet-wide. When hub_controls is ON for a
 * group, the spoke's corresponding settings UI is locked/hidden.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';

if (!isset($settings)) {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ─── HUB GUARD — spoke admins have nothing to push ───────────────────────────
if (($settings['multisite_role'] ?? '') !== 'hub') {
    header('Location: smack-multisite.php');
    exit;
}

$spokes = $pdo->query("
    SELECT id, site_url, site_name, api_key_local
    FROM snap_multisite_nodes
    WHERE role = 'spoke' AND status = 'active'
    ORDER BY site_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ─── PUSH GROUPS ─────────────────────────────────────────────────────────────
// Each group pushes its settings + its hub_controls flag.
// hub_controls_[group] = '1' → spoke locks that section
// hub_controls_[group] = '0' → spoke unlocks that section

$push_group_keys = [
    'timezone'  => ['timezone', 'date_format',          'hub_controls_timezone'],
    'akismet'   => ['akismet_key',                       'hub_controls_akismet'],
    'ai'        => ['ai_provider', 'ai_key_claude', 'ai_key_gemini', 'ai_key_openai', 'ai_training_policy', 'hub_controls_ai'],
    'smackback' => ['smackback_enabled', 'smackback_mode', 'hub_controls_smackback'],
    'comments'  => ['global_comments_enabled',           'hub_controls_comments'],
    'email'     => ['site_email',                        'hub_controls_email'],
];

// ─── cURL PUSH HELPER ────────────────────────────────────────────────────────
function pushit_push(string $site_url, string $api_key, array $pairs): array {
    $url = rtrim($site_url, '/') . '/api.php?route=multisite/settings/push';
    $ch  = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POSTFIELDS     => http_build_query(['settings' => json_encode($pairs)]),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Accept: application/json',
        ],
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['ok' => false, 'error' => $err];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : ['ok' => false, 'error' => 'Bad response'];
}

function pushit_push_group(array $spokes, array $settings, array $keys, string $hub_controls_key, string $control_val): array {
    $pairs = [];
    foreach ($keys as $k) {
        if (isset($settings[$k])) $pairs[$k] = $settings[$k];
    }
    $pairs[$hub_controls_key] = $control_val;

    $results = [];
    foreach ($spokes as $spoke) {
        $r = pushit_push($spoke['site_url'], $spoke['api_key_local'], $pairs);
        $results[$spoke['site_name'] ?: $spoke['site_url']] = ($r['ok'] ?? false) ? 'OK' : ($r['error'] ?? 'Failed');
    }
    return $results;
}

// ─── SAVE HUB CONTROLS ───────────────────────────────────────────────────────
$push_results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['csrf']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'])) {

    $upsert = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    );

    // Save all hub_controls toggles from the form
    foreach (array_keys($push_group_keys) as $group) {
        $ctrl_key = 'hub_controls_' . $group;
        $ctrl_val = isset($_POST['hub_controls'][$group]) ? '1' : '0';
        $upsert->execute([$ctrl_key, $ctrl_val]);
        $settings[$ctrl_key] = $ctrl_val; // update in-memory
    }

    // Individual group push
    foreach (array_keys($push_group_keys) as $group) {
        if (!isset($_POST['push_' . $group])) continue;
        $ctrl_val = ($settings['hub_controls_' . $group] ?? '0');
        $push_results[$group] = pushit_push_group(
            $spokes, $settings,
            $push_group_keys[$group],
            'hub_controls_' . $group,
            $ctrl_val
        );
    }

    // PUSH IT ALL — push every hub-controlled group
    if (isset($_POST['push_all'])) {
        foreach (array_keys($push_group_keys) as $group) {
            $ctrl_key = 'hub_controls_' . $group;
            if (($settings[$ctrl_key] ?? '0') !== '1') continue;
            $push_results[$group] = pushit_push_group(
                $spokes, $settings,
                $push_group_keys[$group],
                $ctrl_key,
                '1'
            );
        }
    }
}

// Re-read hub_controls from DB (after saves)
foreach (array_keys($push_group_keys) as $group) {
    $ctrl_key = 'hub_controls_' . $group;
    if (!isset($settings[$ctrl_key])) {
        $settings[$ctrl_key] = '0';
    }
}

$hub_controlled_count = count(array_filter(
    array_keys($push_group_keys),
    fn($g) => ($settings['hub_controls_' . $g] ?? '0') === '1'
));

$ai_prov = $settings['ai_provider'] ?? 'none';
$ai_prov_labels = ['claude' => 'Claude (Anthropic)', 'gemini' => 'Gemini (Google)', 'openai' => 'ChatGPT (OpenAI)', 'none' => 'None'];
$ai_key_map = ['claude' => 'ai_key_claude', 'gemini' => 'ai_key_gemini', 'openai' => 'ai_key_openai'];
$ai_has_key = !empty($settings[$ai_key_map[$ai_prov] ?? ''] ?? '');

// ─── PAGE RENDER ─────────────────────────────────────────────────────────────

$page_title = 'Push It';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>PUSH IT</h2>
    </div>
    <p class="dim" style="font-size:0.95rem;margin-bottom:4px;letter-spacing:2px;text-transform:uppercase;">Push it real good.</p>

    <div class="signal-control-header" style="margin:16px 0 24px;">
        <div class="signal-nav-group">
            <a href="smack-multisite.php"           class="btn-clear">DASHBOARD</a>
            <a href="smack-multisite-comments.php"  class="btn-clear">SIGNALS</a>
            <a href="smack-multisite-posts.php"     class="btn-clear">POSTS</a>
            <a href="smack-multisite-backup.php"    class="btn-clear">BACKUP DOCK</a>
            <a href="smack-multisite-stats.php"     class="btn-clear">STATS</a>
            <a href="smack-multisite-crosspost.php" class="btn-clear">CROSS-POST</a>
            <a href="smack-multisite-blogroll.php"  class="btn-clear">BLOGROLL</a>
            <a href="smack-multisite-settings.php"  class="btn-clear">SETTINGS</a>
            <a href="smack-push-it.php"             class="btn-clear active">PUSH IT</a>
        </div>
    </div>

    <?php if (empty($spokes)): ?>
    <div class="box">
        <p class="dim">No active spokes connected. Connect spokes from the <a href="smack-multisite.php">Dashboard</a>.</p>
    </div>
    <?php else: ?>

    <div class="box">
        <p class="dim" style="line-height:1.7;font-size:0.88rem;">
            Toggle <strong>HUB CONTROLS</strong> on a setting group to own it fleet-wide.
            When on, that section is locked on every spoke — spokes can't change it locally.
            When off, spokes manage their own value. Push anytime to sync current hub values.
            <br><br>
            Changes to toggles are saved when you click any PUSH button or PUSH ALL.
        </p>
    </div>

    <form method="POST">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

        <?php
        // Helper: render push results for a group
        $render_result = function(string $group) use ($push_results): void {
            if (empty($push_results[$group])) return;
            echo '<div class="push-result-block" style="margin:10px 0;">';
            foreach ($push_results[$group] as $name => $status) {
                $ok  = $status === 'OK';
                echo '<span class="' . ($ok ? 'push-ok' : 'push-fail') . '">'
                   . htmlspecialchars($name) . ': ' . htmlspecialchars($status) . '</span>';
            }
            echo '</div>';
        };
        ?>

        <!-- ── TIMEZONE & DATE FORMAT ───────────────────────────────────── -->
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
                <div class="lens-input-wrapper">
                    <label>
                        <input type="checkbox" name="hub_controls[timezone]" value="1"
                               <?php echo ($settings['hub_controls_timezone'] ?? '0') === '1' ? 'checked' : ''; ?>>
                        HUB CONTROLS THIS SETTING
                    </label>
                    <span class="dim" style="font-size:0.82rem;">When on, spokes cannot change their timezone or date format.</span>
                </div>
            </div>
            <?php $render_result('timezone'); ?>
            <button type="submit" name="push_timezone" class="btn-smack">PUSH TO ALL SPOKES</button>
        </div>

        <!-- ── SPAM PROTECTION ──────────────────────────────────────────── -->
        <div class="box">
            <h3>SPAM PROTECTION</h3>
            <div class="dash-grid" style="margin-bottom:16px;">
                <div class="lens-input-wrapper">
                    <label>AKISMET API KEY</label>
                    <div class="read-only-display" style="font-family:monospace;">
                        <?php $ak = $settings['akismet_key'] ?? '';
                        echo $ak ? '••••••••' . htmlspecialchars(substr($ak, -4)) : '<span class="dim">(not set)</span>'; ?>
                    </div>
                </div>
                <div class="lens-input-wrapper">
                    <label>
                        <input type="checkbox" name="hub_controls[akismet]" value="1"
                               <?php echo ($settings['hub_controls_akismet'] ?? '0') === '1' ? 'checked' : ''; ?>>
                        HUB CONTROLS THIS SETTING
                    </label>
                    <span class="dim" style="font-size:0.82rem;">When on, spokes cannot change their Akismet key.</span>
                </div>
            </div>
            <?php $render_result('akismet'); ?>
            <button type="submit" name="push_akismet" class="btn-smack"
                    <?php echo empty($ak) ? 'disabled title="No Akismet key set on hub"' : ''; ?>>
                PUSH TO ALL SPOKES
            </button>
        </div>

        <!-- ── AI SETTINGS ──────────────────────────────────────────────── -->
        <div class="box">
            <h3>AI SETTINGS</h3>
            <div class="dash-grid" style="margin-bottom:16px;">
                <div class="lens-input-wrapper">
                    <label>PROVIDER</label>
                    <div class="read-only-display"><?php echo htmlspecialchars($ai_prov_labels[$ai_prov] ?? strtoupper($ai_prov)); ?></div>
                </div>
                <div class="lens-input-wrapper">
                    <label>API KEY</label>
                    <div class="read-only-display"><?php echo $ai_has_key ? '<span class="msg">SET</span>' : '<span class="dim">NOT SET</span>'; ?></div>
                </div>
                <div class="lens-input-wrapper">
                    <label>CRAWLER POLICY</label>
                    <div class="read-only-display"><?php
                        $pol = $settings['ai_training_policy'] ?? 'no_opinion';
                        echo htmlspecialchars(strtoupper(str_replace('_', ' ', $pol)));
                    ?></div>
                </div>
                <div class="lens-input-wrapper">
                    <label>
                        <input type="checkbox" name="hub_controls[ai]" value="1"
                               <?php echo ($settings['hub_controls_ai'] ?? '0') === '1' ? 'checked' : ''; ?>>
                        HUB CONTROLS THIS SETTING
                    </label>
                    <span class="dim" style="font-size:0.82rem;">When on, spokes cannot change their AI provider, keys, or crawler policy.</span>
                </div>
            </div>
            <?php $render_result('ai'); ?>
            <button type="submit" name="push_ai" class="btn-smack"
                    <?php echo ($ai_prov === 'none' || !$ai_has_key) ? 'disabled title="No AI provider configured on hub"' : ''; ?>>
                PUSH TO ALL SPOKES
            </button>
        </div>

        <!-- ── SMACKBACK ─────────────────────────────────────────────────── -->
        <div class="box">
            <h3>SMACKBACK — FILE INTEGRITY</h3>
            <div class="dash-grid" style="margin-bottom:16px;">
                <div class="lens-input-wrapper">
                    <label>ENABLED</label>
                    <div class="read-only-display"><?php echo ($settings['smackback_enabled'] ?? '0') === '1' ? 'YES' : 'NO'; ?></div>
                </div>
                <div class="lens-input-wrapper">
                    <label>RESPONSE MODE</label>
                    <div class="read-only-display"><?php echo htmlspecialchars(strtoupper($settings['smackback_mode'] ?? 'lockout')); ?></div>
                </div>
                <div class="lens-input-wrapper">
                    <label>
                        <input type="checkbox" name="hub_controls[smackback]" value="1"
                               <?php echo ($settings['hub_controls_smackback'] ?? '0') === '1' ? 'checked' : ''; ?>>
                        HUB CONTROLS THIS SETTING
                    </label>
                    <span class="dim" style="font-size:0.82rem;">When on, spokes cannot change their SMACKBACK enabled state or response mode.</span>
                </div>
            </div>
            <?php $render_result('smackback'); ?>
            <button type="submit" name="push_smackback" class="btn-smack">PUSH TO ALL SPOKES</button>
        </div>

        <!-- ── GLOBAL COMMENTS ───────────────────────────────────────────── -->
        <div class="box">
            <h3>GLOBAL COMMENTS</h3>
            <div class="dash-grid" style="margin-bottom:16px;">
                <div class="lens-input-wrapper">
                    <label>COMMENTS</label>
                    <div class="read-only-display"><?php echo ($settings['global_comments_enabled'] ?? '1') === '1' ? 'ENABLED' : 'DISABLED (KILL-SWITCH)'; ?></div>
                </div>
                <div class="lens-input-wrapper">
                    <label>
                        <input type="checkbox" name="hub_controls[comments]" value="1"
                               <?php echo ($settings['hub_controls_comments'] ?? '0') === '1' ? 'checked' : ''; ?>>
                        HUB CONTROLS THIS SETTING
                    </label>
                    <span class="dim" style="font-size:0.82rem;">When on, spokes cannot toggle their global comments switch.</span>
                </div>
            </div>
            <?php $render_result('comments'); ?>
            <button type="submit" name="push_comments" class="btn-smack">PUSH TO ALL SPOKES</button>
        </div>

        <!-- ── CONTACT EMAIL ─────────────────────────────────────────────── -->
        <div class="box">
            <h3>CONTACT EMAIL</h3>
            <div class="dash-grid" style="margin-bottom:16px;">
                <div class="lens-input-wrapper">
                    <label>SITE EMAIL</label>
                    <div class="read-only-display"><?php echo htmlspecialchars($settings['site_email'] ?? '(not set)'); ?></div>
                </div>
                <div class="lens-input-wrapper">
                    <label>
                        <input type="checkbox" name="hub_controls[email]" value="1"
                               <?php echo ($settings['hub_controls_email'] ?? '0') === '1' ? 'checked' : ''; ?>>
                        HUB CONTROLS THIS SETTING
                    </label>
                    <span class="dim" style="font-size:0.82rem;">When on, spokes cannot change their site contact email.</span>
                </div>
            </div>
            <?php $render_result('email'); ?>
            <button type="submit" name="push_email" class="btn-smack"
                    <?php echo empty($settings['site_email']) ? 'disabled title="No site email set on hub"' : ''; ?>>
                PUSH TO ALL SPOKES
            </button>
        </div>

        <!-- ── PUSH ALL ──────────────────────────────────────────────────── -->
        <div class="box">
            <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
                <div>
                    <strong><?php echo $hub_controlled_count; ?> of <?php echo count($push_group_keys); ?> setting groups under hub control.</strong>
                    <span class="dim" style="font-size:0.85rem;display:block;margin-top:4px;">
                        PUSH ALL pushes every hub-controlled group simultaneously.
                        Spoke-only groups are skipped.
                    </span>
                </div>
                <button type="submit" name="push_all" class="master-update-btn"
                        <?php echo $hub_controlled_count === 0 ? 'disabled title="No groups set to hub control"' : ''; ?>>
                    PUSH IT ALL
                </button>
            </div>
            <?php
            // Show combined results from push_all
            $any_all_results = false;
            foreach (array_keys($push_group_keys) as $group) {
                if (!empty($push_results[$group]) && isset($_POST['push_all'])) {
                    $any_all_results = true; break;
                }
            }
            if ($any_all_results && isset($_POST['push_all'])):
            ?>
            <div style="margin-top:16px;">
                <?php foreach (array_keys($push_group_keys) as $group): ?>
                    <?php if (!empty($push_results[$group])): ?>
                        <div style="margin-bottom:6px;font-size:0.82rem;"><strong style="text-transform:uppercase;"><?php echo $group; ?>:</strong> <?php $render_result($group); ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </form>

    <?php endif; // spokes ?>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
