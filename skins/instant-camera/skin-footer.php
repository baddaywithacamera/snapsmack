<?php
/**
 * SNAPSMACK - The Grid Skin Footer
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
<!-- Post modal overlay (populated by tg-modal.js). Rendered in the SHARED
     footer so every Grid page that includes skin-footer.php (landing, archive,
     hashtag, skin-page, and the solo post view) has the container the modal
     script requires. Modal fragments skip skin-footer (layout.php modal-mode
     guard), so there is never a duplicate inside the fetched fragment. -->
<div id="tg-modal-overlay" class="tg-modal-overlay" hidden
     data-grid-url="<?php echo htmlspecialchars(BASE_URL); ?>"<?php echo !empty($tg_autoopen) ? ' data-autoopen="1"' : ''; ?>>
    <div class="tg-modal-backdrop"></div>
    <div id="tg-modal-frame" class="tg-modal-frame"></div>
</div>

<!-- Avatar lightbox (populated by tg-lightbox.js). Shared by every Grid page. -->
<div id="tg-lightbox" class="tg-lightbox" hidden>
    <button type="button" class="tg-lightbox-close" aria-label="Close">&times;</button>
    <img class="tg-lightbox-img" src="" alt="Profile photo">
</div>
<?php
// ── Load required JS engines from manifest ─────────────────────────────────
$skin_manifest = function_exists('load_skin_manifest')
    ? load_skin_manifest(basename(__DIR__))
    : include __DIR__ . '/manifest.php';
$requested     = $skin_manifest['require_scripts'] ?? [];

// Background engines: single modes are mutually exclusive (PARADE pattern) —
// Organized Mayhem loads by default; RACETRACK / RAINFALL replace it when
// selected; Static loads no engine. CYCLE loads ALL engines plus the crossfader
// so every background renders and rotates on a timer.
$_ic_bg_engine = $settings['ic_bg_mode'] ?? 'mayhem';
if ($_ic_bg_engine === 'cycle') {
    // Keep mayhem (already in require_scripts) and add the rest + the cycler.
    $requested[] = 'smack-racetrack';
    $requested[] = 'smack-rainfall';
    $requested[] = 'smack-bg-cycle';
} elseif ($_ic_bg_engine !== 'mayhem') {
    $requested = array_values(array_filter($requested, function ($h) { return $h !== 'smack-organized-mayhem'; }));
    if ($_ic_bg_engine === 'racetrack') $requested[] = 'smack-racetrack';
    if ($_ic_bg_engine === 'rainfall')  $requested[] = 'smack-rainfall';
}

// Skin asset cache-buster: core version + skin version (skin JS busts on skin bump).
$skin_asset_v = SNAPSMACK_VERSION_SHORT . (!empty($skin_manifest['version']) ? '-' . $skin_manifest['version'] : '');

if (!empty($requested)) {
    $inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
    if (isset($inventory['scripts'])) {
        foreach ($requested as $handle) {
            if (isset($inventory['scripts'][$handle])) {
                $script = $inventory['scripts'][$handle];
                // Skin-owned scripts bust on the skin version and load deferred.
                $_is_skin = strpos($script['path'], 'skins/') === 0;
                $_ver = $_is_skin ? $skin_asset_v : SNAPSMACK_VERSION_SHORT;
                echo '<script src="' . BASE_URL . $script['path'] . '?v=' . $_ver . '"' . ($_is_skin ? ' defer' : '') . '></script>' . "\n";
            }
        }
    }
}

// All Grid engines load through the manifest above (require_scripts →
// core/manifest-inventory.php). No direct <script> tags here.

// ── Floating search dock (bottom-left magnifier; self-gates on search_enabled) ─
include(dirname(__DIR__, 2) . '/core/gram-search-dock.php');

// ── Core footer (closes </body></html>) ────────────────────────────────────
include_once(dirname(__DIR__, 2) . '/core/footer.php');
// ===== SNAPSMACK EOF =====
