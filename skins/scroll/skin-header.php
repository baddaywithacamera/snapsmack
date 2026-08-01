<?php
/**
 * SNAPSMACK — SCROLL compact page header (static pages, blogroll, photo pages).
 *
 * Renders the SAME five-icon nav as the landing masthead so every page shares
 * one header — Home / About / Blogroll / Search / Filter, plus the inline social
 * dock. It is emitted inline here (not via the shared grid-sticky-nav icon
 * resolver, which only knows a handful of icon names) so the icons stay
 * identical to the landing WITHOUT a core change. The nav carries
 * `scroll-sticky-nav` so it inherits the exact chip styling, and
 * `ss-grid-nav-always-visible` so it is a persistent top bar away from the
 * landing masthead.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$photographer_name = trim((string)($settings['photographer_name'] ?? ($settings['site_name'] ?? 'SnapSmack')));
// NOTE: the sticky-bar logo (scroll__nav_logo) is deliberately NOT used here —
// it is for the landing's sticky menu only, never static pages. This header
// shows the photographer name.
?>
<nav class="scroll-sticky-nav ss-grid-sticky-nav ss-grid-nav-always-visible"
     aria-label="Site navigation">
    <div class="ss-grid-nav-identity">
        <a class="ss-grid-nav-name" href="<?php echo BASE_URL; ?>"><?php echo htmlspecialchars($photographer_name); ?></a>
    </div>
    <div class="ss-grid-nav-links">
        <a class="ss-grid-nav-link" href="<?php echo BASE_URL; ?>" title="Home">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
            <span class="ss-grid-nav-label">Home</span>
        </a>
        <a class="ss-grid-nav-link" href="<?php echo BASE_URL; ?>about" title="About">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><line x1="12" y1="11" x2="12" y2="16.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="7.8" r="1.05" fill="currentColor"/></svg>
            <span class="ss-grid-nav-label">About</span>
        </a>
        <a class="ss-grid-nav-link" href="<?php echo BASE_URL; ?>blogroll.php" title="Blogroll">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="6" r="1.5" fill="currentColor"/><circle cx="5" cy="12" r="1.5" fill="currentColor"/><circle cx="5" cy="18" r="1.5" fill="currentColor"/><path d="M9 6h11M9 12h11M9 18h11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span class="ss-grid-nav-label">Blogroll</span>
        </a>
        <details class="scroll-nav-search">
            <summary class="ss-grid-nav-link" title="Search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m15.5 15.5 5 5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                <span class="ss-grid-nav-label">Search</span>
            </summary>
            <form class="scroll-nav-search-panel" method="get" action="<?php echo BASE_URL; ?>archive.php">
                <label class="ss-grid-nav-label" for="scroll-nav-search-input">Search photographs</label>
                <input id="scroll-nav-search-input"
                       type="search"
                       name="q"
                       placeholder="<?php echo htmlspecialchars($settings['search_placeholder'] ?? 'Search or #tag…'); ?>"
                       autocomplete="off">
                <button type="submit">GO</button>
            </form>
        </details>
        <a class="ss-grid-nav-link" href="<?php echo BASE_URL; ?>archive.php#smack-archive-filter-btn" title="Filter">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18l-7 8v5l-4 2v-7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
            <span class="ss-grid-nav-label">Filter</span>
        </a>
    </div>
    <div class="ss-grid-nav-actions">
        <?php
        $social_dock_inline = true;
        include dirname(__DIR__, 2) . '/core/social-dock.php';
        unset($social_dock_inline);
        ?>
    </div>
</nav>
<?php // ===== SNAPSMACK EOF =====
