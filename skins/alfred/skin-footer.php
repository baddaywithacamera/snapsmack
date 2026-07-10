<?php
/**
 * SNAPSMACK - Skin footer for the Alfred skin
 * v1.0.0
 *
 * Loads manifest-required scripts, then includes the core footer (the
 * configured slot bar) and the shared public engine loader to close the doc.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// Every skin's visible footer IS the configured slot bar rendered by
// core/footer.php (COPYRIGHT · EMAIL · THEME · POWERED BY · PRIVACY · RSS,
// each ON / CUSTOM / OFF per Global Vibe). No skin renders its own hardcoded
// footer — a user who wants a different look uses SMACK YOUR CSS UP. ALFRED's
// old hardcoded .credits bar duplicated two slots and hid the rest, so the
// footer configuration never showed; removed.

// Load manifest-required scripts
$skin_manifest = include __DIR__ . '/manifest.php';
$requested     = $skin_manifest['require_scripts'] ?? [];

if (!empty($requested)) {
    $inventory = include dirname(__DIR__, 2) . '/core/manifest-inventory.php';
    if (isset($inventory['scripts'])) {
        foreach ($requested as $handle) {
            if (isset($inventory['scripts'][$handle])) {
                $script = $inventory['scripts'][$handle];
                echo '<script src="' . BASE_URL . $script['path'] . '?v=' . SNAPSMACK_VERSION_SHORT . '"></script>' . "\n";
            }
        }
    }
}

include_once dirname(__DIR__, 2) . '/core/footer.php';

// Global public engines: consent banner, comms/HUD, the Thomas the Bear easter
// egg (REQUIRED in every skin fork), the Social Profile Dock (Alfred's chosen
// social surface), sticky header, and the SCROLL TIME tracker. Mainline
// controllers include this on every public page; Alfred renders its own
// document via preload.php, so it must pull the shared engine loader itself.
include dirname(__DIR__, 2) . '/core/footer-scripts.php';
?>
<?php // ===== SNAPSMACK EOF =====
