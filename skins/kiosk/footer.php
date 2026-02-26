<?php
/**
 * SNAPSMACK Skin Footer: New Horizon Dark
 * Version: 4.1 - Defensive JS Handshake
 * -------------------------------------------------------------------------
 * - ADDED: Loop to cross-reference manifest requirements with core inventory.
 * - FIXED: Injects skin-specific JS engines before calling core footer.
 * - ADDED: Null-check on variables to prevent errors on direct calls.
 * -------------------------------------------------------------------------
 */

// 1. GET THE REQUESTED SCRIPTS FROM THE MANIFEST
// We use a fallback empty array if layout.php didn't load them (defensive coding)
$requested = $skin_manifest['require_scripts'] ?? [];

// 2. CROSS-REFERENCE WITH CORE INVENTORY & INJECT
if (!empty($requested) && isset($inventory['scripts'])) {
    echo "\n\n";
    foreach ($requested as $handle) {
        if (isset($inventory['scripts'][$handle])) {
            $script = $inventory['scripts'][$handle];
            
            // Output script tag using the centralized path from inventory
            echo '<script src="' . BASE_URL . $script['path'] . '"></script>' . "\n";
        }
    }
}

// 3. CALL CORE FOOTER (Handles global UI script and closing tags)
include_once(dirname(__DIR__, 2) . '/core/footer.php');
?>