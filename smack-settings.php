<?php
/**
 * SNAPSMACK - Global site configuration
 *
 * Manages site identity, branding, navigation, footer layout, and image processing parameters.
 * Handles logo and favicon uploads, timezone settings, and feature toggles.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth.php';
require_once 'core/ste-client.php';

// --- AKISMET KEY TEST (AJAX) ---
if (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest' &&
    ($_POST['action'] ?? '') === 'akismet_test'
) {
    $key = trim($_POST['key'] ?? '');
    $ok  = false;
    $msg = 'No key provided.';
    if ($key) {
        $site_url = rtrim($settings['site_url'] ?? ('https://' . $_SERVER['HTTP_HOST']), '/');
        $payload  = http_build_query([
            'key'  => $key,
            'blog' => $site_url,
        ]);
        $ch = curl_init('https://rest.akismet.com/1.1/verify-key');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => 'SnapSmack/' . (defined('SNAPSMACK_VERSION') ? SNAPSMACK_VERSION : '0'),
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if (trim($body) === 'valid') {
            $ok  = true;
            $msg = '✓ Valid Akismet key.';
        } elseif (trim($body) === 'invalid') {
            $msg = '✗ Invalid key — check it at akismet.com.';
        } else {
            $msg = '✗ Akismet did not respond — try again.';
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok, 'message' => $msg]);
    exit;
}

// --- TOOL API KEY ACTIONS ---
$api_key_msg = '';
if (isset($_POST['api_key_action'])) {
    if ($_POST['api_key_action'] === 'generate') {
        $new_key = bin2hex(random_bytes(32)); // 64-char hex
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('tool_api_key', ?)
                        ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")->execute([$new_key]);
        $settings['tool_api_key'] = $new_key;
        $api_key_msg = 'New API key generated. Copy it into your tool now — it will not be shown in full again.';
    } elseif ($_POST['api_key_action'] === 'revoke') {
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('tool_api_key', '')
                        ON DUPLICATE KEY UPDATE setting_val = ''")->execute();
        $settings['tool_api_key'] = '';
        $api_key_msg = 'API key revoked. Tool access is now disabled.';
    }
}

// --- SMACKATTACK: REGISTER ACTION ---
// Handled before the main settings save so the new key is in DB before we reload settings.
$ste_msg = '';
$ste_err = '';
if (isset($_POST['ste_action']) && $_POST['ste_action'] === 'register') {
    $site_url = rtrim($settings['site_url'] ?? $_SERVER['HTTP_HOST'], '/');
    if (empty($site_url)) $site_url = 'https://' . $_SERVER['HTTP_HOST'];
    $display_name = $settings['site_title'] ?? 'SnapSmack Site';
    $post_count   = (int)($pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='published'")->fetchColumn() ?? 0);

    $res = ste_client_register($site_url, $display_name, $post_count);
    if ($res['ok']) {
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('ste_api_key',?) ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)")->execute([$res['api_key']]);
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('ste_enabled','1') ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)")->execute();
        $ste_msg = 'Registered with SMACKATTACK. Ready to roll.';
        // Reload settings to pick up the new key
        $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    } else {
        $ste_err = 'Registration failed: ' . ($res['error'] ?? 'unknown error');
    }
}

// Sync scores now if key exists and a manual sync was requested
if (isset($_POST['ste_action']) && $_POST['ste_action'] === 'sync_now') {
    $key    = $settings['ste_api_key'] ?? '';
    $cursor = $settings['ste_scores_cursor'] ?? '';
    $count  = ste_client_fetch_delta($pdo, $key, $cursor);
    $ste_msg = $count !== false ? "Synced {$count} score update(s) from the network." : 'Sync failed — check your API key and network connection.';
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Opt out
if (isset($_POST['ste_action']) && $_POST['ste_action'] === 'optout') {
    $key = $settings['ste_api_key'] ?? '';
    if ($key) _ste_request('POST', 'optout', [], $key);
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('ste_enabled','0') ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)")->execute();
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('ste_api_key','') ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)")->execute();
    $ste_msg = 'Opted out. Your site has been removed from the network.';
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// --- FORM SUBMISSION HANDLER ---
// Saves all settings via upsert. Logo/favicon uploads moved to Global Vibe.
if (isset($_POST['save_settings'])) {
    // Checkboxes that are unchecked send no POST value; default them to '0' before saving.
    $checkbox_keys = ['landing_only', 'ste_enabled'];
    foreach ($checkbox_keys as $ck) {
        if (!isset($_POST['settings'][$ck])) $_POST['settings'][$ck] = '0';
    }

    // Persist all settings, inserting or updating as needed.
    foreach ($_POST['settings'] as $key => $val) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute([$key, $val, $val]);
    }
    // --- REGENERATE ROBOTS.TXT ---
    // Build a robots.txt that reflects the AI training policy.
    $ai_policy = $_POST['settings']['ai_training_policy'] ?? 'no_opinion';
    $ai_bots   = ['GPTBot', 'ChatGPT-User', 'CCBot', 'Google-Extended', 'anthropic-ai', 'ClaudeBot', 'Bytespider'];

    $robots  = "# SNAPSMACK — auto-generated robots.txt\n";
    $robots .= "# Regenerated each time Global Configuration is saved.\n\n";
    $robots .= "User-agent: *\n";
    $robots .= "Disallow: /smack-*\n";
    $robots .= "Disallow: /core/\n";
    $robots .= "Disallow: /backups/\n";
    $robots .= "Disallow: /migrations/\n\n";

    if ($ai_policy === 'allow') {
        foreach ($ai_bots as $bot) {
            $robots .= "User-agent: {$bot}\n";
            $robots .= "Allow: /\n\n";
        }
    } elseif ($ai_policy === 'disallow') {
        foreach ($ai_bots as $bot) {
            $robots .= "User-agent: {$bot}\n";
            $robots .= "Disallow: /\n\n";
        }
    }
    // 'no_opinion' = no AI-specific directives at all.

    $robots .= "Sitemap: " . ($_POST['settings']['site_url'] ?? 'https://example.com/') . "sitemap.xml\n";

    file_put_contents(__DIR__ . '/robots.txt', $robots);

    $msg = "Engine parameters updated successfully.";
}

// Load all settings from database.
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

// Load available pages for navigation slot assignment.
try {
    $pages_list = $pdo->query("SELECT id, title FROM snap_pages WHERE is_active = 1 ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pages_list = [];
}

// Resolve active theme name from slug or manifest.
$active_slug = $settings['active_skin'] ?? 'new-horizon';
$active_skin_friendly = str_replace('_', ' ', ucfirst($active_slug));
if (file_exists("skins/{$active_slug}/manifest.php")) {
    $manifest = include "skins/{$active_slug}/manifest.php";
    if (isset($manifest['name'])) {
        $active_skin_friendly = $manifest['name'];
    }
}

// Date format options for display in the UI.
$date_options = [
    'F j, Y'          => 'February 1, 2026',
    'Y-m-d'           => '2026-02-01',
    'd/m/Y'           => '01/02/2026',
    'm.d.y'           => '02.01.26',
    'jS F Y'          => '1st February 2026',
    'D, M j, Y'       => 'Sun, Feb 1, 2026'
];

$page_title = "Configuration";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>GLOBAL ENGINE CONFIGURATION</h2>
    
    <?php if(isset($msg)): ?>
        <div class="alert">> <?php echo $msg; ?></div>
    <?php endif; ?>

    <form method="POST" id="config-form">
        
        <!-- ============================================================
             SITE IDENTITY & BRANDING — post-layout-grid (2-col)
             Left: 3 text fields. Right: 2 selectors + read-only.
             ============================================================ -->
        <div class="box">
            <h3>SITE IDENTITY & BRANDING</h3>
            <div class="post-layout-grid">
                <div class="post-col-left">
                    <label>BLOG NAME</label>
                    <input type="text" name="settings[site_name]" value="<?php echo htmlspecialchars($settings['site_name'] ?? ''); ?>">
                    
                    <label>TAGLINE</label>
                    <input type="text" name="settings[site_tagline]" value="<?php echo htmlspecialchars($settings['site_tagline'] ?? ''); ?>">

                    <label>SEARCH FIELD LABEL <span class="field-tip" data-tip="Placeholder shown in the archive search box. Useful for multi-blog domains where each blog wants its own wording (e.g. 'Search articles', 'Search photos'). Defaults to 'Search or #tag…' if empty.">ⓘ</span></label>
                    <input type="text" name="settings[search_placeholder]" value="<?php echo htmlspecialchars($settings['search_placeholder'] ?? 'Search or #tag…'); ?>" placeholder="Search or #tag…" maxlength="60">

                    <label>SITE DESCRIPTION <span class="field-tip" data-tip="Used for Open Graph link previews and feed skin profile bios.">ⓘ</span></label>
                    <textarea name="settings[site_description]" rows="3" placeholder="One or two sentences about this blog. Used for Open Graph link previews and photo-feed skin profiles."><?php echo htmlspecialchars($settings['site_description'] ?? ''); ?></textarea>

                    <label>BASE SITE URL</label>
                    <input type="text" name="settings[site_url]" value="<?php echo htmlspecialchars($settings['site_url'] ?? 'https://example.com/'); ?>">

                    <label>SITE EMAIL</label>
                    <input type="email" name="settings[site_email]" value="<?php echo htmlspecialchars($settings['site_email'] ?? ''); ?>" placeholder="e.g. contact@example.com">
                </div>

                <div class="post-col-right">
                    <label>HEADER MODE</label>
                    <select name="settings[header_type]">
                        <option value="text" <?php echo (($settings['header_type'] ?? 'text') == 'text') ? 'selected' : ''; ?>>TEXT MODE</option>
                        <option value="image" <?php echo (($settings['header_type'] ?? 'text') == 'image') ? 'selected' : ''; ?>>IMAGE MODE (LOGO)</option>
                    </select>

                    <label>ACTIVE SKIN</label>
                    <div class="read-only-display">
                        <?php echo strtoupper(htmlspecialchars($active_skin_friendly)); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             ARCHITECTURE & INTERACTION — post-layout-grid (2-col)
             4 toggles: 2 per column. Clean 2x2.
             ============================================================ -->
        <div class="box">
            <h3>ARCHITECTURE & INTERACTION</h3>
            <div class="config-grid">
                <div class="lens-input-wrapper">
                    <label>GLOBAL COMMENTS <span class="field-tip" data-tip="Master override for all posts. Disabling this kills comments site-wide regardless of per-post settings.">ⓘ</span></label>
                    <select name="settings[global_comments_enabled]">
                        <option value="1" <?php echo (($settings['global_comments_enabled'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                        <option value="0" <?php echo (($settings['global_comments_enabled'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED (KILL-SWITCH)</option>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>AKISMET API KEY <span class="field-tip" data-tip="Spam filter for comments. Leave blank to disable. Get a free key at akismet.com/signup">ⓘ</span></label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" name="settings[akismet_key]"
                               value="<?php echo htmlspecialchars($settings['akismet_key'] ?? ''); ?>"
                               placeholder="e.g. a1b2c3d4e5f6"
                               style="flex:1;font-family:monospace;">
                        <button type="button" id="akismet-test-btn" class="master-update-btn" style="white-space:nowrap;padding:0 16px;flex-shrink:0;width:auto;">TEST KEY</button>
                    </div>
                    <span id="akismet-test-result" style="display:none;margin-top:4px;font-size:11px;"></span>
                </div>

                <div class="lens-input-wrapper">
                    <label>SITE-WIDE SEARCH <span class="field-tip" data-tip="Enables full-text search on skins that support it (e.g. Photogram).">ⓘ</span></label>
                    <select name="settings[search_enabled]">
                        <option value="0" <?php echo (($settings['search_enabled'] ?? '0') == '0') ? 'selected' : ''; ?>>DISABLED (DEFAULT)</option>
                        <option value="1" <?php echo (($settings['search_enabled'] ?? '0') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>AI TRAINING CRAWLERS <span class="field-tip" data-tip="Controls robots.txt directives for GPTBot, ClaudeBot, CCBot, Google-Extended, and ByteSpider. Regenerated on save.">ⓘ</span></label>
                    <select name="settings[ai_training_policy]">
                        <option value="no_opinion" <?php echo (($settings['ai_training_policy'] ?? 'no_opinion') == 'no_opinion') ? 'selected' : ''; ?>>NO OPINION (DEFAULT)</option>
                        <option value="allow" <?php echo (($settings['ai_training_policy'] ?? 'no_opinion') == 'allow') ? 'selected' : ''; ?>>ALLOW</option>
                        <option value="disallow" <?php echo (($settings['ai_training_policy'] ?? 'no_opinion') == 'disallow') ? 'selected' : ''; ?>>DISALLOW</option>
                    </select>
                </div>


                <div class="lens-input-wrapper">
                    <label>COMMUNITY FORUM <span class="field-tip" data-tip="Shows the forum client in your admin panel. Connects to the SnapSmack community hub.">ⓘ</span></label>
                    <select name="settings[forum_enabled]">
                        <option value="1" <?php echo (($settings['forum_enabled'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED (DEFAULT)</option>
                        <option value="0" <?php echo (($settings['forum_enabled'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED</option>
                    </select>
                </div>

                <!-- Forum URL is hardcoded to snapsmack.ca. Not user-configurable. -->

                <div class="lens-input-wrapper">
                    <label>HOMEPAGE MODE <span class="field-tip" data-tip="Latest Post shows the newest image. Skin Landing uses the skin's built-in slider/grid. Static Page uses a custom page you select.">ⓘ</span></label>
                    <select name="settings[homepage_mode]" id="homepage-mode-select">
                        <option value="latest_post" <?php echo (($settings['homepage_mode'] ?? 'latest_post') == 'latest_post') ? 'selected' : ''; ?>>LATEST POST (DEFAULT)</option>
                        <option value="skin_landing" <?php echo (($settings['homepage_mode'] ?? 'latest_post') == 'skin_landing') ? 'selected' : ''; ?>>SKIN LANDING PAGE</option>
                        <option value="static_page" <?php echo (($settings['homepage_mode'] ?? 'latest_post') == 'static_page') ? 'selected' : ''; ?>>STATIC PAGE</option>
                    </select>

                    <div class="lens-input-wrapper homepage-page-picker<?php echo (($settings['homepage_mode'] ?? 'latest_post') == 'static_page') ? '' : ' d-none'; ?>" id="homepage-page-picker">
                        <label>HOMEPAGE PAGE</label>
                        <select name="settings[homepage_page_id]">
                            <option value="0">SELECT A PAGE</option>
                            <?php foreach($pages_list as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo (($settings['homepage_page_id'] ?? 0) == $p['id']) ? 'selected' : ''; ?>><?php echo strtoupper(htmlspecialchars($p['title'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="lens-input-wrapper homepage-blog-slug<?php echo (($settings['homepage_mode'] ?? 'latest_post') == 'latest_post') ? ' d-none' : ''; ?>" id="homepage-blog-slug">
                        <label>BLOG URL SLUG <span class="field-tip" data-tip="The URL where visitors find your image feed (e.g. /blog, /feed, /photos). Appears in navigation.">ⓘ</span></label>
                        <input type="text" name="settings[blog_slug]" value="<?php echo htmlspecialchars($settings['blog_slug'] ?? 'blog'); ?>" placeholder="blog">
                    </div>

                    <?php $show_landing_only = in_array(($settings['homepage_mode'] ?? 'latest_post'), ['skin_landing', 'static_page']); ?>
                    <div class="lens-input-wrapper<?php echo $show_landing_only ? '' : ' d-none'; ?>" id="homepage-landing-only">
                        <label>LANDING PAGE ONLY <span class="field-tip" data-tip="No navigation, no skin, no chrome — just page content. Use for splash screens or single-page sites.">ⓘ</span></label>
                        <label class="toggle-switch">
                            <input type="checkbox" name="settings[landing_only]" value="1" <?php echo (($settings['landing_only'] ?? '0') === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             SECURITY
             ============================================================ -->
        <div class="box">
            <h3>SECURITY</h3>
            <p class="dim">Harden your login endpoint against bots and brute-force attacks.</p>
            <div class="post-layout-grid">
                <div class="lens-input-wrapper">
                    <label>LOGIN SLUG <span class="field-tip" data-tip="The URL path for your login page (e.g. snap-in → yoursite.com/snap-in). Bots won't find it. Changing this takes effect immediately — bookmark the new URL before saving.">ⓘ</span></label>
                    <input type="text" name="settings[login_slug]" value="<?php echo htmlspecialchars($settings['login_slug'] ?? 'snap-in'); ?>" placeholder="snap-in">
                </div>
                <div class="lens-input-wrapper">
                    <label>RECOVERY TOKEN <span class="field-tip" data-tip="If you forget your login slug, visit snap-in.php?key=TOKEN to be redirected to it. Leave blank to disable. Use a long random string.">ⓘ</span></label>
                    <input type="text" name="settings[login_recovery_key]" value="<?php echo htmlspecialchars($settings['login_recovery_key'] ?? ''); ?>" placeholder="leave blank to disable" autocomplete="off">
                </div>
            </div>
        </div>

        <!-- Footer Config + Image Engine moved to Global Vibe (smack-globalvibe.php) -->

        <!-- ============================================================
             DOWNLOADS
             ============================================================ -->
        <div class="box">
            <h3>DOWNLOADS</h3>
            <div class="dash-grid">

                <div class="lens-input-wrapper">
                    <label>REQUIRE DOWNLOAD LINK? <span class="field-tip" data-tip="When enabled, posts cannot be published without a download URL. Intended for sites where every image is backed by a Google Drive original.">ⓘ</span></label>
                    <select name="settings[download_link_required]">
                        <option value="0" <?php echo (($settings['download_link_required'] ?? '0') == '0') ? 'selected' : ''; ?>>NO — OPTIONAL</option>
                        <option value="1" <?php echo (($settings['download_link_required'] ?? '0') == '1') ? 'selected' : ''; ?>>YES — BLOCK PUBLISH IF MISSING</option>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>DEFAULT DOWNLOAD MODE <span class="field-tip" data-tip="All Posts saves a step if every image is downloadable. You can still disable downloads per-post.">ⓘ</span></label>
                    <select name="settings[download_default_mode]">
                        <option value="per_post" <?php echo (($settings['download_default_mode'] ?? 'per_post') == 'per_post') ? 'selected' : ''; ?>>PER-POST (ENABLE MANUALLY)</option>
                        <option value="all_posts" <?php echo (($settings['download_default_mode'] ?? 'per_post') == 'all_posts') ? 'selected' : ''; ?>>ALL POSTS (DOWNLOADS ON BY DEFAULT)</option>
                    </select>
                </div>

            </div>
        </div>

        <!-- ============================================================
             TIME & LOCALIZATION — dash-grid (3-col)
             Exactly 3 items. Perfect fit.
             ============================================================ -->
        <div class="box box-flush-bottom">
            <h3>TIME & LOCALIZATION</h3>
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>TIMEZONE</label>
                    <select name="settings[timezone]" id="timezone-select">
                        <?php
                        $timezones = DateTimeZone::listIdentifiers();
                        $current_tz = $settings['timezone'] ?? 'America/Edmonton';
                        foreach ($timezones as $tz) {
                            $selected = ($current_tz == $tz) ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($tz) . "' $selected>" . strtoupper(htmlspecialchars($tz)) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="lens-input-wrapper">
                    <label>DATE DISPLAY FORMAT</label>
                    <select name="settings[date_format]" id="format-select">
                        <?php
                        $current_format = $settings['date_format'] ?? 'F j, Y';
                        foreach ($date_options as $code => $example) {
                            $selected = ($current_format == $code) ? 'selected' : '';
                            echo "<option value='$code' $selected>" . strtoupper($example) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="lens-input-wrapper">
                    <label>LIVE PREVIEW</label>
                    <div id="local-clock" class="read-only-display clock-display">SYNCING...</div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             AI ASSISTANT
             ============================================================ -->
        <div class="box box-flush-bottom">
            <h3>AI ASSISTANT</h3>
            <p class="dim" style="margin:0 0 16px;">
                Powers the Spell/Grammar check and AI Assist panel in the post editor.
                Your API key is stored in the database and never exposed publicly.
            </p>

            <div class="dash-grid" style="grid-template-columns: 1fr 1fr;">
                <div class="lens-input-wrapper">
                    <label>PROVIDER</label>
                    <select name="settings[ai_provider]" id="ai-provider-select">
                        <?php
                        $ai_provider = $settings['ai_provider'] ?? 'none';
                        $ai_providers = [
                            'none'   => 'None (disabled)',
                            'claude' => 'Claude (Anthropic)',
                            'gemini' => 'Gemini (Google)',
                            'openai' => 'ChatGPT (OpenAI)',
                        ];
                        foreach ($ai_providers as $val => $label):
                            $sel = $ai_provider === $val ? 'selected' : '';
                        ?>
                        <option value="<?php echo $val; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="ai-key-fields">
                <div class="lens-input-wrapper ai-key-field" data-provider="claude" <?php echo ($ai_provider !== 'claude') ? 'style="display:none;"' : ''; ?>>
                    <label>CLAUDE API KEY</label>
                    <input type="password" id="ai_key_claude_input" name="settings[ai_key_claude]"
                           value="<?php echo htmlspecialchars($settings['ai_key_claude'] ?? ''); ?>"
                           placeholder="sk-ant-…" autocomplete="off">
                    <span class="field-hint">Get yours at <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a></span>
                </div>
                <div class="lens-input-wrapper ai-key-field" data-provider="gemini" <?php echo ($ai_provider !== 'gemini') ? 'style="display:none;"' : ''; ?>>
                    <label>GEMINI API KEY</label>
                    <input type="password" id="ai_key_gemini_input" name="settings[ai_key_gemini]"
                           value="<?php echo htmlspecialchars($settings['ai_key_gemini'] ?? ''); ?>"
                           placeholder="AIza…" autocomplete="off">
                    <span class="field-hint">Get yours at <a href="https://aistudio.google.com/apikey" target="_blank">aistudio.google.com</a></span>
                </div>
                <div class="lens-input-wrapper ai-key-field" data-provider="openai" <?php echo ($ai_provider !== 'openai') ? 'style="display:none;"' : ''; ?>>
                    <label>OPENAI API KEY</label>
                    <input type="password" id="ai_key_openai_input" name="settings[ai_key_openai]"
                           value="<?php echo htmlspecialchars($settings['ai_key_openai'] ?? ''); ?>"
                           placeholder="sk-…" autocomplete="off">
                    <span class="field-hint">Get yours at <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a></span>
                </div>
            </div>

            <div style="margin-top:12px;">
                <button type="button" class="btn-smack btn-smack--sm" id="ai-test-btn"
                        style="display:<?php echo $ai_provider !== 'none' ? 'inline-block' : 'none'; ?>;">
                    TEST CONNECTION
                </button>
                <span id="ai-test-result" style="margin-left:12px; font-size:0.85em;"></span>
            </div>
        </div>

        <?php $_ui_pimpmobile = ($settings['ui_mode'] ?? 'bigwheel') === 'pimpmobile'; ?>
        <?php if (($settings['site_mode'] ?? 'photoblog') === 'smacktalk'): ?>
        <h3>POST MODES</h3>
        <div class="lens-input-wrapper">
            <label>SMACKTALK (LONGFORM POSTS) <span class="field-tip" data-tip='Enables the longform post editor and "New Longform Post" in the sidebar.'>ⓘ</span></label>
            <label class="toggle-switch">
                <input type="checkbox" name="settings[enable_longform]" value="1"
                       <?php echo ($settings['enable_longform'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>
        <?php endif; ?>

        <?php if ($_ui_pimpmobile): ?>
        <h3>SMACKATTACK</h3>
        <?php if ($ste_msg): ?>
            <div class="alert alert-success mb-25">&gt; <?php echo htmlspecialchars($ste_msg); ?></div>
        <?php endif; ?>
        <?php if ($ste_err): ?>
            <div class="alert alert-danger mb-25">&gt; <?php echo htmlspecialchars($ste_err); ?></div>
        <?php endif; ?>

        <?php
        $ste_key     = $settings['ste_api_key']           ?? '';
        $ste_enabled = ($settings['ste_enabled']          ?? '0') === '1';
        $ste_thresh  = $settings['ste_auto_ban_threshold'] ?? 'red';
        $ste_cursor  = $settings['ste_scores_cursor']      ?? '';
        ?>

        <?php if ($ste_key === ''): ?>
            <p class="dim" style="font-size:0.85rem; margin-bottom:16px;">
                SMACKATTACK is a voluntary network reputation system for SnapSmack blogs.
                Register to start receiving threat level scores on incoming comments.
                You can opt out at any time.
            </p>
            <form method="POST" style="margin-top:15px;">
                <input type="hidden" name="ste_action" value="register">
                <button type="submit" class="btn-smack" style="width:100%;">JOIN THE NETWORK</button>
            </form>
        <?php else: ?>
            <label>NETWORK STATUS</label>
            <div class="read-only-display highlight-green">REGISTERED</div>
            <label class="mt-20">API KEY</label>
            <div class="read-only-display" style="font-family:monospace; font-size:0.75rem; letter-spacing:0.05em;">
                <?php echo substr($ste_key, 0, 8); ?>…<?php echo substr($ste_key, -8); ?>
            </div>

            <label class="mt-20">PARTICIPATION</label>
            <div class="toggle-row">
                <input type="checkbox" id="ste_enabled" name="settings[ste_enabled]" value="1"
                       <?php echo $ste_enabled ? 'checked' : ''; ?>>
                <label for="ste_enabled">ACTIVE — report bans and receive threat scores</label>
            </div>

            <label class="mt-20">AUTO-BAN THRESHOLD</label>
            <select name="settings[ste_auto_ban_threshold]" class="styled-select">
                <?php
                $thresholds = [
                    'yellow' => 'YELLOW — ban anything flagged (1+ strike)',
                    'orange' => 'ORANGE — ban confirmed threats (2+ strikes)',
                    'red'    => 'RED — ban serious threats (3+ strikes)',
                    'black'  => 'BLACK — ban only the worst offenders (4+ strikes)',
                    'never'  => 'NEVER — receive scores but never auto-ban',
                ];
                foreach ($thresholds as $val => $label):
                ?>
                <option value="<?php echo $val; ?>" <?php echo $ste_thresh === $val ? 'selected' : ''; ?>>
                    <?php echo $label; ?>
                </option>
                <?php endforeach; ?>
            </select>
            <span class="dim" style="font-size:0.8rem; display:block; margin-top:6px;">
                Comments at or above this level are silently rejected. You still see them in Troll Control.
            </span>

            <label class="mt-20">SCORE CACHE</label>
            <div class="read-only-display"><?php echo $ste_cursor ? 'Last synced: ' . htmlspecialchars($ste_cursor) : 'Never synced — save settings then sync'; ?></div>
            <div class="action-grid-dual mt-15">
                <form method="POST" style="display:contents;">
                    <input type="hidden" name="ste_action" value="sync_now">
                    <button type="submit" class="btn-smack">SYNC SCORES NOW</button>
                </form>
                <form method="POST" style="display:contents;" onsubmit="return confirm('This will remove your site from the network and clear your API key. Continue?');">
                    <input type="hidden" name="ste_action" value="optout">
                    <button type="submit" class="btn-smack btn-danger">OPT OUT</button>
                </form>
            </div>
        <?php endif; ?>
        <?php endif; // pimpmobile ?>

        <h3>API ACCESS</h3>
        <?php
        $tool_api_key = $settings['tool_api_key'] ?? '';
        ?>
        <?php if ($api_key_msg): ?>
            <div class="alert alert-success mb-25">&gt; <?php echo htmlspecialchars($api_key_msg); ?></div>
        <?php endif; ?>
        <p class="dim" style="font-size:0.85rem; margin-bottom:16px;">
            Generate an API key to allow companion tools (SYBU, etc.) to authenticate
            without a login session. Send the key in the <code>X-Snap-Key</code> request header.
            Revoking the key immediately blocks all tool access.
        </p>

        <?php if ($tool_api_key !== ''): ?>
            <div class="control-group" style="margin-bottom:12px;">
                <label>CURRENT KEY</label>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <input type="text" id="tool-api-key-display"
                           value="<?php echo htmlspecialchars($tool_api_key); ?>"
                           readonly style="font-family:monospace; font-size:0.8rem; flex:1; min-width:200px; height:38px; padding:0 10px; margin:0;">
                    <button type="button" onclick="
                        navigator.clipboard.writeText(document.getElementById('tool-api-key-display').value);
                        this.textContent='COPIED';
                        setTimeout(()=>this.textContent='COPY',1500);
                    " class="btn-smack" style="margin-top:0; white-space:nowrap; width:auto; flex-shrink:0; height:38px; padding:0 18px;">COPY</button>
                </div>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:8px;">
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="api_key_action" value="generate">
                    <button type="submit" class="btn-smack" style="margin-top:0; width:auto; padding:0 18px; height:38px;">REGENERATE KEY</button>
                </form>
                <form method="POST" style="margin:0;"
                      onsubmit="return confirm('Revoke the API key? All tools will lose access immediately.');">
                    <input type="hidden" name="api_key_action" value="revoke">
                    <button type="submit" class="btn-smack btn-danger" style="margin-top:0; width:auto; padding:0 18px; height:38px;">REVOKE KEY</button>
                </form>
            </div>
        <?php else: ?>
            <p class="dim" style="font-size:0.85rem; margin-bottom:12px;">No key generated. Tool API access is currently disabled.</p>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="api_key_action" value="generate">
                <button type="submit" class="btn-smack" style="margin-top:0;">GENERATE API KEY</button>
            </form>
        <?php endif; ?>

        <button type="submit" name="save_settings" class="master-update-btn">SAVE GLOBAL ENGINE CONFIGURATION</button>

    </form>
</div>

<script>
// Akismet key test button
(function () {
    var btn = document.getElementById('akismet-test-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var key = document.querySelector('input[name="settings[akismet_key]"]').value.trim();
        var result = document.getElementById('akismet-test-result');
        if (!key) {
            result.style.display = '';
            result.style.color = '#aaa';
            result.textContent = 'Enter a key first.';
            return;
        }
        btn.disabled = true;
        btn.textContent = 'TESTING…';
        result.style.display = 'none';
        var fd = new FormData();
        fd.append('action', 'akismet_test');
        fd.append('key', key);
        fetch('smack-settings.php', { method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            result.style.display = '';
            result.style.color = d.ok ? '#4caf50' : '#e74c3c';
            result.textContent = d.message;
        })
        .catch(function () {
            result.style.display = '';
            result.style.color = '#e74c3c';
            result.textContent = 'Request failed — check console.';
        })
        .finally(function () {
            btn.disabled = false;
            btn.textContent = 'TEST KEY';
        });
    });
})();
</script>

<script>
// Toggle homepage page picker visibility based on homepage mode.
var homepageMode = document.getElementById('homepage-mode-select');
if (homepageMode) {
    homepageMode.addEventListener('change', function() {
        var picker      = document.getElementById('homepage-page-picker');
        var blogSlug    = document.getElementById('homepage-blog-slug');
        var landingOnly = document.getElementById('homepage-landing-only');
        if (picker)      picker.classList.toggle('d-none', this.value !== 'static_page');
        if (blogSlug)    blogSlug.classList.toggle('d-none', this.value === 'latest_post');
        if (landingOnly) landingOnly.classList.toggle('d-none', this.value !== 'skin_landing' && this.value !== 'static_page');
    });
}
</script>

<script>
// Show/hide API key field based on provider selection.
(function () {
    var sel    = document.getElementById('ai-provider-select');
    var testBtn = document.getElementById('ai-test-btn');
    var testRes = document.getElementById('ai-test-result');
    if (!sel) return;

    sel.addEventListener('change', function () {
        document.querySelectorAll('.ai-key-field').forEach(function (el) {
            el.style.display = (el.dataset.provider === sel.value) ? '' : 'none';
        });
        if (testBtn) testBtn.style.display = sel.value !== 'none' ? 'inline-block' : 'none';
        if (testRes) testRes.textContent = '';
    });

    if (testBtn) {
        testBtn.addEventListener('click', function () {
            testRes.textContent = 'Testing…';
            testRes.style.color = '';

            // Gather current form values so the test works before saving
            var provider   = sel.value;
            var keyFieldId = { claude: 'ai_key_claude_input', gemini: 'ai_key_gemini_input', openai: 'ai_key_openai_input' }[provider] || '';
            var keyField   = keyFieldId ? document.getElementById(keyFieldId) : null;
            var apiKey     = keyField ? keyField.value.trim() : '';

            var fd = new FormData();
            fd.append('provider', provider);
            fd.append('api_key',  apiKey);

            fetch('smack-ai-test.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    testRes.textContent = d.ok ? '✓ ' + d.message : '✗ ' + d.error;
                    testRes.style.color = d.ok ? 'var(--color-ok, #4caf50)' : 'var(--color-danger, #e05252)';
                })
                .catch(function () {
                    testRes.textContent = '✗ Request failed';
                    testRes.style.color = 'var(--color-danger, #e05252)';
                });
        });
    }
}());
</script>
<script src="assets/js/ss-engine-admin-ui.js?v=<?php echo time(); ?>"></script>
<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
