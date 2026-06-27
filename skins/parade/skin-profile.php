<?php
/**
 * SNAPSMACK - PARADE shared profile + sticky nav header
 *
 * Self-contained partial: renders the profile block (avatar, name/tagline,
 * post count, bio) and the sticky nav identically on the landing grid,
 * static pages and the blogroll. Requires $pdo and $settings in scope.
 *
 * Also emits the PARADE fireworks background carrier (.pa-parade-bg): the
 * fireworks engine (assets/js/ss-engine-parade-fireworks.js) reads the active
 * flag palette + motion params from this element's data-pa-* attributes and
 * appends its own <canvas>. The high-key background colour is a CSS var the
 * engine never paints over (trails erase via destination-out so the field
 * shows through). PARADE = THE GRID structure + this fireworks layer.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$show_profile = ($settings['pa_profile_header'] ?? '1') === '1';
$show_tagline = ($settings['pa_show_tagline']   ?? '1') === '1';

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
    $_pa_count = $pdo->prepare(
        "SELECT COUNT(*) FROM snap_posts WHERE status = 'published' AND created_at <= ?"
    );
    $_pa_count->execute([date('Y-m-d H:i:s')]);
    $post_count = (int)$_pa_count->fetchColumn();
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
$_pa_script      = basename($_SERVER['SCRIPT_NAME'] ?? '');
$_pa_active_slug = $_GET['slug'] ?? null;
$_pa_on_blogroll = ($_pa_script === 'blogroll.php');
$_pa_on_home     = ($_pa_script === 'index.php' && !isset($_GET['s']) && $_pa_active_slug === null);

// ── PARADE fireworks config (Layer 1 feed) ─────────────────────────────────
// Resolve the active flag palette + high-key background from the data-driven
// registry, and convert the integer admin sliders into the decimal params the
// engine expects. (Admin range widgets are INTEGER-ONLY — never store decimals;
// store an integer and divide here. Lesson carried from AURORA's stuck slider.)
$_pa_reg      = include __DIR__ . '/parade-config.php';
$_pa_palettes = $_pa_reg['palettes']    ?? [];
$_pa_bgs      = $_pa_reg['backgrounds'] ?? [];

$_pa_pal_key  = $settings['pa_palette'] ?? 'rainbow';
$_pa_colors   = $_pa_palettes[$_pa_pal_key]['colors']
                ?? ($_pa_palettes['rainbow']['colors']
                    ?? ['#e40303', '#ff8c00', '#ffed00', '#008026', '#004dff', '#750787']);
$_pa_colors   = array_values($_pa_colors);

// High-key background preset → CSS colour. 'wash' = a faint high-lightness tint
// of the active flag's first stop (NEVER a generic colour picker).
$_pa_bg_key = $settings['pa_background'] ?? 'warm';
$_pa_bg_css = $_pa_bgs[$_pa_bg_key]['css'] ?? '#ffffff';
if ($_pa_bg_key === 'wash' || $_pa_bg_css === '') {
    $_pa_first  = $_pa_colors[0] ?? '#e40303';
    $_pa_bg_css = 'color-mix(in srgb, ' . htmlspecialchars($_pa_first) . ' 8%, #ffffff)';
}

// Prototype-unit sliders → engine params. Scales AND defaults are test.html's dock,
// VERBATIM, with Sean's signed-off prototype values as the defaults (he signed off the
// prototype, not a re-mapping). Busyness /3 = launches/sec; Streamer /10; speeds /100; spread /1000.
$_pa_rate      = number_format(max(1,  min(40,  (int)($settings['pa_rate']      ?? 8)))   / 3,    2);  // 8 → 2.7/s
$_pa_launch    = number_format(max(5,  min(150, (int)($settings['pa_launch']    ?? 32)))  / 100,  2);  // 0.32×
$_pa_explode   = number_format(max(3,  min(150, (int)($settings['pa_explode']   ?? 21)))  / 100,  2);  // 0.21×
$_pa_intensity = max(20, min(300, (int)($settings['pa_intensity'] ?? 105)));                            // 105 particles
$_pa_spread    = number_format(max(10, min(120, (int)($settings['pa_spread']    ?? 45)))  / 1000, 3);  // 0.045
$_pa_streamer  = number_format(max(2,  min(40,  (int)($settings['pa_streamer']  ?? 4)))   / 10,   2);  // 0.4×
$_pa_soft      = number_format(max(0,  min(100, (int)($settings['pa_soft']      ?? 100))) / 100,  2);  // 100%

// ── Text colours (legibility over the bright field) — default DARK ──────────
$_pa_text   = trim($settings['pa_text_color']   ?? '#1a1a1a');
$_pa_muted  = trim($settings['pa_muted_color']  ?? '#5b5b66');
$_pa_accent = trim($settings['pa_accent_color'] ?? '#750787');

// ── Layer 2: tile border WAVE (the waving flag) ─────────────────────────────
// Drives the shared, prefix-derived ss-engine-aurora-wave.js on each .pa-ring.
$_pa_bstyle  = $settings['pa_border_style']  ?? 'circle';   // circle|sweep|across|pulse
$_pa_bdir    = $settings['pa_border_dir']    ?? 'dtlbr';
$_pa_brhythm = $settings['pa_border_rhythm'] ?? 'breath';   // breath|constant
$_pa_wave_cycle = max(40, min(400, (int)($settings['pa_wave_speed'] ?? 160)));   // Layer-2 border-wave clock; higher = slower (independent of fireworks rate)
$_pa_bw      = max(1,  min(10,  (int)($settings['pa_border_width']   ?? 5)));
$_pa_bo      = number_format(max(10, min(100, (int)($settings['pa_border_opacity'] ?? 100))) / 100, 2);
$_pa_corner  = $settings['pa_tile_corners'] ?? 'auto';      // auto|square|rounded
$_pa_radius  = ($_pa_corner === 'square') ? 0
             : (($_pa_corner === 'rounded') ? 16 : (int)round($_pa_bw * 2.2));
// Dark-stop floor so flag black/brown (Progress, Non-Binary) never paints a hard
// black border — lifts those stops toward grey. (Sean: black borders looked bad.)
$_pa_border_minl = '0.45';

// ── Nav divider — dual 1px lines ────────────────────────────────────────────
// 'track' → lines ride the live wave colour; 'fixed' → admin-picked colour.
$_pa_nav_mode = $settings['pa_nav_line_mode'] ?? 'track';
$_pa_nav_col  = ($_pa_nav_mode === 'fixed')
    ? htmlspecialchars(trim($settings['pa_nav_line_color'] ?? '#750787'))
    : 'var(--pa-wave-color, var(--pa-accent, #750787))';

// ── Background mode: fireworks (default) OR a full-viewport waving flag ──────
// Mutually exclusive (spec): only the chosen engine is loaded (skin-footer.php
// swaps the require_scripts handle). The flag rendered = the active pa_palette,
// resolved to stripe data from the central flag stock (core/manifest-inventory).
$_pa_flag_mode = (($settings['pa_bg_mode'] ?? 'fireworks') === 'flag');
$_pa_flag_stripes = null;
if ($_pa_flag_mode) {
    $_pa_inv       = include dirname(__DIR__, 2) . '/core/manifest-inventory.php';
    $_pa_flag_def  = $_pa_inv['flags'][$_pa_pal_key] ?? null;
    if ($_pa_flag_def) {
        $_pa_flag_orient  = $_pa_flag_def['o'] ?? 'h';
        $_pa_flag_stripes = $_pa_flag_def['stripes'];
    } else {
        // Palette has no stripe def (e.g. progress chevron / two-spirit) — degrade
        // to equal stripes from the fireworks palette colours.
        $_pa_flag_orient  = 'h';
        $_pa_flag_stripes = array_map(function ($c) { return [$c, 1]; }, $_pa_colors);
    }
    $_pa_flag_speed   = max(1, min(100, (int) ($settings['pa_flag_speed']     ?? 30)));
    $_pa_flag_amp     = max(1, min(100, (int) ($settings['pa_flag_amplitude'] ?? 40)));
    $_pa_flag_opacity = max(0, min(100, (int) ($settings['pa_flag_opacity']   ?? 100)));
}

// ── Glow stack builder ───────────────────────────────────────────────────────
//    A 2-shadow halo (the old approach) read as a wimpy haze even at max size,
//    because text-shadow blur disperses fast. This stacks four graduated layers
//    so a tight bright core builds into a soft outer halo — the glow actually
//    reads at 40px. Shared by the nav glow and the profile (title/tagline/bio)
//    glow so both behave identically.
$_pa_glow_stack = function (int $r, int $g, int $b, int $sz, int $op): string {
    if ($sz <= 0 || $op <= 0) return 'none';
    $a = $op / 100;
    // [blur multiple of size, alpha multiple of base]
    $layers = [[0.40, 1.00], [0.80, 0.85], [1.30, 0.65], [2.00, 0.45]];
    $parts  = [];
    foreach ($layers as $l) {
        $blur    = max(1, (int) round($sz * $l[0]));
        $alpha   = number_format(min(1, $a * $l[1]), 2);
        $parts[] = sprintf('0 0 %dpx rgba(%d,%d,%d,%s)', $blur, $r, $g, $b, $alpha);
    }
    return implode(',', $parts);
};

// ── Nav menu text glow (ported from AURORA — was never wired in PARADE, so the
//    nav fell back to style.css's hardcoded GREEN and the admin colour did
//    nothing). Emits --nav-text-glow / --nav-text-glow-strong. ────────────────
$_pa_navglow_hex = trim($settings['pa_nav_glow_color'] ?? '#750787');
$_pa_navglow_sz  = max(0, min(40,  (int)($settings['pa_nav_glow_size']    ?? 0)));
$_pa_navglow_op  = max(0, min(100, (int)($settings['pa_nav_glow_opacity'] ?? 45)));
$_pa_navglow_css = 'none'; $_pa_navglow_strong = 'none';
if ($_pa_navglow_sz > 0 && $_pa_navglow_op > 0) {
    $_ngc = ltrim($_pa_navglow_hex, '#');
    if (strlen($_ngc) === 3) $_ngc = $_ngc[0].$_ngc[0].$_ngc[1].$_ngc[1].$_ngc[2].$_ngc[2];
    $_ngr = hexdec(substr($_ngc, 0, 2)); $_ngg = hexdec(substr($_ngc, 2, 2)); $_ngb = hexdec(substr($_ngc, 4, 2));
    $_pa_navglow_css    = $_pa_glow_stack($_ngr, $_ngg, $_ngb, $_pa_navglow_sz, $_pa_navglow_op);
    $_pa_navglow_strong = $_pa_glow_stack($_ngr, $_ngg, $_ngb, $_pa_navglow_sz + 4, (int) min(100, $_pa_navglow_op * 1.5));
}

// ── Profile text glow (ported from AURORA — also never wired, so the title/
//    tagline/bio glow controls did nothing). Emits --profile-text-glow. ───────
$_pa_glow_hex = trim($settings['pa_glow_color'] ?? '#ffffff');
$_pa_glow_sz  = max(0, min(40,  (int)($settings['pa_glow_size']    ?? 0)));
$_pa_glow_op  = max(0, min(100, (int)($settings['pa_glow_opacity'] ?? 0)));
$_pa_glow_css = 'none';
if ($_pa_glow_sz > 0 && $_pa_glow_op > 0) {
    $_gc = ltrim($_pa_glow_hex, '#');
    if (strlen($_gc) === 3) $_gc = $_gc[0].$_gc[0].$_gc[1].$_gc[1].$_gc[2].$_gc[2];
    $_gr = hexdec(substr($_gc, 0, 2)); $_gg = hexdec(substr($_gc, 2, 2)); $_gb = hexdec(substr($_gc, 4, 2));
    $_pa_glow_css = $_pa_glow_stack($_gr, $_gg, $_gb, $_pa_glow_sz, $_pa_glow_op);
}

// ── Footer text glow (dedicated control). Emits --footer-text-glow. Off (none)
//    until both size and opacity are set, same opt-in behaviour as profile glow.
$_pa_ftglow_hex = trim($settings['pa_footer_glow_color'] ?? '#750787');
$_pa_ftglow_sz  = max(0, min(40,  (int)($settings['pa_footer_glow_size']    ?? 0)));
$_pa_ftglow_op  = max(0, min(100, (int)($settings['pa_footer_glow_opacity'] ?? 0)));
$_pa_ftglow_css = 'none';
if ($_pa_ftglow_sz > 0 && $_pa_ftglow_op > 0) {
    $_fgc = ltrim($_pa_ftglow_hex, '#');
    if (strlen($_fgc) === 3) $_fgc = $_fgc[0].$_fgc[0].$_fgc[1].$_fgc[1].$_fgc[2].$_fgc[2];
    $_fgr = hexdec(substr($_fgc, 0, 2)); $_fgg = hexdec(substr($_fgc, 2, 2)); $_fgb = hexdec(substr($_fgc, 4, 2));
    $_pa_ftglow_css = $_pa_glow_stack($_fgr, $_fgg, $_fgb, $_pa_ftglow_sz, $_pa_ftglow_op);
}

// Nav companion-line opacity (0–100 → 0–1) — also previously unemitted.
$_pa_nav_line_op = number_format(max(0, min(100, (int)($settings['pa_nav_line_opacity'] ?? 100))) / 100, 2);
?>

<!-- PARADE CSS vars: high-key field + text colours (read by style.css) -->
<style id="pa-vars">:root{--pa-bg:<?php echo $_pa_bg_css; ?>;--pa-text:<?php echo htmlspecialchars($_pa_text); ?>;--pa-muted:<?php echo htmlspecialchars($_pa_muted); ?>;--pa-accent:<?php echo htmlspecialchars($_pa_accent); ?>;--tile-bw:<?php echo $_pa_bw; ?>px;--tile-radius:<?php echo $_pa_radius; ?>px;--ring-op:<?php echo $_pa_bo; ?>;--pa-nav-line:<?php echo $_pa_nav_col; ?>;--nav-line-opacity:<?php echo $_pa_nav_line_op; ?>;--nav-text-glow:<?php echo $_pa_navglow_css; ?>;--nav-text-glow-strong:<?php echo $_pa_navglow_strong; ?>;--profile-text-glow:<?php echo $_pa_glow_css; ?>;--footer-text-glow:<?php echo $_pa_ftglow_css; ?>;}</style>

<?php if ($_pa_flag_mode): ?>
<!-- PARADE waving-flag carrier — read by ss-engine-flag-wave.js (Layer 1
     ALTERNATIVE to fireworks; mutually exclusive). Reuses .pa-parade-bg for the
     fixed full-viewport positioning; the engine appends its own <canvas>. -->
<div class="pa-parade-bg pa-flag-bg" aria-hidden="true"
     data-flag-wave
     data-stripes='<?php echo htmlspecialchars(json_encode($_pa_flag_stripes), ENT_QUOTES); ?>'
     data-orientation="<?php echo $_pa_flag_orient; ?>"
     data-speed="<?php echo $_pa_flag_speed; ?>"
     data-amplitude="<?php echo $_pa_flag_amp; ?>"
     data-opacity="<?php echo $_pa_flag_opacity; ?>"
     data-pa-palette='<?php echo htmlspecialchars(json_encode($_pa_colors), ENT_QUOTES); ?>'
     data-pa-border-style="<?php echo htmlspecialchars($_pa_bstyle); ?>"
     data-pa-border-dir="<?php echo htmlspecialchars($_pa_bdir); ?>"
     data-pa-border-rhythm="<?php echo htmlspecialchars($_pa_brhythm); ?>"
     data-pa-border-cycle="<?php echo $_pa_wave_cycle; ?>"
     data-pa-border-minl="<?php echo $_pa_border_minl; ?>"></div>
<?php else: ?>
<!-- PARADE fireworks carrier — read by ss-engine-parade-fireworks.js (Layer 1).
     The engine appends its own <canvas class="pa-canvas">. -->
<div class="pa-parade-bg" aria-hidden="true"
     data-pa-palette='<?php echo htmlspecialchars(json_encode($_pa_colors), ENT_QUOTES); ?>'
     data-pa-rate="<?php echo $_pa_rate; ?>"
     data-pa-launch="<?php echo $_pa_launch; ?>"
     data-pa-explode="<?php echo $_pa_explode; ?>"
     data-pa-intensity="<?php echo $_pa_intensity; ?>"
     data-pa-spread="<?php echo $_pa_spread; ?>"
     data-pa-streamer="<?php echo $_pa_streamer; ?>"
     data-pa-soft="<?php echo $_pa_soft; ?>"
     data-pa-border-style="<?php echo htmlspecialchars($_pa_bstyle); ?>"
     data-pa-border-dir="<?php echo htmlspecialchars($_pa_bdir); ?>"
     data-pa-border-rhythm="<?php echo htmlspecialchars($_pa_brhythm); ?>"
     data-pa-border-cycle="<?php echo $_pa_wave_cycle; ?>"
     data-pa-border-minl="<?php echo $_pa_border_minl; ?>"></div>
<?php endif; ?>

<?php if ($show_profile): ?>
<!-- ── Profile Header (shared across all Grid pages) ───────────────────────── -->
<section class="pa-profile">
    <div class="pa-profile-avatar<?php echo $avatar_exists ? ' pa-profile-avatar--zoom' : ''; ?>"
         <?php if ($avatar_exists): ?>role="button" tabindex="0"
         aria-label="View profile photo"
         data-pa-lightbox="<?php echo $avatar_url; ?>"<?php endif; ?>>
        <?php if ($avatar_exists): ?>
            <img src="<?php echo $avatar_url; ?>" alt="Profile avatar">
        <?php else: ?>
            <span class="pa-profile-avatar-initials"><?php echo htmlspecialchars($avatar_initials); ?></span>
        <?php endif; ?>
    </div>

    <div class="pa-profile-info">
        <div class="pa-profile-nameline">
            <h1 class="pa-profile-username"><?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?></h1>
            <?php if ($show_tagline && $tagline): ?>
            <span class="pa-profile-tagline-sep">/</span>
            <p class="pa-profile-tagline"><?php echo htmlspecialchars($tagline); ?></p>
            <?php endif; ?>
        </div>

        <div class="pa-profile-stats">
            <div class="pa-profile-stat">
                <span class="pa-profile-stat-num"><?php echo number_format($post_count); ?></span>
                <span class="pa-profile-stat-label">post<?php echo $post_count !== 1 ? 's' : ''; ?></span>
            </div>
        </div>

        <?php if ($bio): ?>
        <p class="pa-profile-bio"><?php echo nl2br(htmlspecialchars($bio)); ?></p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- ── Sticky Nav ──────────────────────────────────────────────────────────── -->
<nav class="pa-sticky-nav" aria-label="Site navigation">
    <div class="pa-sticky-nav-inner">
        <?php if ($avatar_exists): ?>
            <img class="pa-sticky-avatar" src="<?php echo $avatar_url; ?>"
                 alt="<?php echo htmlspecialchars($settings['site_name'] ?? ''); ?>" aria-hidden="true">
        <?php else: ?>
            <span class="pa-sticky-avatar-initials" aria-hidden="true"><?php echo htmlspecialchars($avatar_initials); ?></span>
        <?php endif; ?>

        <ul class="pa-sticky-nav-links">
            <li><a href="<?php echo BASE_URL; ?>" class="<?php echo $_pa_on_home ? 'active' : ''; ?>">Home</a></li>
            <?php if (($settings['blogroll_enabled'] ?? '1') == '1'): ?>
            <li><a href="<?php echo BASE_URL; ?>blogroll.php" class="<?php echo $_pa_on_blogroll ? 'active' : ''; ?>">Blogroll</a></li>
            <?php endif; ?>
            <?php foreach ($nav_pages as $nav_page): ?>
            <li><a href="<?php echo BASE_URL . 'page.php?slug=' . htmlspecialchars($nav_page['slug']); ?>"
                   class="<?php echo ($nav_page['slug'] === $_pa_active_slug) ? 'active' : ''; ?>"><?php echo htmlspecialchars($nav_page['title']); ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
</nav>
<?php // ===== SNAPSMACK EOF =====
