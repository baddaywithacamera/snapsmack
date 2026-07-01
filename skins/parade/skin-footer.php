<?php
/**
 * SNAPSMACK - PARADE Skin Footer
 * Alpha v0.7.9
 *
 * Renders the minimal site footer, loads required JS engines from the
 * manifest, and includes core/footer.php to close </body></html>.
 *
 * $settings is available from the calling template.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


?>
<!-- Post modal overlay (populated by pa-modal.js). Rendered in the SHARED
     footer so every Grid page that includes skin-footer.php (landing, archive,
     hashtag, skin-page, and the solo post view) has the container the modal
     script requires. Modal fragments skip skin-footer (layout.php modal-mode
     guard), so there is never a duplicate inside the fetched fragment. -->
<div id="pa-modal-overlay" class="pa-modal-overlay" hidden
     data-grid-url="<?php echo htmlspecialchars(BASE_URL); ?>"<?php echo !empty($pa_autoopen) ? ' data-autoopen="1"' : ''; ?>>
    <div class="pa-modal-backdrop"></div>
    <div id="pa-modal-frame" class="pa-modal-frame"></div>
</div>

<!-- Avatar lightbox (populated by pa-lightbox.js). Shared by every Grid page. -->
<div id="pa-lightbox" class="pa-lightbox" hidden>
    <button type="button" class="pa-lightbox-close" aria-label="Close">&times;</button>
    <img class="pa-lightbox-img" src="" alt="Profile photo">
</div>
<?php
// ── Load required JS engines from manifest ─────────────────────────────────
$skin_manifest = include __DIR__ . '/manifest.php';
$requested     = $skin_manifest['require_scripts'] ?? [];

// Background mode is mutually exclusive (spec): in flag mode, load the Flag Wave
// engine INSTEAD of the fireworks engine — the other is never loaded.
if (($settings['pa_bg_mode'] ?? 'fireworks') === 'flag') {
    $requested = array_values(array_filter($requested, function ($h) { return $h !== 'smack-parade-fireworks'; }));
    $requested[] = 'smack-flag-wave';
}

// Skin asset cache-buster: core version + skin version, mirroring meta.php's
// $_skin_css_version. Bumping the skin version now busts BOTH the CSS and this
// skin's JS, so a normal reload pulls everything fresh (no hard-refresh needed).
$skin_asset_v = SNAPSMACK_VERSION_SHORT . (!empty($skin_manifest['version']) ? '-' . $skin_manifest['version'] : '');

if (!empty($requested)) {
    $inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
    if (isset($inventory['scripts'])) {
        foreach ($requested as $handle) {
            if (isset($inventory['scripts'][$handle])) {
                $script = $inventory['scripts'][$handle];
                // Skin-owned scripts bust on the skin version and load deferred;
                // core engines stay on the core version.
                $_is_skin = strpos($script['path'], 'skins/') === 0;
                $_ver = $_is_skin ? $skin_asset_v : SNAPSMACK_VERSION_SHORT;
                echo '<script src="' . BASE_URL . $script['path'] . '?v=' . $_ver . '"' . ($_is_skin ? ' defer' : '') . '></script>' . "\n";
            }
        }
    }
}

// All PARADE engines load through the manifest above (require_scripts →
// core/manifest-inventory.php). No direct <script> tags here — JS stays under
// the CMS library / integrity posture.

// ── Floating search dock (bottom-left magnifier; self-gates on search_enabled) ─
include(dirname(__DIR__, 2) . '/core/gram-search-dock.php');

// ── Core footer (closes </body></html>) ────────────────────────────────────
include_once(dirname(__DIR__, 2) . '/core/footer.php');
// ===== SNAPSMACK EOF =====
