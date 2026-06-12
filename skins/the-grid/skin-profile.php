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


$show_profile = ($settings['tg_profile_header'] ?? '1') === '1';
$show_tagline = ($settings['tg_show_tagline']   ?? '1') === '1';

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
$avatar_path     = $settings['tg_avatar'] ?? '';
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
$_tg_treat_mode  = $settings['tg_treatment_mode']     ?? 'none';
$_tg_treat_img   = trim($settings['tg_treatment_image'] ?? '');
$_tg_treat_color = trim($settings['tg_treatment_color'] ?? '');
$_tg_treat_ov    = (int)($settings['tg_treatment_overlay'] ?? 0); // -100 dark .. +100 light
$_tg_has_treat   = ($_tg_treat_mode === 'image' && $_tg_treat_img !== '')
                || ($_tg_treat_mode === 'color' && $_tg_treat_color !== '');

$_tg_bg_style = '';
$_tg_ov_style = '';
if ($_tg_has_treat) {
    if ($_tg_treat_mode === 'image' && $_tg_treat_img !== '') {
        $_tg_bg_style = "background-image:url('" . BASE_URL . htmlspecialchars($_tg_treat_img) . "');";
    } elseif ($_tg_treat_mode === 'color' && $_tg_treat_color !== '') {
        $_tg_bg_style = 'background-color:' . htmlspecialchars($_tg_treat_color) . ';';
    }
    if ($_tg_treat_ov < 0) {
        $_tg_ov_style = 'background-color:rgba(0,0,0,' . round(min(100, -$_tg_treat_ov) / 100, 2) . ');';
    } elseif ($_tg_treat_ov > 0) {
        $_tg_ov_style = 'background-color:rgba(255,255,255,' . round(min(100, $_tg_treat_ov) / 100, 2) . ');';
    }
}
?>

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
