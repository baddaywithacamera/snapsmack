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


require_once 'core/auth-smack.php';
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

// --- TOOL API KEY ACTIONS --- retired in 0.7.261. The shared tool_api_key is
// gone; per-tool scoped keys are managed on smack-api-keys.php (Admin → API Keys).

// --- SMACKATTACK: REGISTER ACTION ---
// Handled before the main settings save so the new key is in DB before we reload settings.
$ste_msg = '';
$ste_err = '';
if (isset($_POST['ste_action']) && $_POST['ste_action'] === 'register') {
    $site_url = rtrim($settings['site_url'] ?? '', '/');
    if (empty($site_url)) $site_url = 'https://' . rtrim($_SERVER['HTTP_HOST'], '/');
    if (!preg_match('#^https?://#i', $site_url)) $site_url = 'https://' . $site_url;
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

    // Sanitise enum fields before they hit the DB.
    // update_track: only 'stable' or 'dev' are valid; anything else silently collapses to 'stable'.
    if (isset($_POST['settings']['update_track']) && !in_array($_POST['settings']['update_track'], ['stable', 'dev'], true)) {
        $_POST['settings']['update_track'] = 'stable';
    }
    // archive_layout: only 'grid' or 'list' are valid.
    if (isset($_POST['settings']['archive_layout']) && !in_array($_POST['settings']['archive_layout'], ['grid', 'list'], true)) {
        $_POST['settings']['archive_layout'] = 'grid';
    }
    // archive_thumb_style: only 'square' or 'natural' are valid.
    if (isset($_POST['settings']['archive_thumb_style']) && !in_array($_POST['settings']['archive_thumb_style'], ['square', 'natural'], true)) {
        $_POST['settings']['archive_thumb_style'] = 'square';
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

    // --- CACHE DEV MODE (pause) ---
    // Compute an absolute expiry from the chosen window. When "pause" is ticked,
    // caching is bypassed until now + duration, then auto-resumes. Allowed
    // windows: 5min / 15min / 1hr / 6hr / 1day / 1week.
    $_dev_dur     = (int)($_POST['cache_dev_duration'] ?? 0);
    $_dev_allowed = [300, 900, 3600, 21600, 86400, 604800];
    $_dev_until   = (!empty($_POST['cache_dev_enable']) && in_array($_dev_dur, $_dev_allowed, true))
                  ? (time() + $_dev_dur) : 0;
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('cache_dev_until', ?)
                   ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
        ->execute([(string)$_dev_until]);

    // Settings changed — flush the opt-in page cache so the change is visible
    // immediately (and so toggling cache_enabled off / pausing clears stale pages).
    require_once __DIR__ . '/core/page-cache.php';
    page_cache_purge_all();

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

                    <label>META DESCRIPTION <span class="field-tip" data-tip="Dedicated &lt;meta name=description&gt; for search engines. Falls back to Site Description, then tagline. Individual post pages use their own description.">ⓘ</span></label>
                    <textarea name="settings[meta_description]" rows="2" placeholder="Optional. Search-engine description for the homepage. ~150–160 characters." maxlength="320"><?php echo htmlspecialchars($settings['meta_description'] ?? ''); ?></textarea>

                    <label>SEO TITLE TEMPLATE <span class="field-tip" data-tip="Format for per-page browser titles. Tokens: {page} = page title, {site} = site name. Leave blank for the default 'Page | Site'.">ⓘ</span></label>
                    <input type="text" name="settings[seo_title_template]" value="<?php echo htmlspecialchars($settings['seo_title_template'] ?? ''); ?>" placeholder="{page} — {site}">

                    <label>OG IMAGE OVERRIDE <span class="field-tip" data-tip="Default social-share image (path or URL) used when a page has no image of its own. Individual post images still take priority.">ⓘ</span></label>
                    <input type="text" name="settings[og_image_override]" value="<?php echo htmlspecialchars($settings['og_image_override'] ?? ''); ?>" placeholder="e.g. uploads/social-card.jpg">

                    <label>BASE SITE URL</label>
                    <input type="text" name="settings[site_url]" value="<?php echo htmlspecialchars($settings['site_url'] ?? 'https://example.com/'); ?>">

                    <label>SITE EMAIL</label>
                    <?php if (($settings['hub_controls_email'] ?? '0') === '1' && ($settings['multisite_role'] ?? '') !== 'hub'): ?>
                        <div class="read-only-display"><?php echo htmlspecialchars($settings['site_email'] ?? '(not set)'); ?></div>
                        <span class="dim" style="font-size:0.75rem;margin-top:4px;display:block;">⊘ MANAGED BY NETWORK HUB</span>
                    <?php else: ?>
                        <input type="email" name="settings[site_email]" value="<?php echo htmlspecialchars($settings['site_email'] ?? ''); ?>" placeholder="e.g. contact@example.com">
                    <?php endif; ?>
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
                    <?php if (($settings['hub_controls_comments'] ?? '0') === '1' && ($settings['multisite_role'] ?? '') !== 'hub'): ?>
                        <div class="read-only-display"><?php echo ($settings['global_comments_enabled'] ?? '1') === '1' ? 'ENABLED' : 'DISABLED (KILL-SWITCH)'; ?></div>
                        <span class="dim" style="font-size:0.75rem;margin-top:4px;display:block;">⊘ MANAGED BY NETWORK HUB</span>
                    <?php else: ?>
                        <select name="settings[global_comments_enabled]">
                            <option value="1" <?php echo (($settings['global_comments_enabled'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                            <option value="0" <?php echo (($settings['global_comments_enabled'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED (KILL-SWITCH)</option>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="lens-input-wrapper">
                    <label>AKISMET API KEY <span class="field-tip" data-tip="Spam filter for comments. Leave blank to disable. Get a free key at akismet.com/signup">ⓘ</span></label>
                    <?php if (($settings['hub_controls_akismet'] ?? '0') === '1' && ($settings['multisite_role'] ?? '') !== 'hub'): ?>
                        <div class="read-only-display" style="font-family:monospace;">
                            <?php $ak = $settings['akismet_key'] ?? ''; echo $ak ? '••••••••' . htmlspecialchars(substr($ak, -4)) : '(not set)'; ?>
                        </div>
                        <span class="dim" style="font-size:0.75rem;margin-top:4px;display:block;">⊘ MANAGED BY NETWORK HUB</span>
                    <?php else: ?>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="text" name="settings[akismet_key]"
                                   value="<?php echo htmlspecialchars($settings['akismet_key'] ?? ''); ?>"
                                   placeholder="e.g. a1b2c3d4e5f6"
                                   style="flex:1;font-family:monospace;">
                            <button type="button" id="akismet-test-btn" class="master-update-btn btn-mt-0" style="white-space:nowrap;padding:0 16px;flex-shrink:0;width:auto;height:40px;">TEST KEY</button>
                        </div>
                        <span id="akismet-test-result" style="display:none;margin-top:4px;font-size:11px;"></span>
                    <?php endif; ?>
                </div>

                <div class="lens-input-wrapper">
                    <label>SITE-WIDE SEARCH <span class="field-tip" data-tip="Enables full-text search on skins that support it (e.g. Photogram).">ⓘ</span></label>
                    <select name="settings[search_enabled]">
                        <option value="0" <?php echo (($settings['search_enabled'] ?? '0') == '0') ? 'selected' : ''; ?>>DISABLED (DEFAULT)</option>
                        <option value="1" <?php echo (($settings['search_enabled'] ?? '0') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                <div class="lens-input-wrapper">
                    <label>PUBLIC BLOGROLL <span class="field-tip" data-tip="Shows the public blogroll page at /blogroll.php. Disable to hide it entirely.">ⓘ</span></label>
                    <select name="settings[blogroll_enabled]">
                        <option value="1" <?php echo (($settings['blogroll_enabled'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                        <option value="0" <?php echo (($settings['blogroll_enabled'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED</option>
                    </select>
                </div>

                    <label>AI TRAINING CRAWLERS <span class="field-tip" data-tip="Controls robots.txt directives for GPTBot, ClaudeBot, CCBot, Google-Extended, and ByteSpider. Regenerated on save.">ⓘ</span></label>
                    <select name="settings[ai_training_policy]">
                        <option value="no_opinion" <?php echo (($settings['ai_training_policy'] ?? 'no_opinion') == 'no_opinion') ? 'selected' : ''; ?>>NO OPINION (DEFAULT)</option>
                        <option value="allow" <?php echo (($settings['ai_training_policy'] ?? 'no_opinion') == 'allow') ? 'selected' : ''; ?>>ALLOW</option>
                        <option value="disallow" <?php echo (($settings['ai_training_policy'] ?? 'no_opinion') == 'disallow') ? 'selected' : ''; ?>>DISALLOW</option>
                    </select>

                    <label>PAGE CACHE <span class="field-tip" data-tip="Opt-in. Caches public pages for anonymous visitors only (logged-in admins and query-param pages are never cached). Like/comment counts may lag up to the TTL. Cleared automatically when you save settings or publish.">ⓘ</span></label>
                    <select name="settings[cache_enabled]">
                        <option value="0" <?php echo (($settings['cache_enabled'] ?? '0') == '0') ? 'selected' : ''; ?>>OFF (DEFAULT)</option>
                        <option value="1" <?php echo (($settings['cache_enabled'] ?? '0') == '1') ? 'selected' : ''; ?>>ON — anonymous full-page cache</option>
                    </select>

                    <label>CACHE TTL (SECONDS) <span class="field-tip" data-tip="How long a cached page stays fresh before it is rebuilt. Default 300 (5 minutes).">ⓘ</span></label>
                    <input type="number" name="settings[cache_ttl]" min="30" max="86400" value="<?php echo (int)($settings['cache_ttl'] ?? 300); ?>">

                    <?php
                        $_dev_until  = (int)($settings['cache_dev_until'] ?? 0);
                        $_dev_active = $_dev_until > time();
                    ?>
                    <label>DEV MODE — PAUSE CACHE <span class="field-tip" data-tip="Temporarily bypass the cache while you work on the site. Caching resumes automatically when the window ends — no need to switch it back.">ⓘ</span></label>
                    <label class="checkbox-inline" style="display:flex;align-items:center;gap:8px;margin:4px 0;">
                        <input type="checkbox" name="cache_dev_enable" value="1" <?php echo $_dev_active ? 'checked' : ''; ?>>
                        Pause caching for:
                    </label>
                    <select name="cache_dev_duration">
                        <option value="300">5 minutes</option>
                        <option value="900">15 minutes</option>
                        <option value="3600">1 hour</option>
                        <option value="21600">6 hours</option>
                        <option value="86400">1 day</option>
                        <option value="604800">1 week</option>
                    </select>
                    <?php if ($_dev_active): ?>
                        <span class="dim" style="font-size:0.78rem;display:block;margin-top:4px;">
                            ⏸ Caching paused until <?php echo htmlspecialchars(date('M j, Y g:i a', $_dev_until)); ?>.
                            Untick and save to resume now.
                        </span>
                    <?php endif; ?>
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
                        <option value="archive"     <?php echo (($settings['homepage_mode'] ?? 'latest_post') == 'archive')     ? 'selected' : ''; ?>>ARCHIVE PAGE</option>
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

                    <!-- Archive sub-options — visible when homepage_mode = archive -->
                    <?php $show_archive_opts = ($settings['homepage_mode'] ?? 'latest_post') === 'archive'; ?>
                    <div class="lens-input-wrapper<?php echo $show_archive_opts ? '' : ' d-none'; ?>" id="homepage-archive-options">
                        <label>OPENING VIEW <span class="field-tip" data-tip="Layout visitors see when they first land on the archive. They can toggle between modes; this sets the starting state.">ⓘ</span></label>
                        <select name="settings[archive_layout]" id="archive-layout-select">
                            <option value="masonry" <?php echo (($settings['archive_layout'] ?? 'masonry') === 'masonry') ? 'selected' : ''; ?>>MASONRY (JUSTIFIED GRID)</option>
                            <option value="thumbs"  <?php echo (($settings['archive_layout'] ?? 'masonry') === 'thumbs')  ? 'selected' : ''; ?>>THUMBS (GRID)</option>
                        </select>

                        <?php $show_thumb_style = ($settings['archive_layout'] ?? 'masonry') === 'thumbs'; ?>
                        <div class="lens-input-wrapper<?php echo $show_thumb_style ? '' : ' d-none'; ?>" id="archive-thumb-style-wrap" style="margin-top:10px;">
                            <label>THUMB STYLE <span class="field-tip" data-tip="How thumbnails are cropped in the thumbs grid. Cropped fills the tile; Square forces a 1:1 ratio.">ⓘ</span></label>
                            <select name="settings[archive_thumb_style]">
                                <option value="cropped" <?php echo (($settings['archive_thumb_style'] ?? 'cropped') === 'cropped') ? 'selected' : ''; ?>>CROPPED (DEFAULT)</option>
                                <option value="square"  <?php echo (($settings['archive_thumb_style'] ?? 'cropped') === 'square')  ? 'selected' : ''; ?>>SQUARE</option>
                            </select>
                        </div>
                    </div>

                    <div class="lens-input-wrapper homepage-blog-slug<?php echo (($settings['homepage_mode'] ?? 'latest_post') == 'latest_post' || ($settings['homepage_mode'] ?? 'latest_post') == 'archive') ? ' d-none' : ''; ?>" id="homepage-blog-slug">
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
             UPDATE TRACK
             ============================================================ -->
        <div class="box">
            <h3>UPDATE TRACK</h3>
            <p class="dim" style="margin-bottom:16px;">
                Controls which update stream this site receives.
                <strong>Boring</strong> is the default — stable tagged releases only, recommended for production.
                <strong>Bitchin'</strong> is opt-in — receives dev builds (D-suffixed versions) in addition to stable releases.
                Changing this does not modify any installed files; it only affects what the updater offers next time it checks.
            </p>
            <div class="post-layout-grid">
                <div class="lens-input-wrapper">
                    <label>TRACK <span class="field-tip" data-tip="Boring = stable releases only. Bitchin' = dev + stable releases. New installs default to Boring. If you're not sure, leave this on Boring.">ⓘ</span></label>
                    <select name="settings[update_track]">
                        <option value="stable" <?php echo (($settings['update_track'] ?? 'stable') === 'stable') ? 'selected' : ''; ?>>BORING — Stable releases only (default)</option>
                        <option value="dev"    <?php echo (($settings['update_track'] ?? 'stable') === 'dev')    ? 'selected' : ''; ?>>BITCHIN' — Dev + stable releases (opt-in)</option>
                    </select>
                    <?php if (($settings['update_track'] ?? 'stable') === 'dev'): ?>
                        <p style="margin-top:8px;color:var(--warning,#f90);font-size:0.85rem;">
                            ⚠ This site is on the dev track. It may receive builds with known issues. Flip back to Boring any time.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ============================================================
             MAINTENANCE MODE
             ============================================================ -->
        <div class="box">
            <h3>MAINTENANCE MODE</h3>
            <p class="dim">When on, visitors who are not logged in see a holding page instead of your site. You see the site normally.</p>
            <div class="post-layout-grid">
                <div class="lens-input-wrapper">
                    <label>MAINTENANCE MODE</label>
                    <select name="settings[maintenance_mode]">
                        <option value="0" <?php echo (($settings['maintenance_mode'] ?? '0') == '0') ? 'selected' : ''; ?>>OFF — Site is live</option>
                        <option value="1" <?php echo (($settings['maintenance_mode'] ?? '0') == '1') ? 'selected' : ''; ?>>ON — Show maintenance page</option>
                    </select>
                </div>
                <div class="lens-input-wrapper">
                    <label>PAGE TITLE <span class="field-tip" data-tip="Heading shown on the maintenance page.">ⓘ</span></label>
                    <input type="text" name="settings[maintenance_title]" value="<?php echo htmlspecialchars($settings['maintenance_title'] ?? 'Under Maintenance'); ?>" placeholder="Under Maintenance">
                </div>
            </div>
            <div class="lens-input-wrapper" style="margin-top:12px;">
                <label>MESSAGE</label>
                <textarea name="settings[maintenance_body]" rows="3" style="width:100%;resize:vertical;"><?php echo htmlspecialchars($settings['maintenance_body'] ?? "We're working on a few things. Check back soon."); ?></textarea>
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

        <!-- ============================================================
             SMACKBACK — FILE INTEGRITY MONITORING
             ============================================================ -->
        <div class="box">
            <h3>SMACKBACK — FILE INTEGRITY</h3>
            <?php
            $smack_enabled_s = ($settings['smackback_enabled'] ?? '0') === '1';
            $smack_status_s  = $settings['smackback_status'] ?? 'clean';
            $smack_mode_s    = $settings['smackback_mode']   ?? 'lockout';
            ?>
            <p class="dim">Automated sentinel: hashes all PHP, CSS, and JS files at install/update time and re-verifies on admin login and cron. Catches FTP credential compromise before it becomes a bigger problem.</p>
            <div class="post-layout-grid" style="margin-top:12px;">
                <div class="lens-input-wrapper">
                    <label>STATUS</label>
                    <p style="margin:0;">
                        <?php if (!$smack_enabled_s): ?>
                            <span class="dim">Disabled</span>
                        <?php elseif ($smack_status_s === 'breach'): ?>
                            <strong style="color:#cc2200">⚠ BREACH DETECTED</strong>
                        <?php else: ?>
                            <span style="color:#5a9a5a">✓ Clean</span>
                        <?php endif; ?>
                        &nbsp; Mode: <strong><?php echo strtoupper(htmlspecialchars($smack_mode_s)); ?></strong>
                    </p>
                </div>
            </div>
            <a href="smack-back.php" class="btn-smack" style="margin-top:12px;">OPEN SMACKBACK →</a>
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
            <?php if (($settings['hub_controls_timezone'] ?? '0') === '1' && ($settings['multisite_role'] ?? '') !== 'hub'): ?>
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>TIMEZONE</label>
                    <div class="read-only-display"><?php echo htmlspecialchars($settings['timezone'] ?? 'America/Edmonton'); ?></div>
                    <span class="dim" style="font-size:0.75rem;margin-top:4px;display:block;">⊘ MANAGED BY NETWORK HUB</span>
                </div>
                <div class="lens-input-wrapper">
                    <label>DATE DISPLAY FORMAT</label>
                    <div class="read-only-display"><?php echo htmlspecialchars($settings['date_format'] ?? 'F j, Y'); ?></div>
                </div>
                <div class="lens-input-wrapper">
                    <label>LIVE PREVIEW</label>
                    <div id="local-clock" class="read-only-display clock-display">SYNCING...</div>
                </div>
            </div>
            <?php else: ?>
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
            <?php endif; // hub_controls_timezone ?>
        </div>

        <!-- ============================================================
             AI ASSISTANT
             ============================================================ -->
        <div class="box box-flush-bottom">
            <h3>AI ASSISTANT</h3>
            <?php if (($settings['hub_controls_ai'] ?? '0') === '1' && ($settings['multisite_role'] ?? '') !== 'hub'): ?>
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>PROVIDER</label>
                    <div class="read-only-display"><?php
                        $ai_prov_labels = ['claude' => 'Claude (Anthropic)', 'gemini' => 'Gemini (Google)', 'openai' => 'ChatGPT (OpenAI)', 'none' => 'None (disabled)'];
                        echo htmlspecialchars($ai_prov_labels[$settings['ai_provider'] ?? 'none'] ?? 'None');
                    ?></div>
                </div>
                <div class="lens-input-wrapper">
                    <label>API KEY</label>
                    <div class="read-only-display"><span class="dim">⊘ MANAGED BY NETWORK HUB</span></div>
                </div>
            </div>
            <?php else: ?>
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
            <?php endif; // hub_controls_ai ?>
        </div>

        <?php $_ui_pimpmobile = ($settings['ui_mode'] ?? 'bigwheel') === 'pimpmobile'; ?>
        <?php if (($settings['site_mode'] ?? 'photoblog') === 'smacktalk'): ?>
        <div class="box">
            <h3>POST MODES</h3>
            <div class="lens-input-wrapper">
                <label>SMACKTALK (LONGFORM POSTS) <span class="field-tip" data-tip='Enables the longform post editor and "New Longform Post" in the sidebar.'>ⓘ</span></label>
                <label class="toggle-switch">
                    <input type="checkbox" name="settings[enable_longform]" value="1"
                           <?php echo ($settings['enable_longform'] ?? '0') === '1' ? 'checked' : ''; ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($_ui_pimpmobile): ?>
        <div class="box">
            <h3>SMACKATTACK</h3>
            <?php if ($ste_msg): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($ste_msg); ?></div>
            <?php endif; ?>
            <?php if ($ste_err): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($ste_err); ?></div>
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
                <button type="submit" form="ste-register-form" class="btn-smack" style="width:100%;margin-top:15px;">JOIN THE NETWORK</button>
            <?php else: ?>
                <label>NETWORK STATUS</label>
                <div class="read-only-display highlight-green">REGISTERED</div>
                <label class="mt-20">API KEY</label>
                <div class="read-only-display" style="font-family:monospace; font-size:0.75rem; letter-spacing:0.05em;">
                    <?php echo substr($ste_key, 0, 8); ?>…<?php echo substr($ste_key, -8); ?>
                </div>

                <label class="mt-20">PARTICIPATION</label>
                <div class="lens-input-wrapper">
                    <label>ACTIVE — report bans and receive threat scores</label>
                    <label class="toggle-switch">
                        <input type="checkbox" id="ste_enabled" name="settings[ste_enabled]" value="1"
                               <?php echo $ste_enabled ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
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
                    <button type="submit" form="ste-sync-form" class="btn-smack">SYNC SCORES NOW</button>
                    <button type="submit" form="ste-optout-form" class="btn-smack btn-danger"
                            onclick="return confirm('This will remove your site from the network and clear your API key. Continue?')">OPT OUT</button>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; // pimpmobile ?>

        <button type="submit" name="save_settings" class="master-update-btn">SAVE GLOBAL ENGINE CONFIGURATION</button>

    </form>

    <!-- Action forms outside #config-form to avoid illegal nesting.
         Buttons above reference these by form="id". -->
    <form method="POST" id="ste-register-form"><input type="hidden" name="ste_action" value="register"></form>
    <form method="POST" id="ste-sync-form"><input type="hidden" name="ste_action" value="sync_now"></form>
    <form method="POST" id="ste-optout-form"><input type="hidden" name="ste_action" value="optout"></form>
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
// Toggle homepage sub-controls based on homepage mode.
var homepageMode = document.getElementById('homepage-mode-select');
if (homepageMode) {
    homepageMode.addEventListener('change', function() {
        var v           = this.value;
        var picker      = document.getElementById('homepage-page-picker');
        var blogSlug    = document.getElementById('homepage-blog-slug');
        var landingOnly = document.getElementById('homepage-landing-only');
        var archiveOpts = document.getElementById('homepage-archive-options');
        if (picker)      picker.classList.toggle('d-none', v !== 'static_page');
        // Blog slug applies when homepage isn't latest_post AND isn't archive
        // (archive lives at the fixed /archive route, no slug needed).
        if (blogSlug)    blogSlug.classList.toggle('d-none', v === 'latest_post' || v === 'archive');
        if (landingOnly) landingOnly.classList.toggle('d-none', v !== 'skin_landing' && v !== 'static_page');
        if (archiveOpts) archiveOpts.classList.toggle('d-none', v !== 'archive');
    });
}

// Toggle thumb style sub-option inside archive options.
var archiveLayout = document.getElementById('archive-layout-select');
if (archiveLayout) {
    archiveLayout.addEventListener('change', function() {
        var wrap = document.getElementById('archive-thumb-style-wrap');
        if (wrap) wrap.classList.toggle('d-none', this.value !== 'thumbs');
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
