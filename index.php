<?php
/**
 * SNAPSMACK - Main public controller that handles image display and navigation
 *
 * Routes requests to images by slug, loads the active skin template, and
 * manages navigation between published images with proper timestamp filtering.
 *
 * Supports homepage_mode setting:
 *   - 'latest_post'  (default) — shows latest published image via skin landing/layout
 *   - 'static_page'           — renders a chosen static page as homepage; blog moves to blog.php
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/parser.php';
require_once __DIR__ . '/core/skin-settings.php';

// SMACKBACK: silent stat check on public page loads (admin notified, visitor uninterrupted)
// Load only the two settings we need; $settings isn't fully populated yet at this point.
try {
    $_smack_cfg = $pdo->query(
        "SELECT setting_key, setting_val FROM snap_settings
         WHERE setting_key IN ('smackback_enabled', 'smackback_pageload_check')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($_smack_cfg['smackback_enabled'] ?? '0') === '1'
        && ($_smack_cfg['smackback_pageload_check'] ?? '0') === '1') {
        require_once __DIR__ . '/core/smackback.php';
        smackback_verify_quick();
    }
    unset($_smack_cfg);
} catch (PDOException $e) { /* non-fatal — site continues */ }

require_once __DIR__ . '/core/stats-logger.php';

// --- INITIALIZATION ---
$settings = [];
$site_name = 'ISWA.CA';
$active_skin = '50-shades-of-noah-grey';
$prev_slug = $next_slug = $first_slug = $last_slug = "";
$comment_count = 0;

try {
    $snapsmack = new SnapSmack($pdo);

    // --- SETTINGS LOADING ---
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    require_once __DIR__ . '/core/maintenance-gate.php';

    // ── Organized Mayhem JSON endpoint (early intercept) ──────────────────
    // The tabletop background (ss-engine-organized-mayhem.js) fetches its photo
    // pool from BASE_URL?ajax=mayhem. core/mayhem-data.php self-emits that JSON
    // and exits — but ONLY before any HTML is sent. The skins include it from
    // inside their landing template, AFTER meta.php has already streamed the
    // <head>, so an ?ajax=mayhem hit returned the whole HTML page (and the
    // Content-Type: application/json header silently failed) — r.json() then
    // threw, the pool came back empty, and the background stayed blank. Handle
    // it here, before page-cache and all rendering, so the engine gets clean
    // JSON. BASE_URL is needed for the absolute image src/url it builds.
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'mayhem') {
        if (!defined('BASE_URL')) {
            define('BASE_URL', rtrim($settings['site_url'] ?? 'https://example.com/', '/') . '/');
        }
        require_once __DIR__ . '/core/mayhem-data.php';  // self-emits JSON + exits
    }

    require_once __DIR__ . '/core/page-cache.php';
    page_cache_serve_or_start($settings);

    // Define BASE_URL from settings or fallback. Ensures trailing slash for consistent routing.
    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? 'https://example.com/';
        define('BASE_URL', rtrim($db_url, '/') . '/');
    }

    // Override defaults with database values if they exist
    $active_skin = ($settings['active_skin'] ?? '') ?: $active_skin;
    // Decode any HTML entities stored in the name (some import/legacy paths
    // saved it pre-encoded, e.g. "it&#039;s"); skins re-escape on output, so
    // decoding here prevents the double-encode that showed the raw &#039;.
    $site_name = html_entity_decode((string)($settings['site_name'] ?? $site_name), ENT_QUOTES | ENT_HTML5);

    // Force Pocket Rocket on mobile devices (phones only, not tablets)
    if (snapsmack_is_mobile() && SNAPSMACK_MOBILE_SKIN !== '' && is_dir(__DIR__ . '/skins/' . SNAPSMACK_MOBILE_SKIN)) {
        $active_skin = SNAPSMACK_MOBILE_SKIN;
    }

    // Overlay skin-scoped settings so each skin retains its own customizations
    snapsmack_apply_skin_settings($settings, $active_skin);

    // --- HOMEPAGE MODE: STATIC PAGE ---
    // If no specific slug is requested and homepage_mode is static_page, render the
    // chosen page using the same pattern as page.php instead of the image feed.
    $path_info = $_SERVER['PATH_INFO'] ?? '';
    $requested_slug = trim($path_info, '/');
    if (empty($requested_slug)) $requested_slug = $_GET['s'] ?? $_GET['name'] ?? null;

    // --- SMACKVERSE public profile (fediverse actor page at instance/username) ---
    // The address a Pixelfed user expects. Content-negotiate: a fediverse server
    // (activity+json) gets the actor doc; a browser gets the Pixelfed-faithful
    // profile. Only fires for the exact handle with federation ON, so ordinary
    // slugs are never intercepted. Cheap gate first — no library load otherwise.
    if ($requested_slug !== null && $requested_slug !== ''
        && ($settings['smackverse_enabled'] ?? '0') === '1') {
        require_once __DIR__ . '/core/smackverse.php';
        if (strcasecmp((string)$requested_slug, sv_handle($settings)) === 0) {
            $pp_accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            if (stripos($pp_accept, 'activity+json') !== false || stripos($pp_accept, 'ld+json') !== false) {
                header('Content-Type: application/activity+json; charset=utf-8');
                echo json_encode(sv_actor_doc($pdo, $settings), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }
            include __DIR__ . '/core/public-profile.php';
            exit;
        }
    }

    // --- SKIN PRELOAD HOOK ---
    // Allows a skin to intercept the request before image routing fires.
    // Alfred uses this to render its SmackTalk feed and single-post views.
    // The included file may call exit() to short-circuit all remaining logic.
    $skin_preload = __DIR__ . '/skins/' . $active_skin . '/preload.php';
    if (file_exists($skin_preload)) {
        include $skin_preload;
    }

    $homepage_mode    = $settings['homepage_mode'] ?? 'latest_post';
    $homepage_page_id = (int)($settings['homepage_page_id'] ?? 0);

    // Mobile (Photogram) always opens to the post scroll. The landing-page
    // homepage modes — static_page / archive / skin_landing — are desktop-only;
    // an Instagram-style mobile feed never shows a static front page. Force
    // latest_post whenever the mobile skin is active so the scroll is never
    // intercepted by a configured landing page.
    if ($active_skin === SNAPSMACK_MOBILE_SKIN && snapsmack_is_mobile()) {
        $homepage_mode = 'latest_post';
    }
    $blog_slug        = trim($settings['blog_slug'] ?? 'blog', '/');

    $force_blog = !empty($_SERVER['SNAPSMACK_FORCE_BLOG']);

    // Landing-only mode: bare homepage with no skin chrome.
    // Works for both skin_landing (hides nav via CSS) and static_page (full bare render).
    $landing_only_active = ($settings['landing_only'] ?? '0') === '1'
        && in_array($homepage_mode, ['skin_landing', 'static_page'])
        && empty($requested_slug)
        && !$force_blog;

    // If the requested slug matches the blog slug and homepage isn't latest_post,
    // force-show the image feed (the blog) instead of looking for an image with that slug.
    if ($requested_slug === $blog_slug && $homepage_mode !== 'latest_post') {
        $requested_slug = null;
        $force_blog = true;
    }

    // Archive as landing page — redirect root to /archive.
    // The archive_layout setting controls opening view; archive.php reads it directly.
    if (!$force_blog && !$requested_slug && $homepage_mode === 'archive') {
        $site_url_raw = $settings['site_url'] ?? '';
        if (!empty($site_url_raw) && preg_match('#^https?://#i', $site_url_raw)) {
            // Trusted base URL from settings.
            $archive_url = rtrim($site_url_raw, '/') . '/archive';
        } else {
            // site_url not configured — use a path-relative redirect so we can't be
            // redirected to an attacker-controlled host via a spoofed Host header.
            $archive_url = '/archive';
        }
        header('Location: ' . $archive_url, true, 302);
        exit;
    }

    if (!$force_blog && !$requested_slug && $homepage_mode === 'static_page' && $homepage_page_id > 0) {
        // Load the static page from snap_pages
        $hp_stmt = $pdo->prepare("SELECT * FROM snap_pages WHERE id = ? AND is_active = 1 LIMIT 1");
        $hp_stmt->execute([$homepage_page_id]);
        $page_data = $hp_stmt->fetch(PDO::FETCH_ASSOC);

        if ($page_data) {
            $page_title = htmlspecialchars($page_data['title']);
            $skin_path  = 'skins/' . $active_skin;

            // --- LANDING-ONLY / COMING SOON MODE ---
            // Skin CSS fully loaded (ledger paper, fonts, colours), but no header or footer.
            // Page title is centred. Nothing else changes.
            if ($landing_only_active) {
                snapsmack_log_hit($pdo, $settings, ['page_type' => 'landing', 'page_slug' => $page_data['slug'] ?? null]);
                if (file_exists(__DIR__ . '/' . $skin_path . '/skin-meta.php')) {
                    include __DIR__ . '/' . $skin_path . '/skin-meta.php';
                }
                ?>
                <body class="static-transmission homepage-static landing-only">
                    <div id="page-wrapper">
                        <div id="scroll-stage">
                            <div class="static-content">
                                <h1 class="static-page-title"><?php echo $page_title; ?></h1>
                                <?php if (!empty($page_data['image_asset'])):
                                    $hero_size   = in_array($page_data['image_size']  ?? '', ['medium','small']) ? $page_data['image_size']  : 'full';
                                    $hero_align  = in_array($page_data['image_align'] ?? '', ['left','right'])   ? $page_data['image_align'] : 'center';
                                    $hero_shadow = !empty($page_data['image_shadow']) ? ' page-hero--shadow' : '';
                                ?>
                                    <div id="photobox" class="page-hero page-hero--<?php echo $hero_size; ?> page-hero--<?php echo $hero_align; ?><?php echo $hero_shadow; ?>">
                                        <div class="main-photo">
                                            <img src="<?php echo BASE_URL . ltrim($page_data['image_asset'], '/'); ?>"
                                                 class="post-image"
                                                 alt="<?php echo $page_title; ?>">
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="description">
                                    <?php
                                    if (!empty($page_data['content'])) {
                                        echo $snapsmack->parseContent($page_data['content']);
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        <?php include __DIR__ . '/core/footer.php'; ?>
                    </div>
                </body>
                </html>
                <?php
                exit;
            }

            // --- NORMAL STATIC PAGE RENDER (with skin chrome) ---
            snapsmack_log_hit($pdo, $settings, ['page_type' => 'page', 'page_slug' => $page_data['slug'] ?? null]);
            if (file_exists(__DIR__ . '/' . $skin_path . '/skin-meta.php')) {
                include __DIR__ . '/' . $skin_path . '/skin-meta.php';
            }
            ?>
            <body class="static-transmission homepage-static">
                <div id="page-wrapper">
                    <div id="scroll-stage">
                        <?php
                        $header_file = __DIR__ . '/' . $skin_path . '/skin-header.php';
                        if (file_exists($header_file)) {
                            include $header_file;
                        } else {
                            include __DIR__ . '/core/header.php';
                        }
                        ?>

                        <div class="static-content">
                            <h1 class="static-page-title"><?php echo $page_title; ?></h1>
                            <?php if (!empty($page_data['image_asset'])):
                                $hero_size   = in_array($page_data['image_size']  ?? '', ['medium','small']) ? $page_data['image_size']  : 'full';
                                $hero_align  = in_array($page_data['image_align'] ?? '', ['left','right'])   ? $page_data['image_align'] : 'center';
                                $hero_shadow = !empty($page_data['image_shadow']) ? ' page-hero--shadow' : '';
                            ?>
                                <div id="photobox" class="page-hero page-hero--<?php echo $hero_size; ?> page-hero--<?php echo $hero_align; ?><?php echo $hero_shadow; ?>">
                                    <div class="main-photo">
                                        <img src="<?php echo BASE_URL . ltrim($page_data['image_asset'], '/'); ?>"
                                             class="post-image"
                                             alt="<?php echo $page_title; ?>">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="description">
                                <?php
                                if (!empty($page_data['content'])) {
                                    echo $snapsmack->parseContent($page_data['content']);
                                } else {
                                    echo "<p class='dim'>No content signal found for this sector.</p>";
                                }
                                ?>
                            </div>
                        </div>

                        <?php
                        $footer_file = __DIR__ . '/' . $skin_path . '/skin-footer.php';
                        if (file_exists($footer_file)) {
                            include $footer_file;
                        }
                        ?>
                    </div>
                </div>
                <?php
                // Load global JS engines (social dock, sticky header, etc.) unless using Photogram
                if ($active_skin !== 'photogram') {
                    include __DIR__ . '/core/footer-scripts.php';
                }
                ?>
            </body>
            </html>
            <?php
            exit; // Static homepage rendered — stop here
        }
        // If page not found, fall through to normal latest-post behaviour
    }

    // --- HASHTAG ARCHIVE ---
    // ?tag=slug routes to the skin's hashtag.php if it exists
    $requested_tag = trim($_GET['tag'] ?? '');
    if ($requested_tag !== '' && preg_match('/^(?:[a-zA-Z][a-zA-Z0-9_]{0,49}|[0-9][0-9a-fA-F]{5})$/', $requested_tag)) {
        $hashtag_template = __DIR__ . '/skins/' . $active_skin . '/hashtag.php';
        if (file_exists($hashtag_template)) {
            $requested_tag = strtolower($requested_tag); // normalise
            snapsmack_log_hit($pdo, $settings, ['page_type' => 'hashtag', 'page_slug' => $requested_tag]);
            include __DIR__ . '/skins/' . $active_skin . '/skin-meta.php';
            ?><body class="is-hashtag-page"><div id="page-wrapper"><?php
            include $hashtag_template;
            ?></div><?php
            // Load global JS engines (social dock, sticky header, etc.) unless using Photogram
            if ($active_skin !== 'photogram') {
                include __DIR__ . '/core/footer-scripts.php';
            }
            ?></body></html><?php
            exit;
        }
    }

    // --- SEARCH ---
    // ?q=term routes to the active skin's search.php if it exists, gated on the
    // search_enabled setting. Photogram keeps its own ?pg=search flow (locked),
    // so it is excluded here. Mirrors the hashtag dispatch above.
    if (isset($_GET['q']) && ($settings['search_enabled'] ?? '0') === '1'
        && $active_skin !== 'photogram') {
        $search_template = __DIR__ . '/skins/' . $active_skin . '/search.php';
        if (file_exists($search_template)) {
            $search_q = trim((string)$_GET['q']);
            // A #tag query jumps straight to that hashtag archive (before output).
            if ($search_q !== '' && $search_q[0] === '#') {
                $tag_candidate = substr($search_q, 1);
                if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,49}$/', $tag_candidate)) {
                    header('Location: ' . BASE_URL . '?tag=' . rawurlencode(strtolower($tag_candidate)));
                    exit;
                }
            }
            snapsmack_log_hit($pdo, $settings, ['page_type' => 'search', 'page_slug' => mb_substr($search_q, 0, 100)]);
            include __DIR__ . '/skins/' . $active_skin . '/skin-meta.php';
            ?><body class="is-search-page"><div id="page-wrapper"><?php
            include $search_template;
            ?></div><?php
            include __DIR__ . '/core/footer-scripts.php';
            ?></body></html><?php
            exit;
        }
    }

    // --- REQUEST ROUTING (LATEST POST MODE) ---
    // --- IMAGE LOOKUP ---
    if ($requested_slug) {
        $stmt = $pdo->prepare("SELECT * FROM snap_images WHERE img_slug = ? AND img_status = 'published' LIMIT 1");
        $stmt->execute([$requested_slug]);
    } else {
        $stmt = $pdo->query("SELECT * FROM snap_images WHERE img_status = 'published' ORDER BY img_date DESC LIMIT 1");
    }
    $img = $stmt->fetch(PDO::FETCH_ASSOC);

    // --- STATIC PAGE FALLBACK ---
    // A bare slug arrives here via .htaccess (index.php?name=slug). If it is not
    // a published image it may be a static page (snap_pages) reached by its
    // pretty URL, e.g. /flkr-fckr. Resolve and render it the SAME way page.php
    // does so clean-URL pages work. (Keep this in sync with page.php lines ~93-160.)
    if ($requested_slug && !$img) {
        $pg_stmt = $pdo->prepare("SELECT * FROM snap_pages WHERE slug = ? AND is_active = 1 LIMIT 1");
        $pg_stmt->execute([$requested_slug]);
        $page_data = $pg_stmt->fetch(PDO::FETCH_ASSOC);

        if ($page_data) {
            $page_title = htmlspecialchars($page_data['title']);
            $skin_path  = 'skins/' . $active_skin;

            snapsmack_log_hit($pdo, $settings, ['page_type' => 'page', 'page_slug' => $requested_slug]);

            // Skin may fully override the static-page layout (e.g. Photogram).
            $skin_page_tpl = __DIR__ . '/' . $skin_path . '/skin-page.php';
            if (file_exists($skin_page_tpl)) {
                include $skin_page_tpl;
                exit;
            }

            if (file_exists(__DIR__ . '/' . $skin_path . '/skin-meta.php')) {
                include __DIR__ . '/' . $skin_path . '/skin-meta.php';
            }
            ?>
<body class="static-transmission">
    <div id="page-wrapper">
        <div id="scroll-stage">
            <?php
            $header_file = __DIR__ . '/' . $skin_path . '/skin-header.php';
            if (file_exists($header_file)) {
                include $header_file;
            } else {
                include __DIR__ . '/core/header.php';
            }
            ?>
            <div class="static-content">
                <h1 class="static-page-title"><?php echo $page_title; ?></h1>
                <?php if (!empty($page_data['image_asset'])):
                    $hero_size   = in_array($page_data['image_size']  ?? '', ['medium','small']) ? $page_data['image_size']  : 'full';
                    $hero_align  = in_array($page_data['image_align'] ?? '', ['left','right'])   ? $page_data['image_align'] : 'center';
                    $hero_shadow = !empty($page_data['image_shadow']) ? ' page-hero--shadow' : '';
                ?>
                    <div id="photobox" class="page-hero page-hero--<?php echo $hero_size; ?> page-hero--<?php echo $hero_align; ?><?php echo $hero_shadow; ?>">
                        <div class="main-photo">
                            <img src="<?php echo BASE_URL . ltrim($page_data['image_asset'], '/'); ?>"
                                 class="post-image"
                                 alt="<?php echo $page_title; ?>">
                        </div>
                    </div>
                <?php endif; ?>
                <div class="description">
                    <?php
                    if (!empty($page_data['content'])) {
                        echo $snapsmack->parseContent($page_data['content']);
                    } else {
                        echo "<p class='dim'>No content signal found for this sector.</p>";
                    }
                    ?>
                </div>
            </div>
            <?php
            $footer_file = __DIR__ . '/' . $skin_path . '/skin-footer.php';
            if (file_exists($footer_file)) {
                include $footer_file;
            }
            ?>
        </div>
    </div>
    <?php include __DIR__ . '/core/footer-scripts.php'; ?>
</body>
</html>
            <?php
            exit;
        }
    }

    // --- NAVIGATION LINKS ---
    // Fetches first, last, previous, and next image slugs based on publication date.
    // Timezone is configured globally in core/db.php.
    $now_local = date('Y-m-d H:i:s');
    $where_live = "WHERE img_status = 'published' AND img_date <= '$now_local'";

    // First/Last slug queries
    $f_res = $pdo->query("SELECT img_slug FROM snap_images $where_live ORDER BY img_date ASC LIMIT 1")->fetchColumn();
    if ($f_res) $first_slug = BASE_URL . $f_res;

    $l_res = $pdo->query("SELECT img_slug FROM snap_images $where_live ORDER BY img_date DESC LIMIT 1")->fetchColumn();
    if ($l_res) $last_slug = BASE_URL . $l_res;

    if ($img) {
        $current_date = $img['img_date'];

        // Previous image link
        $p_stmt = $pdo->prepare("SELECT img_slug FROM snap_images WHERE img_date < ? AND img_status = 'published' ORDER BY img_date DESC LIMIT 1");
        $p_stmt->execute([$current_date]);
        $p_res = $p_stmt->fetchColumn();
        if ($p_res) $prev_slug = BASE_URL . $p_res;

        // Next image link
        $n_stmt = $pdo->prepare("SELECT img_slug FROM snap_images WHERE img_date > ? AND img_status = 'published' ORDER BY img_date ASC LIMIT 1");
        $n_stmt->execute([$current_date]);
        $n_res = $n_stmt->fetchColumn();
        if ($n_res) $next_slug = BASE_URL . $n_res;

        // Count approved comments for display
        $c_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_comments WHERE img_id = ? AND is_approved = 1");
        $c_stmt->execute([$img['id']]);
        $comment_count = $c_stmt->fetchColumn();
    }
} catch (Exception $e) {
    die("GATEWAY_HALT: " . $e->getMessage());
}

$skin_path = 'skins/' . $active_skin;
$page_title = $img['img_title'] ?? 'Home';

// ── Early exit for JSON AJAX requests ────────────────────────────────────────
// Must happen BEFORE skin-meta.php outputs any HTML, otherwise
// feed.php cannot set Content-Type: application/json headers.
if (($_GET['format'] ?? '') === 'json' && ($_GET['pg'] ?? '') !== '') {
    $skin_path = 'skins/' . $active_skin;
    $landing_file = __DIR__ . '/' . $skin_path . '/landing.php';
    if (file_exists($landing_file)) {
        include $landing_file;
        exit;
    }
}

// --- STATS LOGGING ---
if ($img) {
    if (!$requested_slug && !$force_blog && file_exists(__DIR__ . '/' . $skin_path . '/landing.php')) {
        snapsmack_log_hit($pdo, $settings, ['page_type' => 'landing', 'page_slug' => $img['img_slug'] ?? null, 'image_id' => $img['id'] ?? null]);
    } else {
        snapsmack_log_hit($pdo, $settings, ['page_type' => 'image', 'page_slug' => $img['img_slug'] ?? null, 'image_id' => $img['id'] ?? null]);
    }
}
snapsmack_maybe_rollup($pdo);

// ── Modal fragment request — skip page shell, return only layout fragment ──
// tg-modal.js fetches ?modal=1 to get a bare .tg-post-ig fragment for the
// overlay. Skip skin-meta.php, <body>, and page-wrapper entirely.
if (!empty($_GET['modal']) && $requested_slug && $img
    && file_exists(__DIR__ . '/' . $skin_path . '/layout.php')) {
    include __DIR__ . '/' . $skin_path . '/layout.php';
    exit;
}

include __DIR__ . '/' . $skin_path . '/skin-meta.php';
?>
<body class="is-photo-page<?php echo $landing_only_active ? ' landing-only' : ''; ?>">
<div id="page-wrapper">
    <?php
    if ($img && file_exists(__DIR__ . '/' . $skin_path . '/layout.php')) {
        // Skin landing page: if no explicit slug was requested, the blog isn't being
        // forced, and the skin provides a landing.php, show that instead of the single image.
        if (!$requested_slug && !$force_blog && file_exists(__DIR__ . '/' . $skin_path . '/landing.php')) {
            include __DIR__ . '/' . $skin_path . '/landing.php';
        } else {
            include __DIR__ . '/' . $skin_path . '/layout.php';
        }
    } elseif (!$requested_slug && !$force_blog && file_exists(__DIR__ . '/' . $skin_path . '/landing.php')) {
        // No current image — e.g. a freshly set-up site with no posts yet — but the
        // active skin has a landing page (which renders its own, possibly empty, grid).
        // Render it so an empty site shows its skin instead of a misleading 404.
        include __DIR__ . '/' . $skin_path . '/landing.php';
    } elseif (is_dir(__DIR__ . '/' . $skin_path)) {
        // Skin is installed and present, but there's nothing to render and it has no
        // landing page. A real empty state — not a missing-skin error.
        echo "<div class='not-found-msg'><h1>Nothing here yet</h1>This site doesn't have any posts to show.</div>";
    } else {
        // The active skin's directory is genuinely absent — the real error.
        echo "<div class='not-found-msg'><h1>404</h1>Transmission Lost.<br><small>Looking for: $skin_path</small></div>";
    }
    ?>
</div>

<div id="snap-nav-data"
     data-prev="<?php echo htmlspecialchars((string)$prev_slug); ?>"
     data-next="<?php echo htmlspecialchars((string)$next_slug); ?>"
     data-first="<?php echo htmlspecialchars((string)$first_slug); ?>"
     data-last="<?php echo htmlspecialchars((string)$last_slug); ?>"
     hidden></div>
<?php
// Load global JS engines (social dock, sticky header, etc.) unless using Photogram,
// which has its own mobile-optimized UI and doesn't need these overlays.
if ($active_skin !== 'photogram') {
    include __DIR__ . '/core/footer-scripts.php';
}
?>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
