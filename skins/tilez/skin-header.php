<?php
if (!defined('SNAPSMACK_SKIN_RENDER')) return;
/**
 * SNAPSMACK - Skin header for the Alfred skin
 * v1.0.0
 *
 * Emits:
 *   1. nav.navigation — dark bar with ul.main-menu (desktop) + hamburger + mobile drawer
 *   2. div.header-image — full-width background image (user-replaceable via skin option)
 *   3. header.header.section-inner — custom logo or blog title text
 *
 * Respects skin options: header_image, header_logo, retina_logo.
 * Nav supports nav_menu_json (structured) or falls back to default flat nav.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// --- Skin options ---
$alfred_header_image = trim($settings['header_image'] ?? '');
// TILEZ ships with the site's commissioned masthead. Do not inherit a logo
// saved by the previously active skin.
$alfred_header_logo  = 'skins/tilez/assets/bad-day-masthead.png';
$alfred_retina_logo  = ($settings['retina_logo'] ?? '0') === '1';

// Build header-image inline style
$header_image_style = '';
if ($alfred_header_image !== '') {
    $img_url = (preg_match('#^https?://#', $alfred_header_image))
        ? $alfred_header_image
        : BASE_URL . ltrim($alfred_header_image, '/');
    $header_image_style = ' style="background-image: url(\'' . htmlspecialchars($img_url, ENT_QUOTES) . '\')"';
}

// --- Display-ready navigation supplied by the CMS view model ---
$site_display_name = $settings['site_name'] ?? 'SNAPSMACK';
$tilez_nav_items = $skin_view['navigation'] ?? [];

?>
<!-- Blog title / logo. TILEZ explicitly opts out of the shared sticky-header
     engine so the masthead scrolls away naturally with the page. -->
<header class="header section-inner" data-sticky-header="false">
<?php if ($alfred_header_logo !== ''): ?>
    <?php
    $logo_url = (preg_match('#^https?://#', $alfred_header_logo))
        ? $alfred_header_logo
        : BASE_URL . ltrim($alfred_header_logo, '/');
    ?>
    <a href="<?php echo BASE_URL; ?>" class="custom-logo-link blog-logo">
        <img src="<?php echo htmlspecialchars($logo_url); ?>"
             alt="<?php echo htmlspecialchars($site_display_name); ?>"
             class="custom-logo"
             <?php if ($alfred_retina_logo): ?>
             style="width: auto; max-width: 100%;"
             <?php endif; ?>>
    </a>
<?php else: ?>
    <h1 class="blog-title"><a href="<?php echo BASE_URL; ?>"><?php echo htmlspecialchars($site_display_name); ?></a></h1>
<?php endif; ?>
<?php $alfred_tagline = trim($settings['site_tagline'] ?? ''); if ($alfred_tagline !== '' && ($settings['show_tagline'] ?? '1') === '1'): ?>
    <p class="blog-description"><?php echo htmlspecialchars($alfred_tagline); ?></p>
<?php endif; ?>
</header><!-- /.header -->

<nav class="navigation" role="navigation">
    <div class="section-inner">

        <!-- Desktop nav -->
        <ul class="main-menu">
        <?php snapsmack_render_navigation_items($tilez_nav_items); ?>
        </ul>

        <div class="tilez-icon-nav" role="navigation" aria-label="Quick navigation">
            <a href="<?php echo BASE_URL; ?>" title="Home" aria-label="Home"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" fill="none" stroke="currentColor" stroke-width="1.8"/></svg></a>
            <a href="<?php echo BASE_URL; ?>about" title="About" aria-label="About"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><line x1="12" y1="11" x2="12" y2="16.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="7.8" r="1.05" fill="currentColor"/></svg></a>
            <a href="<?php echo BASE_URL; ?>blogroll.php" title="Blogroll" aria-label="Blogroll"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="6" r="1.5" fill="currentColor"/><circle cx="5" cy="12" r="1.5" fill="currentColor"/><circle cx="5" cy="18" r="1.5" fill="currentColor"/><path d="M9 6h11M9 12h11M9 18h11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></a>
            <details class="tilez-nav-search">
                <summary title="Search" aria-label="Search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m15.5 15.5 5 5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg></summary>
                <form class="tilez-nav-search-panel" method="get" action="<?php echo BASE_URL; ?>archive.php">
                    <label class="screen-reader-text" for="tilez-nav-search-input">Search photographs</label>
                    <input id="tilez-nav-search-input" type="search" name="q" placeholder="<?php echo htmlspecialchars($settings['search_placeholder'] ?? 'Search or #tag…'); ?>" autocomplete="off">
                    <button type="submit">GO</button>
                </form>
            </details>
        </div>

        <!-- Mobile toggle -->
        <button class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">
            <div class="bars" aria-hidden="true">
                <span class="bar"></span>
                <span class="bar"></span>
                <span class="bar"></span>
            </div>
        </button>

        <!-- Mobile drawer -->
        <div class="mobile-navigation">
            <ul class="mobile-menu">
            <?php snapsmack_render_navigation_items($tilez_nav_items); ?>
            </ul>
        </div>

    </div><!-- /.section-inner -->
</nav><!-- /.navigation -->

<!-- Full-viewport header image -->
<div class="header-image"<?php echo $header_image_style; ?>></div>

<?php // ===== SNAPSMACK EOF =====
