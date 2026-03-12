<?php
/**
 * SNAPSMACK - Community Settings
 * Alpha v0.7.3
 *
 * Admin panel for configuring the community system:
 *   - Global feature toggles (community on/off, comments, likes, reactions)
 *   - Community dock position (8-position picker, same as social dock)
 *   - Active reaction set (up to 6 from the full registry; thumbs-down optional)
 *   - Email settings for verification and password reset
 *   - Rate limiting thresholds
 *   - Session lifetime
 */

require_once 'core/auth.php';

// --- FULL REACTION REGISTRY ---
// Mirrors core/community-dock.php and process-reaction.php.
$reaction_registry = [
    'fire'         => ['emoji' => '🔥', 'label' => 'Fire'],
    'chef-kiss'    => ['emoji' => '🤌', 'label' => 'Chef\'s kiss'],
    'wow'          => ['emoji' => '😮', 'label' => 'Wow'],
    'moody'        => ['emoji' => '🌧️', 'label' => 'Moody'],
    'sharp'        => ['emoji' => '💎', 'label' => 'Sharp'],
    'golden-hour'  => ['emoji' => '🌅', 'label' => 'Golden hour'],
    'cinematic'    => ['emoji' => '🎬', 'label' => 'Cinematic'],
    'peaceful'     => ['emoji' => '🕊️', 'label' => 'Peaceful'],
    'haunting'     => ['emoji' => '👁️', 'label' => 'Haunting'],
    'story'        => ['emoji' => '📖', 'label' => 'Tells a story'],
    'colours'      => ['emoji' => '🎨', 'label' => 'The colours'],
    'light'        => ['emoji' => '✨', 'label' => 'The light'],
    'texture'      => ['emoji' => '🪨', 'label' => 'Texture'],
    'timing'       => ['emoji' => '⚡', 'label' => 'Perfect timing'],
    'composition'  => ['emoji' => '🔲', 'label' => 'Composition'],
];

// --- SAVE ---
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_community'])) {

    $saves = [
        // Global toggles
        'community_enabled'           => ($_POST['community_enabled']           ?? '0') === '1' ? '1' : '0',
        'community_comments_enabled'  => ($_POST['community_comments_enabled']  ?? '0') === '1' ? '1' : '0',
        'community_likes_enabled'     => ($_POST['community_likes_enabled']     ?? '0') === '1' ? '1' : '0',
        'community_reactions_enabled' => ($_POST['community_reactions_enabled'] ?? '0') === '1' ? '1' : '0',
        'community_allow_dislike'     => ($_POST['community_allow_dislike']     ?? '0') === '1' ? '1' : '0',

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
        'rate_limit_likes'            => max(1, min(1000, (int)($_POST['rate_limit_likes']    ?? 60))),
        'rate_limit_signups'          => max(1, min(100,  (int)($_POST['rate_limit_signups']  ?? 3))),
        'rate_limit_logins'           => max(1, min(100,  (int)($_POST['rate_limit_logins']   ?? 10))),
        'rate_limit_resets'           => max(1, min(100,  (int)($_POST['rate_limit_resets']   ?? 3))),
    ];

    // Validate dock position
    $valid_positions = ['top-left','top-right','bottom-left','bottom-right','left-top','left-bottom','right-top','right-bottom'];
    if (!in_array($saves['community_dock_position'], $valid_positions, true)) {
        $saves['community_dock_position'] = 'bottom-right';
    }

    // Active reactions: collect checked boxes (max 6, from registry only, no thumbs-down here)
    $checked = $_POST['active_reactions'] ?? [];
    $active  = [];
    foreach ($checked as $code) {
        if (isset($reaction_registry[$code]) && count($active) < 6) {
            $active[] = $code;
        }
    }
    $saves['community_active_reactions'] = json_encode($active, JSON_UNESCAPED_UNICODE);

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

// --- LOAD CURRENT SETTINGS ---
$active_reactions_raw = $settings['community_active_reactions'] ?? '["fire","chef-kiss","wow","moody","sharp","golden-hour"]';
$active_reactions     = json_decode($active_reactions_raw, true) ?: [];

$page_title = "Community Settings";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>COMMUNITY SETTINGS</h2>
        <div class="header-actions">
            <a href="smack-community-users.php" class="btn-smack">MANAGE MEMBERS</a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="msg">> <?php echo htmlspecialchars($msg); ?></div>
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
                <span class="toggle-label">REACTIONS</span>
                <span class="toggle-desc">Show the reaction picker on the floating dock.</span>
                <input type="hidden"   name="community_reactions_enabled" value="0">
                <input type="checkbox" name="community_reactions_enabled" value="1"
                    <?php echo ($settings['community_reactions_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
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
            Where the likes &amp; reactions dock floats on the photo page.
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
         SECTION 3: ACTIVE REACTIONS
         ==================================================================== -->
    <div class="box">
        <h3>REACTIONS</h3>
        <p class="box-desc">
            Choose up to <strong>6</strong> reactions to show in the dock picker.
            Reactions are photography-specific — no generic social media emojis.
        </p>

        <div class="reaction-picker-grid">
            <?php $checked_count = 0; ?>
            <?php foreach ($reaction_registry as $code => $rx):
                $is_checked = in_array($code, $active_reactions, true);
                if ($is_checked) $checked_count++;
            ?>
            <label class="reaction-option <?php echo $is_checked ? 'is-active' : ''; ?>"
                   data-code="<?php echo htmlspecialchars($code); ?>">
                <input type="checkbox" name="active_reactions[]" value="<?php echo htmlspecialchars($code); ?>"
                       <?php echo $is_checked ? 'checked' : ''; ?>>
                <span class="rx-emoji"><?php echo $rx['emoji']; ?></span>
                <span class="rx-label"><?php echo htmlspecialchars($rx['label']); ?></span>
            </label>
            <?php endforeach; ?>
        </div>

        <p class="reaction-count-note">
            <span id="rx-count-display"><?php echo $checked_count; ?></span> of 6 selected
        </p>

        <div class="option-group" style="margin-top: 1.5rem; border-top: 1px solid var(--border); padding-top: 1.5rem;">
            <label class="toggle-row">
                <span class="toggle-label">👎 HONEST FEEDBACK</span>
                <span class="toggle-desc">
                    Add a thumbs-down reaction. Brave, but useful — visitors can give constructive feedback
                    without leaving a comment. Does not count toward the 6-reaction limit.
                </span>
                <input type="hidden"   name="community_allow_dislike" value="0">
                <input type="checkbox" name="community_allow_dislike" value="1"
                    <?php echo ($settings['community_allow_dislike'] ?? '0') === '1' ? 'checked' : ''; ?>>
            </label>
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
                'rate_limit_likes'    => ['LIKES / HOUR',      60],
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
         SAVE
         ==================================================================== -->
    <div class="box">
        <button type="submit" class="btn-smack btn-large">SAVE COMMUNITY SETTINGS</button>
    </div>

    </form>

</div><!-- /.main -->

<style>
/* ---- Community config page styles ---- */

.box-desc {
    color: var(--text-muted, #888);
    font-size: 0.85rem;
    margin: -0.25rem 0 1.25rem;
    line-height: 1.5;
}

/* Toggle rows (checkbox settings) */
.option-group { display: flex; flex-direction: column; gap: 1rem; }
.toggle-row {
    display: grid;
    grid-template-columns: 1fr auto;
    grid-template-rows: auto auto;
    gap: 0.15rem 1rem;
    cursor: pointer;
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--border, #333);
}
.toggle-row:last-child { border-bottom: none; }
.toggle-label {
    grid-column: 1; grid-row: 1;
    font-weight: 600; font-size: 0.8rem; letter-spacing: 0.05em;
}
.toggle-desc {
    grid-column: 1; grid-row: 2;
    font-size: 0.8rem; color: var(--text-muted, #888); line-height: 1.4;
}
.toggle-row input[type="checkbox"] {
    grid-column: 2; grid-row: 1 / 3;
    align-self: center;
    width: 1.1rem; height: 1.1rem;
    cursor: pointer;
}

/* Dock position radio grid */
.radio-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 0.5rem;
}
.radio-option {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border, #444);
    border-radius: 4px;
    font-size: 0.78rem; font-weight: 600; letter-spacing: 0.04em;
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
}
.radio-option:hover      { border-color: var(--accent, #9dff00); }
.radio-option.is-selected,
.radio-option input:checked ~ * { /* fallback */ }
.radio-option input[type="radio"] { margin: 0; }
.radio-option:has(input:checked) {
    border-color: var(--accent, #9dff00);
    background: color-mix(in srgb, var(--accent, #9dff00) 8%, transparent);
}

/* Reaction picker grid */
.reaction-picker-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 0.5rem;
}
.reaction-option {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0.6rem 0.75rem;
    border: 1px solid var(--border, #444);
    border-radius: 6px;
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s, opacity 0.15s;
}
.reaction-option:hover {
    border-color: var(--accent, #9dff00);
}
.reaction-option.is-active,
.reaction-option:has(input:checked) {
    border-color: var(--accent, #9dff00);
    background: color-mix(in srgb, var(--accent, #9dff00) 10%, transparent);
}
.reaction-option input[type="checkbox"] { display: none; }
.rx-emoji  { font-size: 1.3rem; line-height: 1; flex-shrink: 0; }
.rx-label  { font-size: 0.78rem; font-weight: 600; letter-spacing: 0.03em; }

.reaction-count-note {
    margin-top: 0.75rem;
    font-size: 0.82rem;
    color: var(--text-muted, #888);
}

/* Field rows (text/number inputs) */
.field-row {
    display: grid;
    grid-template-columns: 200px 1fr;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}
.field-row label {
    font-size: 0.78rem; font-weight: 600; letter-spacing: 0.04em;
}
.field-row input[type="text"],
.field-row input[type="email"],
.field-row input[type="number"],
.field-row select {
    width: 100%; max-width: 420px;
    padding: 0.4rem 0.6rem;
    background: var(--input-bg, #111);
    border: 1px solid var(--border, #444);
    color: var(--text, #eee);
    border-radius: 3px;
    font-size: 0.82rem;
}
.identity-row {
    padding: 0.4rem 0 0.75rem;
    border-bottom: 1px solid var(--border, #333);
}
.rate-limit-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 0 1.5rem;
}

.btn-large { padding: 0.6rem 2rem; font-size: 0.9rem; }
</style>

<script>
(function () {
    // Live reaction counter — disable checkboxes beyond 6 selections
    var MAX = 6;
    var grid = document.querySelector('.reaction-picker-grid');
    var countDisplay = document.getElementById('rx-count-display');
    if (!grid || !countDisplay) return;

    function update() {
        var boxes = grid.querySelectorAll('input[type="checkbox"]');
        var checked = 0;
        boxes.forEach(function (cb) { if (cb.checked) checked++; });
        countDisplay.textContent = checked;
        boxes.forEach(function (cb) {
            var label = cb.closest('.reaction-option');
            if (!cb.checked && checked >= MAX) {
                cb.disabled = true;
                label.style.opacity = '0.4';
            } else {
                cb.disabled = false;
                label.style.opacity  = '';
            }
            label.classList.toggle('is-active', cb.checked);
        });
    }

    grid.addEventListener('change', update);
    update(); // run once on load

    // Radio option highlight sync for dock position
    document.querySelectorAll('.radio-option').forEach(function (label) {
        label.querySelector('input').addEventListener('change', function () {
            document.querySelectorAll('.radio-option').forEach(function (l) {
                l.classList.toggle('is-selected', l.querySelector('input').checked);
            });
        });
    });
})();
</script>

<?php include 'core/admin-footer.php'; ?>
