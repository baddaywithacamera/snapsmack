<?php
/**
 * SNAPSMACK - Slickr Skin Footer
 *
 * @author Sean McCormick
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

?>
<!-- Avatar lightbox (driven by the shared ss-engine-grid-lightbox.js; the avatar in
     skin-header.php carries data-sl-lightbox). Shared by every Slickr page. -->
<div id="sl-lightbox" class="sl-lightbox" hidden>
    <button type="button" class="sl-lightbox-close" aria-label="Close">&times;</button>
    <img class="sl-lightbox-img" src="" alt="Profile photo">
</div>
<?php
// ── Load required JS engines from the manifest (justified, calendar, lightbox,
//    etc.). This loop existed in every OTHER skin's footer but was missing here,
//    which is why the calendar [C] button — and the justified archive engine —
//    never loaded on slickr. meta.php only emits each engine's CSS; the matching
//    <script> is the skin footer's job. ─────────────────────────────────────────
$skin_manifest = load_skin_manifest(basename(__DIR__));
$requested     = $skin_manifest['require_scripts'] ?? [];
$skin_asset_v  = SNAPSMACK_VERSION_SHORT . (!empty($skin_manifest['version']) ? '-' . $skin_manifest['version'] : '');
if (!empty($requested)) {
    $inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
    if (isset($inventory['scripts'])) {
        foreach ($requested as $handle) {
            if (isset($inventory['scripts'][$handle])) {
                $script   = $inventory['scripts'][$handle];
                $_is_skin = strpos($script['path'], 'skins/') === 0;
                $_ver     = $_is_skin ? $skin_asset_v : SNAPSMACK_VERSION_SHORT;
                echo '<script src="' . BASE_URL . $script['path'] . '?v=' . $_ver . '"' . ($_is_skin ? ' defer' : '') . '></script>' . "\n";
            }
        }
    }
}

include dirname(__DIR__, 2) . '/core/footer.php';
// ===== SNAPSMACK EOF =====
