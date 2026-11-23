<?php
/**
 * SNAPSMACK - Community Settings
 *
 * Admin panel for configuring the community system:
 *   - Global feature toggles (community on/off, comments, likes)
 *   - Community dock position (8-position picker, same as social dock)
 *   - Email settings for verification and password reset
 *   - Rate limiting thresholds
 *   - Session lifetime
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';

// --- SAVE ---
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_community'])) {

    $saves = [
        // Global toggles
        'community_enabled'           => ($_POST['community_enabled']           ?? '0') === '1' ? '1' : '0',
        'community_comments_enabled'  => ($_POST['community_comments_enabled']  ?? '0') === '1' ? '1' : '0',
        'community_likes_enabled'     => ($_POST['community_likes_enabled']     ?? '0') === '1' ? '1' : '0',

        // Comment identity
        'comment_identity'            => in_array($_POST['comment_identity'] ?? 'open', ['open', 'hybrid', 'registered'])
                                            ? ($_POST['comment_identity'] ?? 'open')
                                            : 'open',

        // Dock position
        'community_dock_position'     => $_POST['community_dock_position'] ?? 'bottom-right',

        // Email
        'community_email_from'        => trim($_POST['community_email_from']      ?? ''),
        'community_email_from_name'   => trim($_POST['community_email_from_name'] ?? ''),
        'community_require_verification' => ($_POST['community_require_verification'] ?? '0') === '1' ? '1' : '0',

        // Session
        'community_session_days'      => max(1, min(365, (int)($_POST['community_session_days'] ?? 30))),

        // Rate limits
        'rate_limit_comments'         => max(1, min(1000, (int)($_POST['rate_limit_comments'] ?? 10))),
        'rate_limit_signups'          => max(1, min(100,  (int)($_POST['rate_limit_signups']  ?? 3))),
        'rate_limit_logins'           => max(1, min(100,  (int)($_POST['rate_limit_logins']   ?? 10))),
        'rate_limit_resets'           => max(1, min(100,  (int)($_POST['rate_limit_resets']   ?? 3))),

        // Shield — hub/spoke ban sync (only meaningful on hub installs; harmless on spokes)
        'hub_spoke_ban_sync'          => ($_POST['hub_spoke_ban_sync'] ?? '0') === '1' ? '1' : '0',
    ];

    // Validate dock position
    $valid_positions = ['top-left','top-right','bottom-left','bottom-right','left-top','left-bottom','right-top','right-bottom'];
    if (!in_array($saves['community_dock_position'], $valid_positions, true)) {
        $saves['community_dock_position'] = 'bottom-right';
    }

    // Persist all settings
    $stmt = $pdo->prepare("
        INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)
    ");
    foreach ($saves as $key => $val) {
        $stmt->execute([$key, (string)$val]);
    }

    $msg = 'Community settings saved.';

    // Reload fresh from DB
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Shield — hub-specific data for status display
$is_hub          = ($settings['multisite_role'] ?? '') === 'hub';
$shield_nodes    = [];
$shield_ban_count = 0;
if ($is_hub) {
    try {
        $shield_nodes = $pdo->query("
            SELECT id, site_url, status, ban_sync_cursor
            FROM snap_multisite_nodes
            WHERE role = 'spoke'
            ORDER BY site_url ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $shield_ban_count = (int)$pdo->query(
            "SELECT COUNT(*) FROM snap_hub_shared_bans WHERE removed = 0"
        )->fetchColumn();
    } catch (PDOException $e) {
        // Tables may not exist on very old installs — Shield section will show upgrade note
    }
}

$page_title = "Interaction";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>COMMUNITY</h2>
        <div class="header-actions">
            <a href="smack-community-users.php" class="btn-smack">MANAGE MEMBERS</a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success">&gt; <?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <form method="POST">
    <input type="hidden" name="save_community" value="1">

    <!-- ====================================================================
         SECTION 1: GLOBAL TOGGLES
         ==================================================================== -->
    <div class="box">
        <h3>SYSTEM TOGGLES</h3>

        <div class="option-group">
            <label class="toggle-row">
                <span class="toggle-label">COMMUNITY SYSTEM</span>
                <span class="toggle-desc">Master switch. Turns off all community features site-wide.</span>
                <input type="hidden"   name="community_enabled" value="0">
                <input type="checkbox" name="community_enabled" value="1"
                    <?php echo ($settings['community_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
            </label>

            <label class="toggle-row">
                <span class="toggle-label">COMMENTS</span>
                <span class="toggle-desc">Enable the comment thread on photo pages.</span>
                <input type="hidden"   name="community_comments_enabled" value="0">
                <input type="checkbox" name="community_comments_enabled" value="1"
                    <?php echo ($settings['community_comments_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
            </label>

            <div class="field-row identity-row">
                <label for="comment_identity">COMMENT IDENTITY</label>
                <select id="comment_identity" name="comment_identity">
                    <?php
                    $ci = $settings['comment_identity'] ?? 'open';
                    $ci_opts = [
                        'open'       => 'OPEN — ANYONE CAN COMMENT WITH A NAME (DEFAULT)',
                        'hybrid'     => 'HYBRID — ACCOUNTS GET FULL IDENTITY; GUESTS WELCOME',
                        'registered' => 'REGISTERED — COMMUNITY ACCOUNT REQUIRED',
                    ];
                    foreach ($ci_opts as $val => $label):
                    ?>
                    <option value="<?php echo $val; ?>" <?php echo $ci === $val ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label class="toggle-row">
                <span class="toggle-label">LIKES</span>
                <span class="toggle-desc">Show the heart button on the floating dock.</span>
                <input type="hidden"   name="community_likes_enabled" value="0">
                <input type="checkbox" name="community_likes_enabled" value="1"
                    <?php echo ($settings['community_likes_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
            </label>

            <label class="toggle-row">
                <span class="toggle-label">EMAIL VERIFICATION REQUIRED</span>
                <span class="toggle-desc">Visitors must verify their email before commenting or liking.</span>
                <input type="hidden"   name="community_require_verification" value="0">
                <input type="checkbox" name="community_require_verification" value="1"
                    <?php echo ($settings['community_require_verification'] ?? '1') === '1' ? 'checked' : ''; ?>>
            </label>
        </div>
    </div>


    <!-- ====================================================================
         SECTION 2: DOCK POSITION
         ==================================================================== -->
    <div class="box">
        <h3>DOCK POSITION</h3>
        <p class="box-desc">
            Where the likes dock floats on the photo page.
            If the social dock is in the same corner, the community dock shifts inward automatically.
        </p>

        <?php
        $dock_pos = $settings['community_dock_position'] ?? 'bottom-right';
        $positions = [
            'top-left'      => 'TOP LEFT',
            'top-right'     => 'TOP RIGHT',
            'bottom-left'   => 'BOTTOM LEFT',
            'bottom-right'  => 'BOTTOM RIGHT',
            'left-top'      => 'LEFT SIDE (TOP)',
            'left-bottom'   => 'LEFT SIDE (BOTTOM)',
            'right-top'     => 'RIGHT SIDE (TOP)',
            'right-bottom'  => 'RIGHT SIDE (BOTTOM)',
        ];
        ?>
        <div class="radio-grid">
            <?php foreach ($positions as $val => $label): ?>
            <label class="radio-option <?php echo $dock_pos === $val ? 'is-selected' : ''; ?>">
                <input type="radio" name="community_dock_position" value="<?php echo $val; ?>"
                       <?php echo $dock_pos === $val ? 'checked' : ''; ?>>
                <?php echo $label; ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>


    <!-- ====================================================================
         SECTION 4: EMAIL
         ==================================================================== -->
    <div class="box">
        <h3>EMAIL</h3>
        <p class="box-desc">
            Used for verification emails and password resets. Leave blank to use
            the server's default PHP mail sender.
        </p>

        <div class="field-row">
            <label for="community_email_from">FROM ADDRESS</label>
            <input type="email" id="community_email_from" name="community_email_from"
                   value="<?php echo htmlspecialchars($settings['community_email_from'] ?? ''); ?>"
                   placeholder="noreply@yourdomain.com">
        </div>

        <div class="field-row">
            <label for="community_email_from_name">FROM NAME</label>
            <input type="text" id="community_email_from_name" name="community_email_from_name"
                   value="<?php echo htmlspecialchars($settings['community_email_from_name'] ?? ''); ?>"
                   placeholder="Your Blog Name">
        </div>
    </div>


    <!-- ====================================================================
         SECTION 5: SESSIONS & RATE LIMITS
         ==================================================================== -->
    <div class="box">
        <h3>SESSIONS &amp; RATE LIMITS</h3>
        <p class="box-desc">
            Session lifetime controls how long visitors stay logged in.
            Rate limits are per IP per hour — a safeguard against spam, not a wall.
        </p>

        <div class="field-row">
            <label for="community_session_days">SESSION LIFETIME (DAYS)</label>
            <input type="number" id="community_session_days" name="community_session_days" min="1" max="365"
                   value="<?php echo (int)($settings['community_session_days'] ?? 30); ?>">
        </div>

        <div class="rate-limit-grid">
            <?php
            $limits = [
                'rate_limit_comments' => ['COMMENTS / HOUR',  10],
                'rate_limit_signups'  => ['SIGNUPS / HOUR',    3],
                'rate_limit_logins'   => ['LOGINS / HOUR',     10],
                'rate_limit_resets'   => ['RESETS / HOUR',     3],
            ];
            foreach ($limits as $key => [$label, $default]):
            ?>
            <div class="field-row">
                <label for="<?php echo $key; ?>"><?php echo $label; ?></label>
                <input type="number" id="<?php echo $key; ?>" name="<?php echo $key; ?>" min="1" max="1000"
                       value="<?php echo (int)($settings[$key] ?? $default); ?>">
            </div>
            <?php endforeach; ?>
        </div>
    </div>


    <!-- ====================================================================
         SECTION 6: SHIELD — HUB/SPOKE BAN SYNC (hub installs only)
         ==================================================================== -->
    <?php if ($is_hub): ?>
    <div class="box">
        <h3>SHIELD — BAN SYNC</h3>
        <p class="box-desc">
            When enabled, the hub shares hashed ban lists with all connected spokes during each heartbeat sweep.
            Only SHA-256 hashes are transmitted — no raw IPs, emails, or identifying information ever leaves a site.
            Bans originating from the hub are silently merged on each spoke; new bans on any spoke are collected
            back to the hub registry and distributed on the next cycle.
        </p>

        <div class="field-row">
            <label for="hub_spoke_ban_sync">ENABLE HUB/SPOKE BAN SYNC</label>
            <label class="toggle">
                <input type="checkbox" id="hub_spoke_ban_sync" name="hub_spoke_ban_sync" value="1"
                       <?php echo ($settings['hub_spoke_ban_sync'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span class="toggle-track"></span>
            </label>
        </div>

        <?php if (!empty($shield_nodes)): ?>
        <div class="mt-20">
            <h4 style="margin-bottom:10px; font-size:0.85em; letter-spacing:0.05em; color:var(--text-muted,#888);">SPOKE SYNC STATUS</h4>
            <table class="admin-table w-100">
                <tbody>
                <tr style="font-weight:bold; font-size:0.85em;">
                    <td>Spoke</td>
                    <td>Status</td>
                    <td>Last Sync</td>
                </tr>
                <?php foreach ($shield_nodes as $sn):
                    $cursor   = $sn['ban_sync_cursor'];
                    $status   = $sn['status'];
                    $hostname = parse_url($sn['site_url'], PHP_URL_HOST) ?: $sn['site_url'];
                    $sync_display = $cursor
                        ? date('Y-m-d H:i', strtotime($cursor))
                        : '<span class="text-muted">Never synced</span>';
                    $status_color = $status === 'active' ? 'var(--accent,#7aad5a)' : 'var(--text-muted,#888)';
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($hostname); ?></td>
                    <td><span style="color:<?php echo $status_color; ?>;"><?php echo htmlspecialchars(strtoupper($status)); ?></span></td>
                    <td><?php echo $sync_display; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($shield_ban_count > 0): ?>
        <p style="margin-top:16px; font-size:0.9em;">
            <strong><?php echo $shield_ban_count; ?></strong> shared ban<?php echo $shield_ban_count !== 1 ? 's' : ''; ?> in hub registry.
            <a href="smack-fingerprints.php" class="ml-8">View Shared Bans →</a>
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>


    <!-- ====================================================================
         SAVE
         ==================================================================== -->
    <div class="box">
        <button type="submit" class="btn-smack btn-large">SAVE COMMUNITY SETTINGS</button>
    </div>

    </form>

</div><!-- /.main -->

<script src="assets/js/admin-community-settings.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
