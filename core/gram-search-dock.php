<?php
/**
 * SNAPSMACK - Floating Search Dock (magnifier)
 *
 * A small magnifying-glass button pinned bottom-left that expands into a
 * floating search box. Desktop: expands on hover. Touch: tap to expand,
 * tap-away to close (handled by ss-engine-gram-search.js). Submits to ?q= which
 * index.php routes to the active skin's search.php.
 *
 * Gated on the existing `search_enabled` setting — if search is off, nothing
 * renders. Included once from each GRAM skin's skin-footer.php so it appears on
 * every page of the skin.
 *
 * Expects in scope: $settings (array). BASE_URL constant.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (($settings['search_enabled'] ?? '0') !== '1') return;

$gsd_ph = htmlspecialchars($settings['search_placeholder'] ?? 'Search or #tag…');
?>
<div class="gram-search-dock" data-gram-search-dock>
    <form class="gsd-form" method="GET" action="<?php echo htmlspecialchars(BASE_URL); ?>" role="search">
        <button type="button" class="gsd-toggle" aria-label="Search" aria-expanded="false">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
        </button>
        <input type="search" name="q" class="gsd-input" placeholder="<?php echo $gsd_ph; ?>"
               autocomplete="off" aria-label="Search photos or tags" tabindex="-1">
    </form>
</div>
<?php // ===== SNAPSMACK EOF =====
