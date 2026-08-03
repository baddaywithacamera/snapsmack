<?php
/**
 * SNAPSMACK — SCROLL solo image layout.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once dirname(__DIR__, 2) . '/core/layout-logic.php';
?>
<div id="scroll-stage" class="scroll-solo-stage">
    <header class="scroll-solo-header">
        <div class="scroll-solo-header-inside">
            <nav class="scroll-sticky-nav ss-grid-sticky-nav scroll-solo-nav" aria-label="Site navigation">
                <?php
                // Same identity + full nav + social dock as the shared header, so the
                // solo page matches the landing's sticky bar. The blog name uses
                // .ss-grid-nav-name — the SAME selector the Masthead Font control already
                // compiles to — so it picks up the masthead font with no recompile.
                $solo_masthead = trim((string)($settings['scroll_masthead_lines'] ?? ($settings['site_name'] ?? 'SnapSmack')));
                ?>
                <div class="ss-grid-nav-identity">
                    <a class="ss-grid-nav-name" href="<?php echo BASE_URL; ?>"><?php echo htmlspecialchars(str_replace('|', ' ', $solo_masthead)); ?></a>
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
                            <label class="ss-grid-nav-label" for="scroll-solo-search-input">Search photographs</label>
                            <input id="scroll-solo-search-input" type="search" name="q"
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
        </div>
    </header>
    <main class="scroll-solo-photobox">
        <div class="scroll-photo-wrap">
            <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
            <img src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>"
                 alt="<?php echo htmlspecialchars($img['img_alt'] ?? $img['img_title']); ?>"
                 class="scroll-image post-image"
                 id="main-image">
            <?php echo $download_button; ?>
        </div>
    </main>
    <div id="infobox" class="scroll-infobox">
        <?php include dirname(__DIR__, 2) . '/core/navigation-bar.php'; ?>
    </div>
    <div id="footer">
        <div id="pane-info" class="footer-pane">
            <h1 class="scroll-solo-title"><?php echo htmlspecialchars($img['img_title']); ?></h1>
            <div class="description"><?php echo $snapsmack->parseContent($img['img_description'] ?? ''); ?></div>
        </div>
        <div id="pane-comments" class="footer-pane">
            <?php include dirname(__DIR__, 2) . '/core/community-component.php'; ?>
        </div>
    </div>
    <?php include dirname(__DIR__, 2) . '/core/community-dock.php'; ?>
    <?php include __DIR__ . '/skin-footer.php'; ?>
</div>
<?php // ===== SNAPSMACK EOF =====
