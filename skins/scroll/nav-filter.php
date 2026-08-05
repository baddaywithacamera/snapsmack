<?php
/**
 * SNAPSMACK — SCROLL shared navigation filter popup.
 * Used by non-landing headers so the funnel behaves exactly like the landing
 * instead of navigating directly to the generic archive page.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$scroll_filter_cats = $pdo->query(
    "SELECT id, cat_name FROM snap_categories WHERE show_in_archive = 1 ORDER BY cat_name ASC"
)->fetchAll();
$scroll_filter_albums = $pdo->query(
    "SELECT id, album_name FROM snap_albums ORDER BY album_name ASC"
)->fetchAll();
$scroll_filter_collections = $pdo->query(
    "SELECT id, title FROM snap_collections ORDER BY title ASC"
)->fetchAll();
// snap_images.user_id (author attribution) is absent on legacy/unsynced installs;
// degrade the author filter instead of 500-ing the page.
$scroll_filter_authors = [];
try {
    if ($pdo->query(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'snap_images'
           AND COLUMN_NAME = 'user_id' LIMIT 1"
    )->fetchColumn()) {
        $scroll_filter_authors = $pdo->query(
            "SELECT u.id, u.username FROM snap_users u
             WHERE EXISTS (
                SELECT 1 FROM snap_images i2
                WHERE i2.user_id = u.id AND i2.img_status = 'published'
             )
             ORDER BY u.username ASC"
        )->fetchAll();
    }
} catch (Throwable $e) {
    $scroll_filter_authors = [];
}
?>
<div class="saf-wrap scroll-nav-filter">
    <button id="smack-archive-filter-btn" type="button" class="ss-grid-nav-link"
            aria-expanded="false" aria-controls="smack-archive-filter-panel" title="Filter">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18l-7 8v5l-4 2v-7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
        <span class="ss-grid-nav-label">Filter</span>
    </button>
    <div id="smack-archive-filter-panel" class="saf-panel" role="dialog" aria-label="Filter photographs">
        <input type="text" id="smack-archive-filter-search" class="saf-search"
               placeholder="SEARCH FILTERS…" autocomplete="off" spellcheck="false">
        <?php if ($scroll_filter_cats): ?>
        <div class="saf-group"><div class="saf-group-header">CATEGORIES</div>
            <?php foreach ($scroll_filter_cats as $c): ?>
            <label class="saf-item"><input type="checkbox" class="saf-checkbox" data-type="cat" value="<?php echo (int)$c['id']; ?>"><span class="saf-label"><?php echo htmlspecialchars(strtoupper($c['cat_name'])); ?></span></label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($scroll_filter_albums): ?>
        <div class="saf-group"><div class="saf-group-header">ALBUMS</div>
            <?php foreach ($scroll_filter_albums as $a): ?>
            <label class="saf-item"><input type="checkbox" class="saf-checkbox" data-type="alb" value="<?php echo (int)$a['id']; ?>"><span class="saf-label"><?php echo htmlspecialchars(strtoupper($a['album_name'])); ?></span></label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($scroll_filter_collections): ?>
        <div class="saf-group"><div class="saf-group-header">COLLECTIONS</div>
            <?php foreach ($scroll_filter_collections as $col): ?>
            <label class="saf-item"><input type="checkbox" class="saf-checkbox" data-type="col" value="<?php echo (int)$col['id']; ?>"><span class="saf-label"><?php echo htmlspecialchars(strtoupper($col['title'])); ?></span></label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (count($scroll_filter_authors) > 1): ?>
        <div class="saf-group"><div class="saf-group-header">PHOTOGRAPHER</div>
            <?php foreach ($scroll_filter_authors as $au): ?>
            <label class="saf-item"><input type="checkbox" class="saf-checkbox" data-type="usr" value="<?php echo (int)$au['id']; ?>"><span class="saf-label"><?php echo htmlspecialchars(strtoupper($au['username'])); ?></span></label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-archive-filter.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>
<?php // ===== SNAPSMACK EOF =====
