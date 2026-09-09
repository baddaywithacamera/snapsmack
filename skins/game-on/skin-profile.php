<?php
/**
 * SNAPSMACK - Game On shared profile + sticky nav header
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


$show_profile = ($settings['go_profile_header'] ?? '1') === '1';
$show_tagline = ($settings['go_show_tagline']   ?? '1') === '1';

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
    $_go_count = $pdo->prepare(
        "SELECT COUNT(*) FROM snap_posts WHERE status = 'published' AND created_at <= ?"
    );
    $_go_count->execute([date('Y-m-d H:i:s')]);
    $post_count = (int)$_go_count->fetchColumn();
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
$_go_script      = basename($_SERVER['SCRIPT_NAME'] ?? '');
$_go_active_slug = $_GET['slug'] ?? null;
$_go_on_blogroll = ($_go_script === 'blogroll.php');
$_go_on_home     = ($_go_script === 'index.php' && !isset($_GET['s']) && $_go_active_slug === null);

// ── Background treatment (skin admin → Treatment) ──────────────────────────
// Emitted on every Grid page. The full-screen layers sit behind the centred
// content card; CSS (:has(.go-treatment-bg)) turns the card on only when present.
$_go_treat_mode  = $settings['go_treatment_mode']     ?? 'none';
$_go_treat_img   = trim($settings['go_treatment_image'] ?? '');
$_go_treat_color = trim($settings['go_treatment_color'] ?? '');
$_go_treat_pos   = $settings['go_treatment_position'] ?? 'center';
$_go_treat_ov    = (int)($settings['go_treatment_overlay'] ?? 0); // -100 dark .. +100 light
$_go_has_treat   = ($_go_treat_mode === 'image' && $_go_treat_img !== '')
                || ($_go_treat_mode === 'color' && $_go_treat_color !== '');

$_go_bg_style = '';
$_go_ov_style = '';
if ($_go_has_treat) {
    if ($_go_treat_mode === 'image' && $_go_treat_img !== '') {
        $_go_treat_pos_css = ($_go_treat_pos === 'top')    ? 'center top'
                           : (($_go_treat_pos === 'bottom') ? 'center bottom' : 'center center');
        $_go_bg_style = "background-image:url('" . BASE_URL . htmlspecialchars($_go_treat_img) . "');"
                      . 'background-position:' . $_go_treat_pos_css . ';';
    } elseif ($_go_treat_mode === 'color' && $_go_treat_color !== '') {
        $_go_bg_style = 'background-color:' . htmlspecialchars($_go_treat_color) . ';';
    }
    if ($_go_treat_ov < 0) {
        $_go_ov_style = 'background-color:rgba(0,0,0,' . round(min(100, -$_go_treat_ov) / 100, 2) . ');';
    } elseif ($_go_treat_ov > 0) {
        $_go_ov_style = 'background-color:rgba(255,255,255,' . round(min(100, $_go_treat_ov) / 100, 2) . ');';
    }
}
?>

<?php if ($_go_has_treat): ?>
<div class="go-treatment-bg" style="<?php echo $_go_bg_style; ?>" aria-hidden="true"></div>
<?php if ($_go_ov_style !== ''): ?>
<div class="go-treatment-overlay" style="<?php echo $_go_ov_style; ?>" aria-hidden="true"></div>
<?php endif; ?>
<?php endif; ?>

<?php if ($show_profile): ?>
<!-- ── Profile Header (shared across all Grid pages) ───────────────────────── -->
<section class="go-profile">
    <div class="go-profile-avatar<?php echo $avatar_exists ? ' go-profile-avatar--zoom' : ''; ?>"
         <?php if ($avatar_exists): ?>role="button" tabindex="0"
         aria-label="View profile photo"
         data-go-lightbox="<?php echo $avatar_url; ?>"<?php endif; ?>>
        <?php if ($avatar_exists): ?>
            <img src="<?php echo $avatar_url; ?>" alt="Profile avatar">
        <?php else: ?>
            <span class="go-profile-avatar-initials"><?php echo htmlspecialchars($avatar_initials); ?></span>
        <?php endif; ?>
    </div>

    <div class="go-profile-info">
        <div class="go-profile-nameline">
            <h1 class="go-profile-username"><?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?></h1>
            <?php if ($show_tagline && $tagline): ?>
            <span class="go-profile-tagline-sep">/</span>
            <p class="go-profile-tagline"><?php echo htmlspecialchars($tagline); ?></p>
            <?php endif; ?>
        </div>

        <div class="go-profile-stats">
            <div class="go-profile-stat">
                <span class="go-profile-stat-num"><?php echo number_format($post_count); ?></span>
                <span class="go-profile-stat-label">post<?php echo $post_count !== 1 ? 's' : ''; ?></span>
            </div>
        </div>

        <?php if ($bio): ?>
        <p class="go-profile-bio"><?php echo nl2br(htmlspecialchars($bio)); ?></p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- ── Sticky Nav ──────────────────────────────────────────────────────────── -->
<nav class="go-sticky-nav" aria-label="Site navigation">
    <div class="go-sticky-nav-inner">
        <?php if ($avatar_exists): ?>
            <img class="go-sticky-avatar" src="<?php echo $avatar_url; ?>"
                 alt="<?php echo htmlspecialchars($settings['site_name'] ?? ''); ?>" aria-hidden="true">
        <?php else: ?>
            <span class="go-sticky-avatar-initials" aria-hidden="true"><?php echo htmlspecialchars($avatar_initials); ?></span>
        <?php endif; ?>

        <ul class="go-sticky-nav-links">
            <?php
            // Nav content is driven by the Menu Manager (nav_menu_json) via the
            // shared partial — one source of truth for every GRAMOFSMACK skin's
            // nav. Falls back to Home + Blogroll + pages when no menu is saved.
            // Active-state is derived inside the partial, so the skin namespace
            // ($_go_/$_au_/$_pa_) doesn't matter.
            include dirname(__DIR__, 2) . '/core/gram-nav-links.php';
            ?>
        </ul>
    </div>
</nav>
<?php // ===== SNAPSMACK EOF =====
