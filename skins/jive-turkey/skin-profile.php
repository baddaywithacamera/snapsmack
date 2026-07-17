<?php
/**
 * SNAPSMACK - JIVE TURKEY shared profile + sticky nav header
 *
 * Self-contained partial: renders the profile block (avatar, name/tagline,
 * post count, bio) and the sticky nav identically on the landing grid,
 * static pages, and the blogroll. Requires $pdo and $settings in scope.
 *
 * Pulling this into one place keeps the header identical across every Grid
 * page; previously it lived inline in landing.php only.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$show_profile = ($settings['jt_profile_header'] ?? '1') === '1';
$show_tagline = ($settings['jt_show_tagline']   ?? '1') === '1';

// ── Static pages for nav ───────────────────────────────────────────────────
try {
    $nav_pages = $pdo->query(
        "SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $nav_pages = [];
}

// ── Post count ─────────────────────────────────────────────────────────────
try {
    $_jt_count = $pdo->prepare(
        "SELECT COUNT(*) FROM snap_posts WHERE status = 'published' AND created_at <= ?"
    );
    $_jt_count->execute([date('Y-m-d H:i:s')]);
    $post_count = (int)$_jt_count->fetchColumn();
} catch (PDOException $e) {
    $post_count = 0;
}

// ── Avatar ─────────────────────────────────────────────────────────────────
$avatar_path     = $settings['skin_avatar'] ?? '';
$avatar_exists   = $avatar_path && file_exists(dirname(__DIR__, 2) . '/' . $avatar_path);
$avatar_initials = strtoupper(substr($settings['site_name'] ?? 'S', 0, 1));
$avatar_url      = $avatar_exists ? BASE_URL . htmlspecialchars($avatar_path) : '';

$tagline = trim($settings['site_tagline'] ?? '');
$bio     = trim($settings['site_description'] ?? '');

// ── Active-link detection ──────────────────────────────────────────────────
$_jt_script      = basename($_SERVER['SCRIPT_NAME'] ?? '');
$_jt_active_slug = $_GET['slug'] ?? null;
$_jt_on_blogroll = ($_jt_script === 'blogroll.php');
$_jt_on_home     = ($_jt_script === 'index.php' && !isset($_GET['s']) && $_jt_active_slug === null);

// ── Background treatment (skin admin → Treatment) ──────────────────────────
// Emitted on every Grid page. The full-screen layers sit behind the centred
// content card; CSS (:has(.jt-treatment-bg)) turns the card on only when present.
$_jt_treat_mode  = $settings['jt_treatment_mode']     ?? 'none';
$_jt_treat_img   = trim($settings['jt_treatment_image'] ?? '');
$_jt_treat_color = trim($settings['jt_treatment_color'] ?? '');
$_jt_treat_pos   = $settings['jt_treatment_position'] ?? 'center';
$_jt_treat_ov    = (int)($settings['jt_treatment_overlay'] ?? 0); // -100 dark .. +100 light
$_jt_has_treat   = ($_jt_treat_mode === 'image' && $_jt_treat_img !== '')
                || ($_jt_treat_mode === 'color' && $_jt_treat_color !== '');

$_jt_bg_style = '';
$_jt_ov_style = '';
if ($_jt_has_treat) {
    if ($_jt_treat_mode === 'image' && $_jt_treat_img !== '') {
        $_jt_treat_pos_css = ($_jt_treat_pos === 'top')    ? 'center top'
                           : (($_jt_treat_pos === 'bottom') ? 'center bottom' : 'center center');
        $_jt_bg_style = "background-image:url('" . BASE_URL . htmlspecialchars($_jt_treat_img) . "');"
                      . 'background-position:' . $_jt_treat_pos_css . ';';
    } elseif ($_jt_treat_mode === 'color' && $_jt_treat_color !== '') {
        $_jt_bg_style = 'background-color:' . htmlspecialchars($_jt_treat_color) . ';';
    }
    if ($_jt_treat_ov < 0) {
        $_jt_ov_style = 'background-color:rgba(0,0,0,' . round(min(100, -$_jt_treat_ov) / 100, 2) . ');';
    } elseif ($_jt_treat_ov > 0) {
        $_jt_ov_style = 'background-color:rgba(255,255,255,' . round(min(100, $_jt_treat_ov) / 100, 2) . ');';
    }
}

// ── JIVE TURKEY atmosphere config (Layer 1 + Layer 2 feed) ──────────────────────
// Resolves the active palette + sky from the data-driven registry and emits the
// fixed full-viewport jive-turkey background. The same element carries the wave
// config (palette / direction / speed / intensity) that jive-turkey-wave.js reads.
// Emitted once per page here (skin-profile renders on every JIVE TURKEY page); the
// modal fragment path never includes skin-profile, so it is never duplicated.
$_jt_reg    = include __DIR__ . '/jive-turkey-config.php';
$_jt_cws    = $_jt_reg['colourways'] ?? [];
$_jt_key    = strtoupper($settings['jt_palette'] ?? 'HARVEST');
if (!isset($_jt_cws[$_jt_key])) $_jt_key = isset($_jt_cws['HARVEST']) ? 'HARVEST' : (array_key_first($_jt_cws) ?: 'HARVEST');
$_jt_active = $_jt_cws[$_jt_key] ?? ['cream' => '#f2e2c0', 'colors' => ['#d99a2b', '#bd4e1f', '#6b3f24'], 'centre' => '#d99a2b', 'dark' => '#38220f'];

// Full colourway map for the JS (SURPRISE / CYCLE random-colour + border matching).
$_jt_cw_js = [];
foreach ($_jt_cws as $_k => $_c) {
    $_cols = array_values($_c['colors'] ?? []);
    $_jt_cw_js[$_k] = [
        'cream'  => $_c['cream']  ?? '#f2e2c0',
        'colors' => $_cols,
        'centre' => $_c['centre'] ?? ($_cols[0] ?? '#d99a2b'),
        'dark'   => $_c['dark']   ?? '#38220f',
    ];
}

$_jt_mode    = $settings['jt_mode'] ?? 'surprise';
$_jt_random  = (($settings['jt_random_colour'] ?? '1') === '0') ? '0' : '1';
$_jt_speed   = max(1, min(100, (int)($settings['jt_speed'] ?? 45)));
$_jt_cycle   = max(6, min(60, (int)($settings['jt_cycle_time'] ?? 14)));           // seconds per mode in CYCLE
$_jt_bon     = (($settings['jt_border_on'] ?? '1') === '0') ? '0' : '1';           // inside border on/off
$_jt_bwidth  = max(5, min(15, (int)($settings['jt_border_width'] ?? 12)));         // border width (px)
$_jt_bspeed  = max(0, min(100, (int)($settings['jt_border_speed'] ?? 60)));        // colour-change speed
$_jt_bwave   = max(0, min(100, (int)($settings['jt_border_wave']  ?? 45)));        // wave stagger
$_jt_bdir    = $settings['jt_border_dir'] ?? 'dtlbr';                              // wave direction
$_jt_bw      = $_jt_bwidth;                                                         // --tile-bw follows the border width
$_jt_colors  = array_values($_jt_active['colors'] ?? []);                          // active colourway (back-compat)
$_jt_field   = $_jt_active['cream'] ?? '#f2e2c0';
$_jt_radius  = (int)round($_jt_bw * 1.4);
// Legacy vars still consumed by the #jt-vars style block below.
$_jt_sky     = $settings['jt_sky'] ?? '#000000';
$_jt_bo      = number_format(max(10, min(100, (int)($settings['jt_border_opacity'] ?? 100))) / 100, 2);

// ── Nav line colour mode ───────────────────────────────────────────────────
// 'jive-turkey' → lines track the live wave colour; 'static' → use --border-color.
$_jt_nav_line_mode = $settings['jt_nav_line_mode'] ?? 'static';
$_jt_nav_line_css  = ($_jt_nav_line_mode === 'jive-turkey')
    ? '--nav-line-color:var(--jt-wave-color,var(--border-color));'
    : '';
// Opacity of the dark companion divider lines (0–100% → 0–1).
$_jt_nav_line_op   = number_format(max(0, min(100, (int)($settings['jt_nav_line_opacity'] ?? 100))) / 100, 2);

// ── Nav menu text glow ─────────────────────────────────────────────────────
// Outer glow on the nav links (home / blogroll / pages). Defaults to jive-turkey
// green so the menu stays legible over the animated background; admin-tunable.
$_jt_navglow_hex    = trim($settings['jt_nav_glow_color'] ?? '#61e96e');
$_jt_navglow_sz     = max(0, min(40,  (int)($settings['jt_nav_glow_size']    ?? 8)));
$_jt_navglow_op     = max(0, min(100, (int)($settings['jt_nav_glow_opacity'] ?? 45)));
$_jt_navglow_css    = 'none';
$_jt_navglow_strong = 'none';
if ($_jt_navglow_sz > 0 && $_jt_navglow_op > 0) {
    $_ngc = ltrim($_jt_navglow_hex, '#');
    if (strlen($_ngc) === 3) $_ngc = $_ngc[0].$_ngc[0].$_ngc[1].$_ngc[1].$_ngc[2].$_ngc[2];
    $_ngr = hexdec(substr($_ngc, 0, 2));
    $_ngg = hexdec(substr($_ngc, 2, 2));
    $_ngb = hexdec(substr($_ngc, 4, 2));
    $_nga = number_format($_jt_navglow_op / 100, 2);
    $_jt_navglow_css = sprintf(
        '0 0 %dpx rgba(%d,%d,%d,%s),0 0 %dpx rgba(%d,%d,%d,%s)',
        $_jt_navglow_sz, $_ngr, $_ngg, $_ngb, $_nga,
        $_jt_navglow_sz * 2, $_ngr, $_ngg, $_ngb, number_format($_jt_navglow_op / 200, 2)
    );
    $_jt_navglow_strong = sprintf(
        '0 0 %dpx rgba(%d,%d,%d,%s),0 0 %dpx rgba(%d,%d,%d,%s)',
        $_jt_navglow_sz + 2, $_ngr, $_ngg, $_ngb, number_format(min(1, $_jt_navglow_op / 100 * 1.5), 2),
        ($_jt_navglow_sz + 2) * 2, $_ngr, $_ngg, $_ngb, $_nga
    );
}

// ── Profile text glow ─────────────────────────────────────────────────────
// Composited text-shadow for readability over the shifting jive-turkey background.
$_jt_glow_hex  = trim($settings['jt_glow_color'] ?? '#000000');
$_jt_glow_sz   = max(0, min(40, (int)($settings['jt_glow_size']    ?? 0)));
$_jt_glow_op   = max(0, min(100, (int)($settings['jt_glow_opacity'] ?? 0)));
// Default: a soft DARK halo so the title/tagline stay legible over the bright,
// shifting jive-turkey curtains (light text over a bright curtain needs dark
// separation, not a light glow). The admin "Text Glow" settings override this
// when configured — so this is just the out-of-the-box readability floor.
$_jt_glow_css  = 'none';  // no forced floor — Text Glow sliders at 0 = truly off
if ($_jt_glow_sz > 0 && $_jt_glow_op > 0) {
    // Parse hex → RGB for rgba() composition.
    $_gc = ltrim($_jt_glow_hex, '#');
    if (strlen($_gc) === 3) $_gc = $_gc[0].$_gc[0].$_gc[1].$_gc[1].$_gc[2].$_gc[2];
    $_gr = hexdec(substr($_gc, 0, 2));
    $_gg = hexdec(substr($_gc, 2, 2));
    $_gb = hexdec(substr($_gc, 4, 2));
    $_ga = number_format($_jt_glow_op / 100, 2);
    // Two passes at the configured size, second at 2× for spread.
    $_jt_glow_css = sprintf(
        '0 0 %dpx rgba(%d,%d,%d,%s),0 0 %dpx rgba(%d,%d,%d,%s)',
        $_jt_glow_sz, $_gr, $_gg, $_gb, $_ga,
        $_jt_glow_sz * 2, $_gr, $_gg, $_gb, number_format($_jt_glow_op / 200, 2)
    );
}

// ── Panel (all pages) — ONE tinted backing behind the content column on every
// page (landing, static, archive, hashtag, blogroll). Full viewport height,
// widened by Extend each side. Ported from INSTANT CAMERA → --panel-bg,
// --panel-extend. Colour + opacity + extend admin-tunable.
$_jt_panel_hex    = trim($settings['jt_panel_color'] ?? '#0a0e1a');
$_jt_panel_op     = max(0, min(100, (int)($settings['jt_panel_opacity'] ?? 0)));
$_jt_panel_extend = max(0, min(100, (int)($settings['jt_panel_extend'] ?? 0)));
$_jt_panel_bg     = 'transparent';
if ($_jt_panel_op > 0) {
    $_pc = ltrim($_jt_panel_hex, '#');
    if (strlen($_pc) === 3) $_pc = $_pc[0].$_pc[0].$_pc[1].$_pc[1].$_pc[2].$_pc[2];
    $_jt_panel_bg = sprintf('rgba(%d,%d,%d,%s)',
        hexdec(substr($_pc, 0, 2)), hexdec(substr($_pc, 2, 2)), hexdec(substr($_pc, 4, 2)),
        number_format($_jt_panel_op / 100, 2));
}
// ── Navbar bg, posts glow, nav-line shadow, landing panel (mirrors IC) ──────
$_jt_navbar_hex = trim($settings['jt_navbar_color'] ?? '#0a0e1a');
// Split navbar opacity — landing vs content pages (inner falls back to landing
// when blank, so existing installs are unchanged until Other Pages is set).
$_jt_navbar_op_landing = max(0, min(100, (int)($settings['jt_navbar_opacity'] ?? 0)));
$_jt_navbar_op_inner   = ($settings['jt_navbar_opacity_inner'] ?? '') !== ''
    ? max(0, min(100, (int)$settings['jt_navbar_opacity_inner']))
    : $_jt_navbar_op_landing;
$_jt_navbar_op  = $_jt_on_home ? $_jt_navbar_op_landing : $_jt_navbar_op_inner;
$_jt_navbar_bg  = 'transparent';
if ($_jt_navbar_op > 0) {
    $_c = ltrim($_jt_navbar_hex, '#'); if (strlen($_c)===3) $_c=$_c[0].$_c[0].$_c[1].$_c[1].$_c[2].$_c[2];
    $_jt_navbar_bg = sprintf('rgba(%d,%d,%d,%s)', hexdec(substr($_c,0,2)),hexdec(substr($_c,2,2)),hexdec(substr($_c,4,2)), number_format($_jt_navbar_op/100,2));
}
$_jt_pg_hex = trim($settings['jt_posts_glow_color'] ?? '#000000');
$_jt_pg_sz  = max(0, min(40,  (int)($settings['jt_posts_glow_size']    ?? 0)));
$_jt_pg_op  = max(0, min(100, (int)($settings['jt_posts_glow_opacity'] ?? 0)));
$_jt_posts_glow = 'none';
if ($_jt_pg_sz > 0 && $_jt_pg_op > 0) {
    $_c = ltrim($_jt_pg_hex, '#'); if (strlen($_c)===3) $_c=$_c[0].$_c[0].$_c[1].$_c[1].$_c[2].$_c[2];
    $_r=hexdec(substr($_c,0,2)); $_g=hexdec(substr($_c,2,2)); $_b=hexdec(substr($_c,4,2));
    $_jt_posts_glow = sprintf('0 0 %dpx rgba(%d,%d,%d,%s),0 0 %dpx rgba(%d,%d,%d,%s)', $_jt_pg_sz,$_r,$_g,$_b,number_format($_jt_pg_op/100,2), $_jt_pg_sz*2,$_r,$_g,$_b,number_format($_jt_pg_op/200,2));
}
$_jt_nls_hex = trim($settings['jt_navline_shadow_color'] ?? '#000000');
$_jt_nls_sz  = max(0, min(3,   (int)($settings['jt_navline_shadow_size']    ?? 0)));
$_jt_nls_op  = max(0, min(100, (int)($settings['jt_navline_shadow_opacity'] ?? 40)));
$_jt_navline_shadow = 'none';
if ($_jt_nls_sz > 0 && $_jt_nls_op > 0) {
    $_c = ltrim($_jt_nls_hex, '#'); if (strlen($_c)===3) $_c=$_c[0].$_c[0].$_c[1].$_c[1].$_c[2].$_c[2];
    $_nr=hexdec(substr($_c,0,2)); $_ng=hexdec(substr($_c,2,2)); $_nb=hexdec(substr($_c,4,2));
    $_na=number_format($_jt_nls_op/100,2); $_n=$_jt_nls_sz;
    // Top + bottom lines only, no side bleed (negative spread cancels the blur horizontally).
    $_jt_navline_shadow = sprintf('0 %1$dpx %1$dpx -%1$dpx rgba(%2$d,%3$d,%4$d,%5$s),inset 0 %1$dpx %1$dpx -%1$dpx rgba(%2$d,%3$d,%4$d,%5$s)', $_n,$_nr,$_ng,$_nb,$_na);
}
?>

<!-- JIVE TURKEY tile vars: border width / corner radius / ring opacity / sky base -->
<style id="jt-vars">:root{--tile-bw:<?php echo $_jt_bw; ?>px;--tile-radius:<?php echo $_jt_radius; ?>px;--ring-op:<?php echo $_jt_bo; ?>;--jt-sky:<?php echo htmlspecialchars($_jt_sky); ?>;--profile-text-glow:<?php echo htmlspecialchars($_jt_glow_css); ?>;--nav-line-opacity:<?php echo $_jt_nav_line_op; ?>;--nav-text-glow:<?php echo htmlspecialchars($_jt_navglow_css); ?>;--nav-text-glow-strong:<?php echo htmlspecialchars($_jt_navglow_strong); ?>;--panel-bg:<?php echo htmlspecialchars($_jt_panel_bg); ?>;--panel-extend:<?php echo (int)$_jt_panel_extend; ?>px;--jt-navbar-bg:<?php echo htmlspecialchars($_jt_navbar_bg); ?>;--posts-glow:<?php echo htmlspecialchars($_jt_posts_glow); ?>;--jt-navline-shadow:<?php echo htmlspecialchars($_jt_navline_shadow); ?>;<?php echo $_jt_nav_line_css; ?>}</style>

<!-- JIVE TURKEY config carrier — read by ss-engine-jive-turkey.js (Layer 1
     background, all modes + SURPRISE) and ss-engine-jive-border.js (Layer 2 tile
     borders). The chosen colourway is broadcast on a jt:colourway event so the
     borders always match the background, including under SURPRISE / CYCLE. -->
<div class="jt-jive-turkey-bg" aria-hidden="true"
     data-jt-mode="<?php echo htmlspecialchars($_jt_mode); ?>"
     data-jt-colourway="<?php echo htmlspecialchars($_jt_key); ?>"
     data-jt-colourways='<?php echo htmlspecialchars(json_encode($_jt_cw_js), ENT_QUOTES); ?>'
     data-jt-palette='<?php echo htmlspecialchars(json_encode($_jt_colors), ENT_QUOTES); ?>'
     data-jt-field="<?php echo htmlspecialchars($_jt_field); ?>"
     data-jt-speed="<?php echo $_jt_speed; ?>"
     data-jt-cycle="<?php echo $_jt_cycle; ?>"
     data-jt-random-colour="<?php echo $_jt_random; ?>"
     data-jt-border-enabled="<?php echo $_jt_bon; ?>"
     data-jt-border-width="<?php echo $_jt_bwidth; ?>"
     data-jt-border-speed="<?php echo $_jt_bspeed; ?>"
     data-jt-border-wave="<?php echo $_jt_bwave; ?>"
     data-jt-border-dir="<?php echo htmlspecialchars($_jt_bdir); ?>"></div>

<!-- Readability panel: centred translucent column behind the content, full
     viewport height (reaches the top, runs behind the footer) on every page
     — landing, static, archive, hashtag, blogroll. Ported from INSTANT CAMERA.
     Width + tint + side gutters are admin-tunable (PANEL controls). -->
<div class="jt-panel" aria-hidden="true"></div>

<?php if ($_jt_has_treat): ?>
<div class="jt-treatment-bg" style="<?php echo $_jt_bg_style; ?>" aria-hidden="true"></div>
<?php if ($_jt_ov_style !== ''): ?>
<div class="jt-treatment-overlay" style="<?php echo $_jt_ov_style; ?>" aria-hidden="true"></div>
<?php endif; ?>
<?php endif; ?>

<?php if ($show_profile): ?>
<!-- ── Profile Header (shared across all Grid pages) ───────────────────────── -->
<section class="jt-profile">
    <div class="jt-profile-avatar<?php echo $avatar_exists ? ' jt-profile-avatar--zoom' : ''; ?>"
         <?php if ($avatar_exists): ?>role="button" tabindex="0"
         aria-label="View profile photo"
         data-jt-lightbox="<?php echo $avatar_url; ?>"<?php endif; ?>>
        <?php if ($avatar_exists): ?>
            <img src="<?php echo $avatar_url; ?>" alt="Profile avatar">
        <?php else: ?>
            <span class="jt-profile-avatar-initials"><?php echo htmlspecialchars($avatar_initials); ?></span>
        <?php endif; ?>
    </div>

    <div class="jt-profile-info">
        <div class="jt-profile-nameline">
            <h1 class="jt-profile-username"><?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?></h1>
            <?php if ($show_tagline && $tagline): ?>
            <span class="jt-profile-tagline-sep">/</span>
            <p class="jt-profile-tagline"><?php echo htmlspecialchars($tagline); ?></p>
            <?php endif; ?>
        </div>

        <div class="jt-profile-stats">
            <div class="jt-profile-stat">
                <span class="jt-profile-stat-num"><?php echo number_format($post_count); ?></span>
                <span class="jt-profile-stat-label">post<?php echo $post_count !== 1 ? 's' : ''; ?></span>
            </div>
        </div>

        <?php if ($bio): ?>
        <p class="jt-profile-bio"><?php echo nl2br(htmlspecialchars($bio)); ?></p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- ── Sticky Nav ──────────────────────────────────────────────────────────── -->
<nav class="jt-sticky-nav" aria-label="Site navigation">
    <div class="jt-sticky-nav-inner">
        <?php if ($avatar_exists): ?>
            <img class="jt-sticky-avatar" src="<?php echo $avatar_url; ?>"
                 alt="<?php echo htmlspecialchars($settings['site_name'] ?? ''); ?>" aria-hidden="true">
        <?php else: ?>
            <span class="jt-sticky-avatar-initials" aria-hidden="true"><?php echo htmlspecialchars($avatar_initials); ?></span>
        <?php endif; ?>

        <ul class="jt-sticky-nav-links">
            <?php
            // Nav content is driven by the Menu Manager (nav_menu_json) via the
            // shared partial — one source of truth for every GRAMOFSMACK skin's
            // nav. Falls back to Home + Blogroll + pages when no menu is saved.
            // Active-state is derived inside the partial, so the skin namespace
            // ($_tg_/$_jt_/$_pa_) doesn't matter.
            include dirname(__DIR__, 2) . '/core/gram-nav-links.php';
            ?>
        </ul>
    </div>
</nav>
<?php // ===== SNAPSMACK EOF =====