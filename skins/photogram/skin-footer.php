<?php
/**
 * SNAPSMACK - Photogram Skin Footer
 * Alpha v0.7.3
 *
 * Renders the fixed bottom navigation bar, loads required JS engines,
 * and includes core footer (closes </body></html>).
 *
 * $pg_active_tab — expected to be set by the calling template.
 *   Values: 'home' | 'discover' | 'profile'
 *   Defaults to 'home' if not set.
 *
 * $pg_show_discover — whether the discover tab is shown.
 *   Reads from settings; defaults to true.
 */

$active_tab    = $pg_active_tab ?? 'home';
$show_discover = ($settings['pg_show_discover'] ?? '1') === '1';
$show_search   = ($settings['search_enabled']    ?? '0') === '1';

// Base URL for nav links
$home_url     = BASE_URL;
$discover_url = BASE_URL . '?pg=discover';
$search_url   = BASE_URL . '?pg=search';

// About tab: prefer page with slug='about', fall back to first active page by menu_order
$_pg_about_page = null;
if (isset($pdo)) {
    $_pg_about_stmt = $pdo->prepare(
        "SELECT slug FROM snap_pages WHERE is_active = 1 AND slug = 'about' LIMIT 1"
    );
    $_pg_about_stmt->execute();
    $_pg_about_page = $_pg_about_stmt->fetchColumn() ?: null;
    if (!$_pg_about_page) {
        $_pg_fallback   = $pdo->query(
            "SELECT slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC LIMIT 1"
        );
        $_pg_about_page = ($_pg_fallback ? $_pg_fallback->fetchColumn() : null) ?: null;
    }
}
$show_about  = !empty($_pg_about_page);
$profile_url = $show_about ? BASE_URL . 'page.php?slug=' . rawurlencode($_pg_about_page) : BASE_URL;
?>

<!-- ── Bottom Navigation Bar ─────────────────────────────────────────── -->
<nav id="pg-nav" role="navigation" aria-label="Main navigation">

    <!-- Home -->
    <a href="<?php echo $home_url; ?>" class="pg-nav-tab<?php echo $active_tab === 'home' ? ' active' : ''; ?>" aria-label="Home">
        <!-- Outline (inactive) -->
        <svg class="pg-icon-outline" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/>
            <polyline points="9 21 9 12 15 12 15 21"/>
        </svg>
        <!-- Filled (active) -->
        <svg class="pg-icon-filled" width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
            <path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/>
            <rect x="9" y="12" width="6" height="9" fill="var(--pg-bg)"/>
        </svg>
    </a>

    <?php if ($show_discover): ?>
    <!-- Discover -->
    <a href="<?php echo $discover_url; ?>" class="pg-nav-tab<?php echo $active_tab === 'discover' ? ' active' : ''; ?>" aria-label="Discover">
        <!-- Outline -->
        <svg class="pg-icon-outline" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <!-- Filled -->
        <svg class="pg-icon-filled" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
    </a>
    <?php endif; ?>

    <?php if ($show_search): ?>
    <!-- Search -->
    <a href="<?php echo $search_url; ?>" class="pg-nav-tab<?php echo $active_tab === 'search' ? ' active' : ''; ?>" aria-label="Search">
        <!-- Outline -->
        <svg class="pg-icon-outline" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <!-- Filled (bolder stroke = active) -->
        <svg class="pg-icon-filled" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
    </a>
    <?php endif; ?>

    <!-- About / Profile — hidden when no page is configured -->
    <?php if ($show_about): ?>
    <a href="<?php echo $profile_url; ?>" class="pg-nav-tab<?php echo $active_tab === 'about' ? ' active' : ''; ?>" aria-label="About">
        <!-- Outline -->
        <svg class="pg-icon-outline" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
        </svg>
        <!-- Filled -->
        <svg class="pg-icon-filled" width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
        </svg>
    </a>
    <?php endif; ?>

</nav>

<?php
// ── Load required JS engines from manifest ─────────────────────────────────
$skin_manifest = include __DIR__ . '/manifest.php';
$requested     = $skin_manifest['require_scripts'] ?? [];
echo '<script>console.log("[FTR] skin-footer.php reached, requested scripts: ' . implode(', ', $requested) . '")</script>' . "\n";

if (!empty($requested)) {
    $inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
    if (isset($inventory['scripts'])) {
        foreach ($requested as $handle) {
            if (isset($inventory['scripts'][$handle])) {
                $script = $inventory['scripts'][$handle];
                if (!empty($script['css'])) {
                    echo '<link rel="stylesheet" href="' . BASE_URL . $script['css'] . '?v=' . time() . '">' . "\n";
                }
                echo '<script src="' . BASE_URL . $script['path'] . '?v=' . time() . '"></script>' . "\n";
            }
        }
    }
}

// ── Core footer (closes </body></html>) ────────────────────────────────────
include_once(dirname(__DIR__, 2) . '/core/footer.php');
