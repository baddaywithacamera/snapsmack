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

// ── Appearance controls (shared setting keys, per-skin values) ──────────────
// DISC  = the round pill behind the magnifier (.gsd-form background).
// GLASS = the magnifier icon itself (.gsd-toggle colour; the SVG uses
//         stroke="currentColor", so the toggle's colour IS the glass colour).
// Disc colour + opacity combine into one rgba; glass is a solid colour. Emitted
// as inline CSS custom properties so this ONE shared component themes the dock
// on every skin (no per-skin CSS). Left unset → the skin's own --bg-primary /
// --text-primary win (current look, zero regression).
$gsd_style_parts = [];
$gsd_disc_color  = trim((string)($settings['gsd_disc_color'] ?? ''));
if (preg_match('/^#?[0-9a-fA-F]{6}$/', $gsd_disc_color)) {
    $gsd_hex = ltrim($gsd_disc_color, '#');
    $gsd_r   = hexdec(substr($gsd_hex, 0, 2));
    $gsd_g   = hexdec(substr($gsd_hex, 2, 2));
    $gsd_b   = hexdec(substr($gsd_hex, 4, 2));
    $gsd_op  = $settings['gsd_disc_opacity'] ?? '';
    $gsd_op  = ($gsd_op === '') ? 100 : max(0, min(100, (int)$gsd_op));
    $gsd_style_parts[] = '--gsd-disc-bg:rgba(' . $gsd_r . ',' . $gsd_g . ',' . $gsd_b . ',' . round($gsd_op / 100, 3) . ')';
}
$gsd_glass_color = trim((string)($settings['gsd_glass_color'] ?? ''));
if (preg_match('/^#?[0-9a-fA-F]{6}$/', $gsd_glass_color)) {
    $gsd_style_parts[] = '--gsd-glass-color:' . (strpos($gsd_glass_color, '#') === 0 ? $gsd_glass_color : '#' . $gsd_glass_color);
}
$gsd_style_attr = $gsd_style_parts ? ' style="' . htmlspecialchars(implode(';', $gsd_style_parts)) . '"' : '';
?>
<div class="gram-search-dock" data-gram-search-dock<?php echo $gsd_style_attr; ?>>
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
