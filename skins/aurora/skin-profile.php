<?php
/**
 * SNAPSMACK - AURORA shared profile + sticky nav header
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


$show_profile = ($settings['au_profile_header'] ?? '1') === '1';
$show_tagline = ($settings['au_show_tagline']   ?? '1') === '1';

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
    $_au_count = $pdo->prepare(
        "SELECT COUNT(*) FROM snap_posts WHERE status = 'published' AND created_at <= ?"
    );
    $_au_count->execute([date('Y-m-d H:i:s')]);
    $post_count = (int)$_au_count->fetchColumn();
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
$_au_script      = basename($_SERVER['SCRIPT_NAME'] ?? '');
$_au_active_slug = $_GET['slug'] ?? null;
$_au_on_blogroll = ($_au_script === 'blogroll.php');
$_au_on_home     = ($_au_script === 'index.php' && !isset($_GET['s']) && $_au_active_slug === null);

// ── Background treatment (skin admin → Treatment) ──────────────────────────
// Emitted on every Grid page. The full-screen layers sit behind the centred
// content card; CSS (:has(.au-treatment-bg)) turns the card on only when present.
$_au_treat_mode  = $settings['au_treatment_mode']     ?? 'none';
$_au_treat_img   = trim($settings['au_treatment_image'] ?? '');
$_au_treat_color = trim($settings['au_treatment_color'] ?? '');
$_au_treat_pos   = $settings['au_treatment_position'] ?? 'center';
$_au_treat_ov    = (int)($settings['au_treatment_overlay'] ?? 0); // -100 dark .. +100 light
$_au_has_treat   = ($_au_treat_mode === 'image' && $_au_treat_img !== '')
                || ($_au_treat_mode === 'color' && $_au_treat_color !== '');

$_au_bg_style = '';
$_au_ov_style = '';
if ($_au_has_treat) {
    if ($_au_treat_mode === 'image' && $_au_treat_img !== '') {
        $_au_treat_pos_css = ($_au_treat_pos === 'top')    ? 'center top'
                           : (($_au_treat_pos === 'bottom') ? 'center bottom' : 'center center');
        $_au_bg_style = "background-image:url('" . BASE_URL . htmlspecialchars($_au_treat_img) . "');"
                      . 'background-position:' . $_au_treat_pos_css . ';';
    } elseif ($_au_treat_mode === 'color' && $_au_treat_color !== '') {
        $_au_bg_style = 'background-color:' . htmlspecialchars($_au_treat_color) . ';';
    }
    if ($_au_treat_ov < 0) {
        $_au_ov_style = 'background-color:rgba(0,0,0,' . round(min(100, -$_au_treat_ov) / 100, 2) . ');';
    } elseif ($_au_treat_ov > 0) {
        $_au_ov_style = 'background-color:rgba(255,255,255,' . round(min(100, $_au_treat_ov) / 100, 2) . ');';
    }
}

// ── AURORA atmosphere config (Layer 1 + Layer 2 feed) ──────────────────────
// Resolves the active palette + sky from the data-driven registry and emits the
// fixed full-viewport aurora background. The same element carries the wave
// config (palette / direction / speed / intensity) that aurora-wave.js reads.
// Emitted once per page here (skin-profile renders on every AURORA page); the
// modal fragment path never includes skin-profile, so it is never duplicated.
$_au_reg       = include __DIR__ . '/aurora-config.php';
$_au_palettes  = $_au_reg['palettes'] ?? [];
$_au_pal_key   = $settings['au_palette'] ?? 'aurora';
$_au_colors    = $_au_palettes[$_au_pal_key]['colors']
                 ?? ($_au_palettes['aurora']['colors']
                     ?? ['#61e96e', '#00cec9', '#4899f0', '#a55eea', '#e056d7', '#61e96e']);
$_au_colors    = array_values($_au_colors);

$_au_sky     = $settings['au_sky'] ?? '#000000';
$_au_opacity = number_format(max(5, min(100, (int)($settings['au_l1_opacity'] ?? 50))) / 100, 2); // canvas alpha
$_au_cycle   = max(15, min(240, (int)($settings['au_cycle_time'] ?? 240)));      // seconds per palette pass
$_au_bstyle  = $settings['au_border_style']   ?? 'circle';                        // circle|sweep|across|pulse
$_au_bdir    = $settings['au_wave_direction'] ?? 'dtlbr';
$_au_brhythm = $settings['au_wave_rhythm']    ?? 'breath';                         // breath|constant
$_au_wave_cycle = max(40, min(400, (int)($settings['au_wave_speed'] ?? 160)));    // Layer-2 border-wave clock; higher = slower (independent of sky cycle)
$_au_bw      = max(1, min(10, (int)($settings['au_border_width'] ?? 2)));
$_au_bo      = number_format(max(10, min(100, (int)($settings['au_border_opacity'] ?? 100))) / 100, 2);
$_au_corner  = $settings['au_tile_corners'] ?? 'auto';                             // auto|square|rounded
$_au_radius  = ($_au_corner === 'square') ? 0
             : (($_au_corner === 'rounded') ? 16 : (int)round($_au_bw * 2.2));     // 'auto' grows with width

// ── Nav line colour mode ───────────────────────────────────────────────────
// 'aurora' → lines track the live wave colour; 'static' → use --border-color.
$_au_nav_line_mode = $settings['au_nav_line_mode'] ?? 'static';
$_au_nav_line_css  = ($_au_nav_line_mode === 'aurora')
    ? '--nav-line-color:var(--au-wave-color,var(--border-color));'
    : '';
// Opacity of the dark companion divider lines (0–100% → 0–1).
$_au_nav_line_op   = number_format(max(0, min(100, (int)($settings['au_nav_line_opacity'] ?? 100))) / 100, 2);

// ── Nav menu text glow ─────────────────────────────────────────────────────
// Outer glow on the nav links (home / blogroll / pages). Defaults to aurora
// green so the menu stays legible over the animated background; admin-tunable.
$_au_navglow_hex    = trim($settings['au_nav_glow_color'] ?? '#61e96e');
$_au_navglow_sz     = max(0, min(40,  (int)($settings['au_nav_glow_size']    ?? 8)));
$_au_navglow_op     = max(0, min(100, (int)($settings['au_nav_glow_opacity'] ?? 45)));
$_au_navglow_css    = 'none';
$_au_navglow_strong = 'none';
if ($_au_navglow_sz > 0 && $_au_navglow_op > 0) {
    $_ngc = ltrim($_au_navglow_hex, '#');
    if (strlen($_ngc) === 3) $_ngc = $_ngc[0].$_ngc[0].$_ngc[1].$_ngc[1].$_ngc[2].$_ngc[2];
    $_ngr = hexdec(substr($_ngc, 0, 2));
    $_ngg = hexdec(substr($_ngc, 2, 2));
    $_ngb = hexdec(substr($_ngc, 4, 2));
    $_nga = number_format($_au_navglow_op / 100, 2);
    $_au_navglow_css = sprintf(
        '0 0 %dpx rgba(%d,%d,%d,%s),0 0 %dpx rgba(%d,%d,%d,%s)',
        $_au_navglow_sz, $_ngr, $_ngg, $_ngb, $_nga,
        $_au_navglow_sz * 2, $_ngr, $_ngg, $_ngb, number_format($_au_navglow_op / 200, 2)
    );
    $_au_navglow_strong = sprintf(
        '0 0 %dpx rgba(%d,%d,%d,%s),0 0 %dpx rgba(%d,%d,%d,%s)',
        $_au_navglow_sz + 2, $_ngr, $_ngg, $_ngb, number_format(min(1, $_au_navglow_op / 100 * 1.5), 2),
        ($_au_navglow_sz + 2) * 2, $_ngr, $_ngg, $_ngb, $_nga
    );
}

// ── Profile text glow ─────────────────────────────────────────────────────
// Composited text-shadow for readability over the shifting aurora background.
$_au_glow_hex  = trim($settings['au_glow_color'] ?? '#000000');
$_au_glow_sz   = max(0, min(40, (int)($settings['au_glow_size']    ?? 0)));
$_au_glow_op   = max(0, min(100, (int)($settings['au_glow_opacity'] ?? 0)));
// Default: a soft DARK halo so the title/tagline stay legible over the bright,
// shifting aurora curtains (light text over a bright curtain needs dark
// separation, not a light glow). The admin "Text Glow" settings override this
// when configured — so this is just the out-of-the-box readability floor.
$_au_glow_css  = '0 0 2px rgba(0,0,0,0.85),0 0 8px rgba(0,0,0,0.55),0 0 16px rgba(0,0,0,0.40)';
if ($_au_glow_sz > 0 && $_au_glow_op > 0) {
    // Parse hex → RGB for rgba() composition.
    $_gc = ltrim($_au_glow_hex, '#');
    if (strlen($_gc) === 3) $_gc = $_gc[0].$_gc[0].$_gc[1].$_gc[1].$_gc[2].$_gc[2];
    $_gr = hexdec(substr($_gc, 0, 2));
    $_gg = hexdec(substr($_gc, 2, 2));
    $_gb = hexdec(substr($_gc, 4, 2));
    $_ga = number_format($_au_glow_op / 100, 2);
    // Two passes at the configured size, second at 2× for spread.
    $_au_glow_css = sprintf(
        '0 0 %dpx rgba(%d,%d,%d,%s),0 0 %dpx rgba(%d,%d,%d,%s)',
        $_au_glow_sz, $_gr, $_gg, $_gb, $_ga,
        $_au_glow_sz * 2, $_gr, $_gg, $_gb, number_format($_au_glow_op / 200, 2)
    );
}
?>

<!-- AURORA tile vars: border width / corner radius / ring opacity / sky base -->
<style id="au-vars">:root{--tile-bw:<?php echo $_au_bw; ?>px;--tile-radius:<?php echo $_au_radius; ?>px;--ring-op:<?php echo $_au_bo; ?>;--au-sky:<?php echo htmlspecialchars($_au_sky); ?>;--profile-text-glow:<?php echo htmlspecialchars($_au_glow_css); ?>;--nav-line-opacity:<?php echo $_au_nav_line_op; ?>;--nav-text-glow:<?php echo htmlspecialchars($_au_navglow_css); ?>;--nav-text-glow-strong:<?php echo htmlspecialchars($_au_navglow_strong); ?>;<?php echo $_au_nav_line_css; ?>}</style>

<!-- AURORA config carrier — read by aurora-bg.js (Layer 1 curtains) and
     aurora-wave.js (Layer 2 ring wave). -->
<div class="au-aurora-bg" aria-hidden="true"
     data-au-palette='<?php echo htmlspecialchars(json_encode($_au_colors), ENT_QUOTES); ?>'
     data-au-cycle="<?php echo $_au_cycle; ?>"
     data-au-opacity="<?php echo $_au_opacity; ?>"
     data-au-sky="<?php echo htmlspecialchars($_au_sky); ?>"
     data-au-border-style="<?php echo htmlspecialchars($_au_bstyle); ?>"
     data-au-border-dir="<?php echo htmlspecialchars($_au_bdir); ?>"
     data-au-border-rhythm="<?php echo htmlspecialchars($_au_brhythm); ?>"
     data-au-border-cycle="<?php echo $_au_wave_cycle; ?>"></div>

<?php if ($_au_has_treat): ?>
<div class="au-treatment-bg" style="<?php echo $_au_bg_style; ?>" aria-hidden="true"></div>
<?php if ($_au_ov_style !== ''): ?>
<div class="au-treatment-overlay" style="<?php echo $_au_ov_style; ?>" aria-hidden="true"></div>
<?php endif; ?>
<?php endif; ?>

<?php if ($show_profile): ?>
<!-- ── Profile Header (shared across all Grid pages) ───────────────────────── -->
<section class="au-profile">
    <div class="au-profile-avatar<?php echo $avatar_exists ? ' au-profile-avatar--zoom' : ''; ?>"
         <?php if ($avatar_exists): ?>role="button" tabindex="0"
         aria-label="View profile photo"
         data-au-lightbox="<?php echo $avatar_url; ?>"<?php endif; ?>>
        <?php if ($avatar_exists): ?>
            <img src="<?php echo $avatar_url; ?>" alt="Profile avatar">
        <?php else: ?>
            <span class="au-profile-avatar-initials"><?php echo htmlspecialchars($avatar_initials); ?></span>
        <?php endif; ?>
    </div>

    <div class="au-profile-info">
        <div class="au-profile-nameline">
            <h1 class="au-profile-username"><?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?></h1>
            <?php if ($show_tagline && $tagline): ?>
            <span class="au-profile-tagline-sep">/</span>
            <p class="au-profile-tagline"><?php echo htmlspecialchars($tagline); ?></p>
            <?php endif; ?>
        </div>

        <div class="au-profile-stats">
            <div class="au-profile-stat">
                <span class="au-profile-stat-num"><?php echo number_format($post_count); ?></span>
                <span class="au-profile-stat-label">post<?php echo $post_count !== 1 ? 's' : ''; ?></span>
            </div>
        </div>

        <?php if ($bio): ?>
        <p class="au-profile-bio"><?php echo nl2br(htmlspecialchars($bio)); ?></p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- ── Sticky Nav ──────────────────────────────────────────────────────────── -->
<nav class="au-sticky-nav" aria-label="Site navigation">
    <div class="au-sticky-nav-inner">
        <?php if ($avatar_exists): ?>
            <img class="au-sticky-avatar" src="<?php echo $avatar_url; ?>"
                 alt="<?php echo htmlspecialchars($settings['site_name'] ?? ''); ?>" aria-hidden="true">
        <?php else: ?>
            <span class="au-sticky-avatar-initials" aria-hidden="true"><?php echo htmlspecialchars($avatar_initials); ?></span>
        <?php endif; ?>

        <ul class="au-sticky-nav-links">
            <li><a href="<?php echo BASE_URL; ?>" class="<?php echo $_au_on_home ? 'active' : ''; ?>">Home</a></li>
            <?php if (($settings['blogroll_enabled'] ?? '1') == '1'): ?>
            <li><a href="<?php echo BASE_URL; ?>blogroll.php" class="<?php echo $_au_on_blogroll ? 'active' : ''; ?>">Blogroll</a></li>
            <?php endif; ?>
            <?php foreach ($nav_pages as $nav_page): ?>
            <li><a href="<?php echo BASE_URL . 'page.php?slug=' . htmlspecialchars($nav_page['slug']); ?>"
                   class="<?php echo ($nav_page['slug'] === $_au_active_slug) ? 'active' : ''; ?>"><?php echo htmlspecialchars($nav_page['title']); ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
</nav>
<?php // ===== SNAPSMACK EOF =====