<?php
/**
 * SNAPSMACK - The Grid shared profile + sticky nav header
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


$show_profile = ($settings['ic_profile_header'] ?? '1') === '1';
$show_tagline = ($settings['ic_show_tagline']   ?? '1') === '1';

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
    $_tg_count = $pdo->prepare(
        "SELECT COUNT(*) FROM snap_posts WHERE status = 'published' AND created_at <= ?"
    );
    $_tg_count->execute([date('Y-m-d H:i:s')]);
    $post_count = (int)$_tg_count->fetchColumn();
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
$_tg_script      = basename($_SERVER['SCRIPT_NAME'] ?? '');
$_tg_active_slug = $_GET['slug'] ?? null;
$_tg_on_blogroll = ($_tg_script === 'blogroll.php');
$_tg_on_home     = ($_tg_script === 'index.php' && !isset($_GET['s']) && $_tg_active_slug === null);

// ── Background treatment (skin admin → Treatment) ──────────────────────────
// Emitted on every Grid page. The full-screen layers sit behind the centred
// content card; CSS (:has(.tg-treatment-bg)) turns the card on only when present.
$_tg_treat_mode  = $settings['ic_treatment_mode']     ?? 'none';
$_tg_treat_img   = trim($settings['ic_treatment_image'] ?? '');
$_tg_treat_color = trim($settings['ic_treatment_color'] ?? '');
$_tg_treat_pos   = $settings['ic_treatment_position'] ?? 'center';
$_tg_treat_ov    = (int)($settings['ic_treatment_overlay'] ?? 0); // -100 dark .. +100 light
$_tg_has_treat   = ($_tg_treat_mode === 'image' && $_tg_treat_img !== '')
                || ($_tg_treat_mode === 'color' && $_tg_treat_color !== '');

$_tg_bg_style = '';
$_tg_ov_style = '';
if ($_tg_has_treat) {
    if ($_tg_treat_mode === 'image' && $_tg_treat_img !== '') {
        $_tg_treat_pos_css = ($_tg_treat_pos === 'top')    ? 'center top'
                           : (($_tg_treat_pos === 'bottom') ? 'center bottom' : 'center center');
        $_tg_bg_style = "background-image:url('" . BASE_URL . htmlspecialchars($_tg_treat_img) . "');"
                      . 'background-position:' . $_tg_treat_pos_css . ';';
    } elseif ($_tg_treat_mode === 'color' && $_tg_treat_color !== '') {
        $_tg_bg_style = 'background-color:' . htmlspecialchars($_tg_treat_color) . ';';
    }
    if ($_tg_treat_ov < 0) {
        $_tg_ov_style = 'background-color:rgba(0,0,0,' . round(min(100, -$_tg_treat_ov) / 100, 2) . ');';
    } elseif ($_tg_treat_ov > 0) {
        $_tg_ov_style = 'background-color:rgba(255,255,255,' . round(min(100, $_tg_treat_ov) / 100, 2) . ');';
    }
}

// ── INSTANT CAMERA: tile aspect + white scrim + background mode ──────────────
// Tile aspect matches the print format so scans show uncropped. Trigram cover
// (3 wide × 1 tall) is derived in style.css from the same var.
$_ic_format = $settings['ic_format'] ?? 'instax_square';
$_ic_ratios = [
    'polaroid'      => '79 / 97',   // 600 / OneStep image area (portrait)
    'sx70'          => '1 / 1',     // square image
    'go'            => '47 / 60',   // portrait
    'instax_mini'   => '62 / 46',   // landscape
    'instax_wide'   => '99 / 62',   // wide landscape
    'instax_square' => '1 / 1',     // near-square
];
if ($_ic_format === 'custom') {
    $_ic_raw = trim($settings['ic_custom_ratio'] ?? '1:1');
    $_ic_aspect = (preg_match('/^\s*(\d{1,4})\s*[:\/xX]\s*(\d{1,4})\s*$/', $_ic_raw, $_m)
                   && (int)$_m[1] > 0 && (int)$_m[2] > 0)
                ? ((int)$_m[1] . ' / ' . (int)$_m[2]) : '1 / 1';
} else {
    $_ic_aspect = $_ic_ratios[$_ic_format] ?? '1 / 1';
}
$_ic_scrim  = max(10, min(90, (int)($settings['ic_scrim'] ?? 60))) / 100;
$_ic_bgmode = $settings['ic_bg_mode'] ?? 'mayhem';

// ── Text glow (readability over the drifting tabletop) — ported from AURORA ──
// Profile title / tagline / bio halo. A subtle dark floor keeps text legible
// even with no custom glow set; the Text Glow controls override it.
$_ic_glow_hex = trim($settings['ic_glow_color'] ?? '#000000');
$_ic_glow_sz  = max(0, min(40,  (int)($settings['ic_glow_size']    ?? 0)));
$_ic_glow_op  = max(0, min(100, (int)($settings['ic_glow_opacity'] ?? 0)));
$_ic_glow_css = 'none';  // no forced floor — Text Glow sliders at 0 = truly off
if ($_ic_glow_sz > 0 && $_ic_glow_op > 0) {
    $_gc = ltrim($_ic_glow_hex, '#');
    if (strlen($_gc) === 3) $_gc = $_gc[0].$_gc[0].$_gc[1].$_gc[1].$_gc[2].$_gc[2];
    $_gr = hexdec(substr($_gc, 0, 2)); $_gg = hexdec(substr($_gc, 2, 2)); $_gb = hexdec(substr($_gc, 4, 2));
    $_ic_glow_css = sprintf(
        '0 0 %dpx rgba(%d,%d,%d,%s),0 0 %dpx rgba(%d,%d,%d,%s)',
        $_ic_glow_sz, $_gr, $_gg, $_gb, number_format($_ic_glow_op / 100, 2),
        $_ic_glow_sz * 2, $_gr, $_gg, $_gb, number_format($_ic_glow_op / 200, 2)
    );
}

// ── Nav glow — ported from AURORA. Outer halo behind the menu links. ─────────
$_ic_navglow_hex    = trim($settings['ic_nav_glow_color'] ?? '#000000');
$_ic_navglow_sz     = max(0, min(40,  (int)($settings['ic_nav_glow_size']    ?? 0)));
$_ic_navglow_op     = max(0, min(100, (int)($settings['ic_nav_glow_opacity'] ?? 45)));
$_ic_navglow_css    = 'none';
$_ic_navglow_strong = 'none';
if ($_ic_navglow_sz > 0 && $_ic_navglow_op > 0) {
    $_ngc = ltrim($_ic_navglow_hex, '#');
    if (strlen($_ngc) === 3) $_ngc = $_ngc[0].$_ngc[0].$_ngc[1].$_ngc[1].$_ngc[2].$_ngc[2];
    $_ngr = hexdec(substr($_ngc, 0, 2)); $_ngg = hexdec(substr($_ngc, 2, 2)); $_ngb = hexdec(substr($_ngc, 4, 2));
    $_nga = number_format($_ic_navglow_op / 100, 2);
    $_ic_navglow_css = sprintf(
        '0 0 %dpx rgba(%d,%d,%d,%s),0 0 %dpx rgba(%d,%d,%d,%s)',
        $_ic_navglow_sz, $_ngr, $_ngg, $_ngb, $_nga,
        $_ic_navglow_sz * 2, $_ngr, $_ngg, $_ngb, number_format($_ic_navglow_op / 200, 2)
    );
    $_ic_navglow_strong = sprintf(
        '0 0 %dpx rgba(%d,%d,%d,%s),0 0 %dpx rgba(%d,%d,%d,%s)',
        $_ic_navglow_sz + 2, $_ngr, $_ngg, $_ngb, number_format(min(1, $_ic_navglow_op / 100 * 1.5), 2),
        ($_ic_navglow_sz + 2) * 2, $_ngr, $_ngg, $_ngb, $_nga
    );
}

// ── Panel (all pages) — ONE tinted backing behind the content column on every
// page (landing, About/static, archive, hashtag) so text + prints stay legible
// over the animated background. Full content width, reaches the top, bleeds out
// by Extend (side gutters) each side. Colour + opacity + extend admin-tunable.
$_ic_panel_hex    = trim($settings['ic_panel_color'] ?? '#ffffff');
$_ic_panel_op     = max(0, min(100, (int)($settings['ic_panel_opacity'] ?? 0)));
$_ic_panel_extend = max(0, min(100, (int)($settings['ic_panel_extend'] ?? 0)));
$_ic_panel_bg     = 'transparent';
if ($_ic_panel_op > 0) {
    $_pc = ltrim($_ic_panel_hex, '#');
    if (strlen($_pc) === 3) $_pc = $_pc[0].$_pc[0].$_pc[1].$_pc[1].$_pc[2].$_pc[2];
    $_ic_panel_bg = sprintf('rgba(%d,%d,%d,%s)',
        hexdec(substr($_pc, 0, 2)), hexdec(substr($_pc, 2, 2)), hexdec(substr($_pc, 4, 2)),
        number_format($_ic_panel_op / 100, 2));
}

// ── Sticky navbar background — transparent by default (tabletop shows through);
// admin can dial colour + opacity to make it solid. ────────────────────────
$_ic_nav_hex = trim($settings['ic_nav_color'] ?? '#ffffff');
$_ic_nav_op  = max(0, min(100, (int)($settings['ic_nav_opacity'] ?? 0)));
$_ic_nav_bg  = 'transparent';
if ($_ic_nav_op > 0) {
    $_nc = ltrim($_ic_nav_hex, '#');
    if (strlen($_nc) === 3) $_nc = $_nc[0].$_nc[0].$_nc[1].$_nc[1].$_nc[2].$_nc[2];
    $_ic_nav_bg = sprintf('rgba(%d,%d,%d,%s)',
        hexdec(substr($_nc, 0, 2)), hexdec(substr($_nc, 2, 2)), hexdec(substr($_nc, 4, 2)),
        number_format($_ic_nav_op / 100, 2));
}

// ── "Posts" label colour + glow ─────────────────────────────────────────────
$_ic_posts_color = trim($settings['ic_posts_color'] ?? '#777777');
$_ic_pg_hex = trim($settings['ic_posts_glow_color'] ?? '#000000');
$_ic_pg_sz  = max(0, min(40,  (int)($settings['ic_posts_glow_size']    ?? 0)));
$_ic_pg_op  = max(0, min(100, (int)($settings['ic_posts_glow_opacity'] ?? 0)));
$_ic_posts_glow = 'none';
if ($_ic_pg_sz > 0 && $_ic_pg_op > 0) {
    $_c = ltrim($_ic_pg_hex, '#');
    if (strlen($_c) === 3) $_c = $_c[0].$_c[0].$_c[1].$_c[1].$_c[2].$_c[2];
    $_pgr = hexdec(substr($_c,0,2)); $_pgg = hexdec(substr($_c,2,2)); $_pgb = hexdec(substr($_c,4,2));
    $_ic_posts_glow = sprintf('0 0 %dpx rgba(%d,%d,%d,%s),0 0 %dpx rgba(%d,%d,%d,%s)',
        $_ic_pg_sz, $_pgr, $_pgg, $_pgb, number_format($_ic_pg_op/100,2),
        $_ic_pg_sz*2, $_pgr, $_pgg, $_pgb, number_format($_ic_pg_op/200,2));
}

// ── Nav divider line colour + (capped, down-right) drop shadow ───────────────
$_ic_navline_color = trim($settings['ic_navline_color'] ?? '#e0e0e0');
$_ic_navline_op    = max(0, min(100, (int)($settings['ic_navline_opacity'] ?? 100)));
$_ic_nls_hex = trim($settings['ic_navline_shadow_color'] ?? '#000000');
$_ic_nls_sz  = max(0, min(3,   (int)($settings['ic_navline_shadow_size']    ?? 0)));
$_ic_nls_op  = max(0, min(100, (int)($settings['ic_navline_shadow_opacity'] ?? 40)));
$_ic_navline_shadow = 'none';
if ($_ic_nls_sz > 0 && $_ic_nls_op > 0) {
    $_c = ltrim($_ic_nls_hex, '#');
    if (strlen($_c) === 3) $_c = $_c[0].$_c[0].$_c[1].$_c[1].$_c[2].$_c[2];
    // Shadow under the TOP and BOTTOM divider lines ONLY — never the left/right
    // ends. No horizontal offset, and the negative spread (-n) cancels the blur
    // horizontally so nothing shows on the sides. Outset = below the bottom
    // line; inset = below the top line. Capped at 3px.
    $_nr = hexdec(substr($_c,0,2)); $_ng = hexdec(substr($_c,2,2)); $_nb = hexdec(substr($_c,4,2));
    $_na = number_format($_ic_nls_op/100,2); $_n = $_ic_nls_sz;
    $_ic_navline_shadow = sprintf(
        '0 %1$dpx %1$dpx -%1$dpx rgba(%2$d,%3$d,%4$d,%5$s),inset 0 %1$dpx %1$dpx -%1$dpx rgba(%2$d,%3$d,%4$d,%5$s)',
        $_n, $_nr, $_ng, $_nb, $_na);
}

// ── Solo page backdrop — colour + opacity behind the print on the single-post
// view. 100% = solid; lower it to let the Organized Mayhem tabletop show
// through behind the photo. ─────────────────────────────────────────────────
$_ic_solo_hex = trim($settings['ic_solo_bg_color'] ?? '#000000');
$_ic_solo_op  = max(0, min(100, (int)($settings['ic_solo_bg_opacity'] ?? 100)));
$_sc = ltrim($_ic_solo_hex, '#');
if (strlen($_sc) === 3) $_sc = $_sc[0].$_sc[0].$_sc[1].$_sc[1].$_sc[2].$_sc[2];
$_ic_solo_bg = sprintf('rgba(%d,%d,%d,%s)',
    hexdec(substr($_sc, 0, 2)), hexdec(substr($_sc, 2, 2)), hexdec(substr($_sc, 4, 2)),
    number_format($_ic_solo_op / 100, 2));

// Organized Mayhem ambient background needs its shared data endpoint.
if ($_ic_bgmode === 'mayhem') {
    require_once dirname(__DIR__, 2) . '/core/mayhem-data.php';
}
?>

<!-- INSTANT CAMERA vars: tile aspect (match the print), scrim opacity, sharp corners. -->
<style id="ic-vars">:root{--ic-tile-aspect:<?php echo $_ic_aspect; ?>;--ic-scrim:<?php echo number_format($_ic_scrim, 2); ?>;--tile-radius:0px;--profile-text-glow:<?php echo htmlspecialchars($_ic_glow_css); ?>;--nav-text-glow:<?php echo htmlspecialchars($_ic_navglow_css); ?>;--nav-text-glow-strong:<?php echo htmlspecialchars($_ic_navglow_strong); ?>;--panel-bg:<?php echo htmlspecialchars($_ic_panel_bg); ?>;--panel-extend:<?php echo (int)$_ic_panel_extend; ?>px;--ic-nav-bg:<?php echo htmlspecialchars($_ic_nav_bg); ?>;--posts-color:<?php echo htmlspecialchars($_ic_posts_color); ?>;--posts-glow:<?php echo htmlspecialchars($_ic_posts_glow); ?>;--ic-navline-color:<?php echo htmlspecialchars($_ic_navline_color); ?>;--ic-navline-opacity:<?php echo (int)$_ic_navline_op; ?>;--ic-navline-shadow:<?php echo htmlspecialchars($_ic_navline_shadow); ?>;--post-bg:<?php echo htmlspecialchars($_ic_solo_bg); ?>;}</style>

<?php if ($_ic_bgmode === 'mayhem'): ?>
<!-- Background: Organized Mayhem ambient tabletop (data-pan=0 data-ambient=1) behind the scrim. -->
<div class="ic-bg ic-bg-mayhem" aria-hidden="true"
     data-mayhem
     data-api-url="<?php echo BASE_URL; ?>?ajax=mayhem"
     data-pan="0" data-ambient="1"
     data-initial-count="<?php echo (int)($settings['mayhem_initial_count'] ?? 90); ?>"
     data-max-width="<?php echo (int)($settings['mayhem_max_width'] ?? 260); ?>"
     data-loading-label="Developing"></div>
<?php elseif ($_ic_bgmode === 'static' && $_tg_treat_img !== ''): ?>
<div class="ic-bg ic-bg-static" aria-hidden="true"
     style="background-image:url('<?php echo BASE_URL . htmlspecialchars($_tg_treat_img); ?>');"></div>
<?php endif; ?>
<!-- White scrim between background and grid (primary legibility control). -->
<div class="ic-scrim" aria-hidden="true"></div>

<?php if ($_tg_has_treat): ?>
<div class="tg-treatment-bg" style="<?php echo $_tg_bg_style; ?>" aria-hidden="true"></div>
<?php if ($_tg_ov_style !== ''): ?>
<div class="tg-treatment-overlay" style="<?php echo $_tg_ov_style; ?>" aria-hidden="true"></div>
<?php endif; ?>
<?php endif; ?>

<?php if ($show_profile): ?>
<!-- ── Profile Header (shared across all Grid pages) ───────────────────────── -->
<section class="tg-profile">
    <div class="tg-profile-avatar<?php echo $avatar_exists ? ' tg-profile-avatar--zoom' : ''; ?>"
         <?php if ($avatar_exists): ?>role="button" tabindex="0"
         aria-label="View profile photo"
         data-tg-lightbox="<?php echo $avatar_url; ?>"<?php endif; ?>>
        <?php if ($avatar_exists): ?>
            <img src="<?php echo $avatar_url; ?>" alt="Profile avatar">
        <?php else: ?>
            <span class="tg-profile-avatar-initials"><?php echo htmlspecialchars($avatar_initials); ?></span>
        <?php endif; ?>
    </div>

    <div class="tg-profile-info">
        <div class="tg-profile-nameline">
            <h1 class="tg-profile-username"><?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?></h1>
            <?php if ($show_tagline && $tagline): ?>
            <span class="tg-profile-tagline-sep">/</span>
            <p class="tg-profile-tagline"><?php echo htmlspecialchars($tagline); ?></p>
            <?php endif; ?>
        </div>

        <div class="tg-profile-stats">
            <div class="tg-profile-stat">
                <span class="tg-profile-stat-num"><?php echo number_format($post_count); ?></span>
                <span class="tg-profile-stat-label">post<?php echo $post_count !== 1 ? 's' : ''; ?></span>
            </div>
        </div>

        <?php if ($bio): ?>
        <p class="tg-profile-bio"><?php echo nl2br(htmlspecialchars($bio)); ?></p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- ── Sticky Nav ──────────────────────────────────────────────────────────── -->
<nav class="tg-sticky-nav" aria-label="Site navigation">
    <div class="tg-sticky-nav-inner">
        <?php if ($avatar_exists): ?>
            <img class="tg-sticky-avatar" src="<?php echo $avatar_url; ?>"
                 alt="<?php echo htmlspecialchars($settings['site_name'] ?? ''); ?>" aria-hidden="true">
        <?php else: ?>
            <span class="tg-sticky-avatar-initials" aria-hidden="true"><?php echo htmlspecialchars($avatar_initials); ?></span>
        <?php endif; ?>

        <ul class="tg-sticky-nav-links">
            <li><a href="<?php echo BASE_URL; ?>" class="<?php echo $_tg_on_home ? 'active' : ''; ?>">Home</a></li>
            <?php if (($settings['blogroll_enabled'] ?? '1') == '1'): ?>
            <li><a href="<?php echo BASE_URL; ?>blogroll.php" class="<?php echo $_tg_on_blogroll ? 'active' : ''; ?>">Blogroll</a></li>
            <?php endif; ?>
            <?php foreach ($nav_pages as $nav_page): ?>
            <li><a href="<?php echo BASE_URL . 'page.php?slug=' . htmlspecialchars($nav_page['slug']); ?>"
                   class="<?php echo ($nav_page['slug'] === $_tg_active_slug) ? 'active' : ''; ?>"><?php echo htmlspecialchars($nav_page['title']); ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
</nav>
<?php // ===== SNAPSMACK EOF =====
