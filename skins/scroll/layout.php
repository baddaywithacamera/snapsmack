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
            <a class="scroll-horizontal-title" href="<?php echo BASE_URL; ?>">
                <?php
                $solo_masthead = trim((string)($settings['scroll_masthead_lines'] ?? ($settings['site_name'] ?? 'SnapSmack')));
                echo htmlspecialchars(str_replace('|', ' ', $solo_masthead));
                ?>
            </a>
            <nav class="scroll-sticky-nav scroll-solo-nav" aria-label="Site navigation">
                <div class="ss-grid-nav-links">
                    <a class="ss-grid-nav-link" href="<?php echo BASE_URL; ?>" title="Home">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                        <span class="ss-grid-nav-label">Home</span>
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
